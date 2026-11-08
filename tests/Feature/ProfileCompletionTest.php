<?php

namespace Tests\Feature;

use App\Models\Interest;
use App\Models\Profile;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Perfil obligatorio por pasos (T-49): qué falta, cuándo queda completo y qué bloquea mientras no.
 */
class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['filesystems.profile_photos_disk' => 's3']);
        $this->utt = University::factory()->create(['short_name' => 'UTT']);
    }

    public function test_a_new_student_has_an_incomplete_profile(): void
    {
        $student = User::factory()->student($this->utt)->create();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.profile_completed', false);
    }

    public function test_the_profile_lists_the_missing_steps_in_order(): void
    {
        $student = User::factory()->student($this->utt)->create();

        $this->actingAs($student, 'sanctum')
            ->patchJson('/api/profile', ['career' => 'Ingeniería en Software'])
            ->assertOk()
            ->assertJsonPath('data.profile.completion.completed', false)
            ->assertJsonPath('data.profile.completion.missing', ['basics', 'gender', 'studies', 'interests', 'photos', 'bio']);
    }

    public function test_completing_every_step_marks_the_profile_as_completed(): void
    {
        $student = User::factory()->student($this->utt)->create(['name' => 'Ana López']);
        $interests = Interest::factory()->count(3)->create();
        $api = $this->actingAs($student, 'sanctum');

        $api->patchJson('/api/profile', ['name' => 'Ana López', 'birthdate' => '2005-03-14'])
            ->assertJsonPath('data.profile.completion.missing.0', 'gender');
        $api->patchJson('/api/profile', ['gender' => 'mujer'])
            ->assertJsonPath('data.profile.gender', 'mujer');
        $api->patchJson('/api/profile', ['career' => 'Ingeniería en Software', 'semester' => 5]);
        $api->putJson('/api/profile/interests', ['interest_ids' => $interests->pluck('id')->all()]);
        $api->postJson('/api/profile/photos', ['photo' => UploadedFile::fake()->createWithContent('a.png', $this->png())])
            ->assertJsonPath('data.profile.completion.missing', ['bio']);

        $api->patchJson('/api/profile', ['bio' => 'Me gusta el fútbol y el café.'])
            ->assertOk()
            ->assertJsonPath('data.profile.completion.completed', true)
            ->assertJsonPath('data.profile.completion.missing', []);

        $this->assertNotNull($student->profile()->first()->completed_at);
        $api->getJson('/api/user')->assertJsonPath('data.profile_completed', true);
    }

    public function test_deleting_the_only_photo_makes_the_profile_incomplete_again(): void
    {
        $student = $this->completedStudent();
        $photo = $student->profile->photos()->create(['path' => 'profiles/x.png', 'position' => 1]);
        Storage::disk('s3')->put('profiles/x.png', 'x');

        $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/profile/photos/{$photo->id}")
            ->assertOk()
            ->assertJsonPath('data.profile.completion.completed', false)
            ->assertJsonPath('data.profile.completion.missing', ['photos']);

        $this->assertNull($student->profile()->first()->completed_at);
    }

    public function test_students_must_be_at_least_17_years_old(): void
    {
        $student = User::factory()->student($this->utt)->create();

        $this->actingAs($student, 'sanctum')
            ->patchJson('/api/profile', ['birthdate' => now()->subYears(17)->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthdate' => 'Debes tener al menos 17 años para usar Kampus.']);

        $this->actingAs($student, 'sanctum')
            ->patchJson('/api/profile', ['birthdate' => now()->subYears(17)->toDateString()])
            ->assertOk();
    }

    public function test_gender_must_be_one_of_the_options(): void
    {
        $student = User::factory()->student($this->utt)->create();

        $this->actingAs($student, 'sanctum')
            ->patchJson('/api/profile', ['gender' => 'otro'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gender' => 'Elige una opción de la lista.']);

        foreach (['mujer', 'hombre', 'no_binario', 'prefiero_no_decir'] as $gender) {
            $this->actingAs($student, 'sanctum')
                ->patchJson('/api/profile', ['gender' => $gender])
                ->assertOk();
        }
    }

    public function test_the_name_cannot_be_left_empty(): void
    {
        $student = User::factory()->student($this->utt)->create();

        $this->actingAs($student, 'sanctum')
            ->patchJson('/api/profile', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_a_student_with_an_incomplete_profile_cannot_discover_or_swipe(): void
    {
        $student = User::factory()->student($this->utt)->create();
        Profile::factory()->incomplete()->for($student)->create();
        $other = $this->completedStudent();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/discover')
            ->assertForbidden()
            ->assertJson(['code' => 'profile_incomplete']);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/swipes', ['user_id' => $other->id, 'liked' => true])
            ->assertForbidden()
            ->assertJson(['code' => 'profile_incomplete']);
    }

    public function test_incomplete_profiles_do_not_appear_in_discovery(): void
    {
        $viewer = $this->completedStudent();
        $complete = $this->completedStudent();
        $incomplete = User::factory()->student($this->utt)->create();
        Profile::factory()->incomplete()->for($incomplete)->create();

        $ids = collect($this->actingAs($viewer, 'sanctum')->getJson('/api/discover')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertContains($complete->id, $ids);
        $this->assertNotContains($incomplete->id, $ids);
    }

    public function test_staff_never_has_a_pending_profile(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/user')
            ->assertJsonPath('data.profile_completed', true);
    }

    private function completedStudent(): User
    {
        $student = User::factory()->student($this->utt)->create();
        $profile = Profile::factory()->for($student)->create();
        $profile->interests()->attach(Interest::factory()->count(Profile::MinimumInterests)->create());

        return $student->fresh();
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
