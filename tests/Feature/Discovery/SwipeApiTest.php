<?php

namespace Tests\Feature\Discovery;

use App\Models\Swipe;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SwipeApiTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create();
    }

    public function test_a_student_can_like_or_pass_a_profile(): void
    {
        $swiper = $this->studentWithProfile($this->utt);
        $liked = $this->studentWithProfile($this->utt);
        $passed = $this->studentWithProfile($this->utt);
        Sanctum::actingAs($swiper);

        $this->postJson('/api/swipes', ['user_id' => $liked->id, 'liked' => true])
            ->assertCreated()
            ->assertExactJson(['data' => ['user_id' => $liked->id, 'liked' => true, 'match' => null]]);
        $this->postJson('/api/swipes', ['user_id' => $passed->id, 'liked' => false])
            ->assertCreated()
            ->assertJsonPath('data.liked', false);

        $this->assertDatabaseHas('swipes', ['swiper_id' => $swiper->id, 'swiped_id' => $liked->id, 'liked' => true]);
        $this->assertDatabaseHas('swipes', ['swiper_id' => $swiper->id, 'swiped_id' => $passed->id, 'liked' => false]);
    }

    public function test_a_profile_cannot_be_evaluated_twice(): void
    {
        $swiper = $this->studentWithProfile($this->utt);
        $target = $this->studentWithProfile($this->utt);
        Swipe::factory()->passed()->create(['swiper_id' => $swiper->id, 'swiped_id' => $target->id]);
        Sanctum::actingAs($swiper);

        $this->postJson('/api/swipes', ['user_id' => $target->id, 'liked' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ya evaluaste este perfil.');

        $this->assertDatabaseCount('swipes', 1);
        $this->assertDatabaseHas('swipes', ['swiper_id' => $swiper->id, 'liked' => false]);
    }

    public function test_unavailable_profiles_cannot_be_evaluated(): void
    {
        $swiper = $this->studentWithProfile($this->utt);
        $moderator = User::factory()->moderator($this->utt)->create();
        $admin = User::factory()->admin()->create();
        $blocked = $this->studentWithProfile($this->utt);
        $blocker = $this->studentWithProfile($this->utt);
        $suspended = $this->studentWithProfile($this->utt);
        $suspended->forceFill(['suspended_at' => now()])->save();
        $withoutProfile = User::factory()->student($this->utt)->create();
        DB::table('blocks')->insert([
            ['blocker_id' => $swiper->id, 'blocked_id' => $blocked->id],
            ['blocker_id' => $blocker->id, 'blocked_id' => $swiper->id],
        ]);
        Sanctum::actingAs($swiper);

        $unavailable = [$swiper, $moderator, $admin, $blocked, $blocker, $suspended, $withoutProfile];

        foreach ($unavailable as $target) {
            $this->postJson('/api/swipes', ['user_id' => $target->id, 'liked' => true])
                ->assertUnprocessable()
                ->assertInvalid(['user_id' => 'Este perfil no está disponible.']);
        }
        $this->postJson('/api/swipes', ['user_id' => 999999, 'liked' => true])
            ->assertInvalid(['user_id' => 'Este perfil no está disponible.']);

        $this->assertDatabaseCount('swipes', 0);
    }

    public function test_the_swipe_payload_is_validated(): void
    {
        $swiper = $this->studentWithProfile($this->utt);
        $target = $this->studentWithProfile($this->utt);
        Sanctum::actingAs($swiper);

        $this->postJson('/api/swipes', ['user_id' => $target->id])->assertInvalid(['liked']);
        $this->postJson('/api/swipes', ['user_id' => $target->id, 'liked' => 'maybe'])->assertInvalid(['liked']);
        $this->postJson('/api/swipes', ['liked' => true])->assertInvalid(['user_id']);

        $this->assertDatabaseCount('swipes', 0);
    }

    public function test_staff_cannot_use_the_social_features(): void
    {
        $target = $this->studentWithProfile($this->utt);
        $staff = [
            User::factory()->moderator($this->utt)->create(),
            User::factory()->admin()->create(),
        ];

        foreach ($staff as $member) {
            Sanctum::actingAs($member);

            $this->getJson('/api/discover')->assertForbidden();
            $this->getJson('/api/discover/filters')->assertForbidden();
            $this->putJson('/api/discover/filters', [])->assertForbidden();
            $this->postJson('/api/swipes', ['user_id' => $target->id, 'liked' => true])->assertForbidden();
            $this->getJson('/api/profile')
                ->assertForbidden()
                ->assertJsonPath('message', 'Tu cuenta no tiene acceso a esta sección.');
            $this->patchJson('/api/profile', ['bio' => 'Hola'])->assertForbidden();
        }

        $this->assertDatabaseCount('swipes', 0);
        $this->assertDatabaseMissing('profiles', ['bio' => 'Hola']);
    }

    public function test_guests_and_unverified_students_cannot_swipe(): void
    {
        $target = $this->studentWithProfile($this->utt);

        $this->postJson('/api/swipes', ['user_id' => $target->id, 'liked' => true])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->unverified()->student($this->utt)->create());
        $this->postJson('/api/swipes', ['user_id' => $target->id, 'liked' => true])->assertForbidden();
        $this->getJson('/api/discover')->assertForbidden();

        $this->assertDatabaseCount('swipes', 0);
    }
}
