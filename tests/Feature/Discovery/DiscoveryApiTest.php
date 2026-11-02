<?php

namespace Tests\Feature\Discovery;

use App\Models\Interest;
use App\Models\Photo;
use App\Models\Swipe;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscoveryApiTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    private University $utt;

    private University $uadec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC']);
    }

    public function test_discovery_shows_students_from_every_participating_university_without_private_data(): void
    {
        $viewer = $this->studentWithProfile($this->utt);
        $sameUniversity = $this->studentWithProfile($this->utt, ['birthdate' => now()->subYears(21)->toDateString()]);
        $otherUniversity = $this->studentWithProfile($this->uadec);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/discover')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$sameUniversity->id, $otherUniversity->id],
            $response->json('data.*.id'),
        );
        $card = collect($response->json('data'))->firstWhere('id', $sameUniversity->id);
        $this->assertSame('UTT', $card['university']['short_name']);
        $this->assertSame(21, $card['age']);
        $this->assertArrayNotHasKey('email', $card);
        $this->assertArrayNotHasKey('birthdate', $card);
        $this->assertArrayNotHasKey('zone_id', $card);
        $response->assertJsonPath('filters', ['universities' => [], 'career' => null, 'interests' => []]);
    }

    public function test_discovery_excludes_self_staff_unverified_suspended_and_users_without_profile(): void
    {
        $viewer = $this->studentWithProfile($this->utt);
        $visible = $this->studentWithProfile($this->utt);

        $moderator = User::factory()->moderator($this->utt)->create();
        $moderator->profile()->create(['bio' => 'El staff no debería tener perfil.']);
        $unverified = $this->studentWithProfile($this->utt);
        $unverified->forceFill(['email_verified_at' => null])->save();
        $suspended = $this->studentWithProfile($this->utt);
        $suspended->forceFill(['suspended_at' => now()])->save();
        User::factory()->student($this->utt)->create();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_discovery_hides_blocked_users_in_both_directions(): void
    {
        $viewer = $this->studentWithProfile($this->utt);
        $blockedByViewer = $this->studentWithProfile($this->utt);
        $blockedViewer = $this->studentWithProfile($this->utt);
        $visible = $this->studentWithProfile($this->utt);
        DB::table('blocks')->insert([
            ['blocker_id' => $viewer->id, 'blocked_id' => $blockedByViewer->id],
            ['blocker_id' => $blockedViewer->id, 'blocked_id' => $viewer->id],
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_an_evaluated_profile_never_shows_up_again(): void
    {
        $viewer = $this->studentWithProfile($this->utt);
        $liked = $this->studentWithProfile($this->utt);
        $passed = $this->studentWithProfile($this->utt);
        $pending = $this->studentWithProfile($this->utt);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/swipes', ['user_id' => $liked->id, 'liked' => true])->assertCreated();
        $this->postJson('/api/swipes', ['user_id' => $passed->id, 'liked' => false])->assertCreated();

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);
    }

    public function test_being_evaluated_by_someone_else_does_not_hide_a_profile(): void
    {
        $viewer = $this->studentWithProfile($this->utt);
        $candidate = $this->studentWithProfile($this->utt);
        Swipe::factory()->create(['swiper_id' => $candidate->id, 'swiped_id' => $viewer->id]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover')->assertOk()->assertJsonPath('data.0.id', $candidate->id);
    }

    public function test_discovery_applies_the_saved_filters(): void
    {
        $music = Interest::factory()->create();
        $soccer = Interest::factory()->create();
        $viewer = $this->studentWithProfile($this->utt, [
            'discovery_filters' => [
                'universities' => [$this->uadec->id],
                'career' => 'sistemas',
                'interests' => [$music->id, $soccer->id],
            ],
        ]);
        $match = $this->studentWithProfile($this->uadec, ['career' => 'Ingeniería en Sistemas'], [$soccer]);
        $this->studentWithProfile($this->utt, ['career' => 'Ingeniería en Sistemas'], [$soccer]);
        $this->studentWithProfile($this->uadec, ['career' => 'Medicina'], [$soccer]);
        $this->studentWithProfile($this->uadec, ['career' => 'Ingeniería en Sistemas']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('filters.universities', [$this->uadec->id]);
    }

    public function test_the_career_filter_treats_wildcards_as_plain_text(): void
    {
        $viewer = $this->studentWithProfile($this->utt, [
            'discovery_filters' => ['career' => '100%'],
        ]);
        $match = $this->studentWithProfile($this->utt, ['career' => 'Inglés 100%']);
        $this->studentWithProfile($this->utt, ['career' => 'Inglés 1000']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_profiles_with_more_shared_interests_come_first(): void
    {
        [$music, $soccer, $reading] = Interest::factory()->count(3)->create()->all();
        $viewer = $this->studentWithProfile($this->utt, [], [$music, $soccer]);
        $noneShared = $this->studentWithProfile($this->utt, [], [$reading]);
        $twoShared = $this->studentWithProfile($this->uadec, [], [$music, $soccer, $reading]);
        $oneSharedOtherUniversity = $this->studentWithProfile($this->uadec, [], [$music]);
        $oneSharedSameUniversity = $this->studentWithProfile($this->utt, [], [$soccer]);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/discover')->assertOk();

        $this->assertSame(
            [$twoShared->id, $oneSharedSameUniversity->id, $oneSharedOtherUniversity->id, $noneShared->id],
            $response->json('data.*.id'),
        );
        $this->assertSame([2, 1, 1, 0], $response->json('data.*.shared_interests'));
    }

    public function test_photos_come_ordered_with_signed_urls(): void
    {
        Storage::fake('s3');
        $viewer = $this->studentWithProfile($this->utt);
        $candidate = $this->studentWithProfile($this->utt);
        $second = Photo::factory()->create(['profile_id' => $candidate->profile->id, 'position' => 2]);
        $first = Photo::factory()->create(['profile_id' => $candidate->profile->id, 'position' => 1]);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/discover')->assertOk();

        $this->assertSame([$first->id, $second->id], $response->json('data.0.photos.*.id'));
        $this->assertNotEmpty($response->json('data.0.photos.0.temporary_url'));
        $this->assertArrayNotHasKey('path', $response->json('data.0.photos.0'));
    }

    public function test_the_limit_controls_the_batch_size(): void
    {
        $viewer = $this->studentWithProfile($this->utt);
        foreach (range(1, 3) as $ignored) {
            $this->studentWithProfile($this->utt);
        }
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/discover?limit=50')->assertUnprocessable()->assertInvalid(['limit']);
    }

    /**
     * Criterio de terminado de T-09 (respuesta < 500 ms): el número de consultas no crece con la tanda.
     */
    public function test_the_number_of_queries_does_not_grow_with_the_batch(): void
    {
        Storage::fake('s3');
        $interest = Interest::factory()->create();
        $viewer = $this->studentWithProfile($this->utt, [], [$interest]);
        $this->studentWithPhoto($interest);

        $queriesForOne = $this->countDiscoveryQueries($viewer, expectedCards: 1);

        foreach (range(1, 9) as $ignored) {
            $this->studentWithPhoto($interest);
        }

        $this->assertSame($queriesForOne, $this->countDiscoveryQueries($viewer, expectedCards: 10));
    }

    private function studentWithPhoto(Interest $interest): User
    {
        $student = $this->studentWithProfile($this->uadec, [], [$interest]);
        Photo::factory()->create(['profile_id' => $student->profile->id]);

        return $student;
    }

    /**
     * Cada medición parte de un usuario recién leído, como en una petición real (sin relaciones cargadas).
     */
    private function countDiscoveryQueries(User $viewer, int $expectedCards): int
    {
        Sanctum::actingAs($viewer->fresh());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/discover')->assertOk()->assertJsonCount($expectedCards, 'data');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
