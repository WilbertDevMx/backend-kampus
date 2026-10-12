<?php

namespace Tests\Feature;

use App\Models\Interest;
use App\Models\Photo;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_and_read_their_own_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $updateResponse = $this->patchJson('/api/profile', [
            'name' => 'Ada Updated',
            'bio' => 'Me gusta la fotografía y la música.',
            'birth_date' => '2002-04-15',
            'gender' => 'woman',
            'user_id' => $otherUser->id,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.user.name', 'Ada Updated')
            ->assertJsonPath('data.profile.bio', 'Me gusta la fotografía y la música.')
            ->assertJsonPath('data.profile.birth_date', '2002-04-15')
            ->assertJsonPath('data.profile.gender', 'woman');
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'bio' => 'Me gusta la fotografía y la música.',
            'gender' => 'woman',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Ada Updated',
        ]);
        $this->assertDatabaseMissing('profiles', ['user_id' => $otherUser->id]);
    }

    public function test_profile_read_returns_the_authenticated_users_data(): void
    {
        $user = User::factory()->create();
        Profile::factory()->create([
            'user_id' => $user->id,
            'bio' => 'Perfil existente.',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.profile.bio', 'Perfil existente.');
    }

    public function test_profile_endpoint_requires_sanctum_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_interest_catalog_returns_available_interests(): void
    {
        $user = User::factory()->create();
        $interest = Interest::factory()->create(['name' => 'Astronomía']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/interests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $interest->id)
            ->assertJsonPath('data.0.name', 'Astronomía');
    }

    public function test_profile_rejects_a_future_birth_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile', ['birth_date' => '2999-01-01'])
            ->assertInvalid(['birth_date']);

        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
    }

    public function test_user_can_replace_their_interest_selection(): void
    {
        $user = User::factory()->create();
        $firstInterest = Interest::factory()->create(['name' => 'Fotografía']);
        $secondInterest = Interest::factory()->create(['name' => 'Música']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile/interests', [
                'interest_ids' => [$firstInterest->id, $secondInterest->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.profile.interests');

        $this->assertDatabaseHas('interest_profile', [
            'profile_id' => $user->profile->id,
            'interest_id' => $firstInterest->id,
        ]);
        $this->assertDatabaseHas('interest_profile', [
            'profile_id' => $user->profile->id,
            'interest_id' => $secondInterest->id,
        ]);
    }

    public function test_empty_interest_selection_removes_all_selected_interests(): void
    {
        $user = User::factory()->create();
        $interest = Interest::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        $profile->interests()->attach($interest);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile/interests', ['interest_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.profile.interests');

        $this->assertDatabaseMissing('interest_profile', [
            'profile_id' => $profile->id,
            'interest_id' => $interest->id,
        ]);
    }

    public function test_interest_selection_rejects_unknown_ids_without_creating_a_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile/interests', ['interest_ids' => ['invalid']])
            ->assertInvalid(['interest_ids.0']);

        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
    }

    public function test_user_can_upload_a_photo_to_private_storage(): void
    {
        Storage::fake('s3');
        config(['filesystems.profile_photos_disk' => 's3']);
        $user = User::factory()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/photos', [
                'photo' => UploadedFile::fake()->createWithContent('avatar.png', $png),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.profile.photos.0.mime_type', 'image/png')
            ->assertJsonPath('data.profile.photos.0.temporary_url', fn (string $url): bool => $url !== '');

        $photo = Photo::query()->firstOrFail();
        Storage::disk('s3')->assertExists($photo->path);
        $this->assertStringContainsString(
            'expiration=',
            $response->json('data.profile.photos.0.temporary_url'),
        );
        $this->assertDatabaseHas('photos', [
            'profile_id' => $user->profile->id,
            'path' => $photo->path,
        ]);
    }

    public function test_user_cannot_delete_another_users_photo(): void
    {
        Storage::fake('s3');
        config(['filesystems.profile_photos_disk' => 's3']);
        $owner = User::factory()->create();
        $ownerProfile = Profile::factory()->create(['user_id' => $owner->id]);
        $photo = Photo::factory()->create(['profile_id' => $ownerProfile->id]);
        Storage::disk('s3')->put($photo->path, 'private image', ['visibility' => 'private']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->deleteJson('/api/profile/photos/'.$photo->id)
            ->assertNotFound();

        Storage::disk('s3')->assertExists($photo->path);
        $this->assertModelExists($photo);
    }

    public function test_user_can_delete_their_own_photo(): void
    {
        Storage::fake('s3');
        config(['filesystems.profile_photos_disk' => 's3']);
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        $photo = Photo::factory()->create(['profile_id' => $profile->id]);
        Storage::disk('s3')->put($photo->path, 'private image', ['visibility' => 'private']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/profile/photos/'.$photo->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.profile.photos');

        Storage::disk('s3')->assertMissing($photo->path);
        $this->assertModelMissing($photo);
    }

    public function test_user_cannot_upload_a_non_image_file(): void
    {
        Storage::fake('s3');
        config(['filesystems.profile_photos_disk' => 's3']);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/photos', [
                'photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertInvalid(['photo']);

        $this->assertDatabaseCount('photos', 0);
        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
    }
}
