<?php

namespace Tests\Feature;

use App\Models\Interest;
use App\Models\Photo;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_and_read_their_own_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $zoneId = DB::table('zones')->insertGetId([
            'name' => 'Campus UTT',
            'type' => 'campus',
            'university_id' => null,
            'city' => 'Torreón',
            'center_lat' => 25.5,
            'center_lng' => -103.4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($user, 'sanctum');

        $updateResponse = $this->patchJson('/api/profile', [
            'name' => 'Ada Updated',
            'career' => 'Ingeniería en Tecnologías de la Información',
            'semester' => 6,
            'birthdate' => '2002-04-15',
            'bio' => 'Me gusta la fotografía y la música.',
            'zone_id' => $zoneId,
            'nearby_enabled' => true,
            'user_id' => $otherUser->id,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.user.name', 'Ada Updated')
            ->assertJsonPath('data.profile.career', 'Ingeniería en Tecnologías de la Información')
            ->assertJsonPath('data.profile.semester', 6)
            ->assertJsonPath('data.profile.bio', 'Me gusta la fotografía y la música.')
            ->assertJsonPath('data.profile.birthdate', '2002-04-15')
            ->assertJsonPath('data.profile.zone_id', $zoneId)
            ->assertJsonPath('data.profile.nearby_enabled', true);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'career' => 'Ingeniería en Tecnologías de la Información',
            'semester' => 6,
            'bio' => 'Me gusta la fotografía y la música.',
            'zone_id' => $zoneId,
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
            'career' => 'Medicina',
            'semester' => 2,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.profile.bio', 'Perfil existente.')
            ->assertJsonPath('data.profile.career', 'Medicina')
            ->assertJsonPath('data.profile.semester', 2);
    }

    public function test_profile_endpoint_requires_sanctum_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_interest_catalog_returns_available_interests(): void
    {
        $user = User::factory()->create();
        $interest = Interest::factory()->create(['name' => 'Astronomía', 'category' => 'academico']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/interests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $interest->id)
            ->assertJsonPath('data.0.name', 'Astronomía')
            ->assertJsonPath('data.0.category', 'academico');
    }

    public function test_profile_rejects_a_future_birthdate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile', ['birthdate' => '2999-01-01'])
            ->assertInvalid(['birthdate']);

        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
    }

    public function test_unverified_user_cannot_access_profile_routes(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertForbidden();
    }

    public function test_user_can_replace_their_interest_selection(): void
    {
        $user = User::factory()->create();
        $firstInterest = Interest::factory()->create(['name' => 'Fotografía', 'category' => 'cultura']);
        $secondInterest = Interest::factory()->create(['name' => 'Música', 'category' => 'entretenimiento']);
        $thirdInterest = Interest::factory()->create(['name' => 'Fútbol', 'category' => 'deportes']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile/interests', [
                'interest_ids' => [$firstInterest->id, $secondInterest->id, $thirdInterest->id],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data.profile.interests');

        $this->assertDatabaseHas('interest_profile', [
            'profile_id' => $user->profile->id,
            'interest_id' => $firstInterest->id,
        ]);
        $this->assertDatabaseHas('interest_profile', [
            'profile_id' => $user->profile->id,
            'interest_id' => $secondInterest->id,
        ]);
        $this->assertDatabaseHas('interest_profile', [
            'profile_id' => $user->profile->id,
            'interest_id' => $thirdInterest->id,
        ]);
    }

    public function test_interest_selection_rejects_fewer_than_three_without_changing_existing_selection(): void
    {
        $user = User::factory()->create();
        $interest = Interest::factory()->create();
        $secondInterest = Interest::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        $profile->interests()->attach($interest);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile/interests', [
                'interest_ids' => [$interest->id, $secondInterest->id],
            ])
            ->assertInvalid(['interest_ids']);

        $this->assertDatabaseHas('interest_profile', [
            'profile_id' => $profile->id,
            'interest_id' => $interest->id,
        ]);
        $this->assertDatabaseMissing('interest_profile', [
            'profile_id' => $profile->id,
            'interest_id' => $secondInterest->id,
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
            ->assertJsonPath('data.profile.photos.0.position', 1)
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

    public function test_user_cannot_upload_a_sixth_profile_photo(): void
    {
        Storage::fake('s3');
        config(['filesystems.profile_photos_disk' => 's3']);
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);

        foreach (range(1, 5) as $position) {
            Photo::factory()->create([
                'profile_id' => $profile->id,
                'position' => $position,
            ]);
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/photos', [
                'photo' => UploadedFile::fake()->createWithContent('sixth.png', $png),
            ])
            ->assertInvalid(['photo']);

        $this->assertDatabaseCount('photos', 5);
        Storage::disk('s3')->assertDirectoryEmpty('profiles');
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

    public function test_profile_bio_cannot_exceed_300_characters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile', ['bio' => str_repeat('a', 301)])
            ->assertInvalid(['bio']);

        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
    }
}
