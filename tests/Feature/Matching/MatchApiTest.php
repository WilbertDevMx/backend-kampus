<?php

namespace Tests\Feature\Matching;

use App\Events\MatchCreated;
use App\Models\Photo;
use App\Models\Swipe;
use App\Models\University;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Discovery\CreatesStudents;
use Tests\TestCase;

class MatchApiTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
    }

    public function test_a_mutual_like_creates_a_match_and_dispatches_match_created(): void
    {
        Event::fake([MatchCreated::class]);
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        Swipe::factory()->create(['swiper_id' => $luis->id, 'swiped_id' => $ana->id]);
        Sanctum::actingAs($ana);

        $response = $this->postJson('/api/swipes', ['user_id' => $luis->id, 'liked' => true])
            ->assertCreated()
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.match.user.id', $luis->id)
            ->assertJsonPath('data.match.user.university.short_name', 'UTT');

        $match = UserMatch::query()->sole();
        $response->assertJsonPath('data.match.id', $match->id);
        $this->assertSame(UserMatch::pairOf($ana, $luis), [
            'user_one_id' => (int) $match->user_one_id,
            'user_two_id' => (int) $match->user_two_id,
        ]);
        Event::assertDispatchedTimes(MatchCreated::class, 1);
        Event::assertDispatched(MatchCreated::class, fn (MatchCreated $event) => $event->match->is($match));
    }

    public function test_the_pair_is_stored_in_order_no_matter_who_closes_it(): void
    {
        $first = $this->studentWithProfile($this->utt);
        $second = $this->studentWithProfile($this->utt);
        Swipe::factory()->create(['swiper_id' => $first->id, 'swiped_id' => $second->id]);
        Sanctum::actingAs($second);

        $this->postJson('/api/swipes', ['user_id' => $first->id, 'liked' => true])->assertCreated();

        $this->assertDatabaseHas('matches', ['user_one_id' => $first->id, 'user_two_id' => $second->id]);
        $this->assertDatabaseCount('matches', 1);
    }

    public function test_there_is_no_match_until_both_sides_like(): void
    {
        Event::fake([MatchCreated::class]);
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        $sofia = $this->studentWithProfile($this->utt);
        Swipe::factory()->passed()->create(['swiper_id' => $sofia->id, 'swiped_id' => $ana->id]);
        Sanctum::actingAs($ana);

        $this->postJson('/api/swipes', ['user_id' => $luis->id, 'liked' => true])
            ->assertCreated()
            ->assertJsonPath('data.match', null);
        $this->postJson('/api/swipes', ['user_id' => $sofia->id, 'liked' => true])
            ->assertCreated()
            ->assertJsonPath('data.match', null);

        $this->assertDatabaseCount('matches', 0);
        Event::assertNotDispatched(MatchCreated::class);
    }

    public function test_passing_on_someone_who_liked_you_does_not_create_a_match(): void
    {
        Event::fake([MatchCreated::class]);
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        Swipe::factory()->create(['swiper_id' => $luis->id, 'swiped_id' => $ana->id]);
        Sanctum::actingAs($ana);

        $this->postJson('/api/swipes', ['user_id' => $luis->id, 'liked' => false])
            ->assertCreated()
            ->assertJsonPath('data.match', null);

        $this->assertDatabaseCount('matches', 0);
        Event::assertNotDispatched(MatchCreated::class);
    }

    public function test_match_created_is_broadcast_to_both_private_user_channels(): void
    {
        Storage::fake('s3');
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        Photo::factory()->create(['profile_id' => $luis->profile->id, 'position' => 2]);
        $firstPhoto = Photo::factory()->create(['profile_id' => $luis->profile->id, 'position' => 1]);
        $match = UserMatch::factory()->between($ana, $luis)->create();

        $event = new MatchCreated($match);
        $payload = $event->broadcastWith();
        $users = collect($payload['match']['users'])->keyBy('id');

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame('match.created', $event->broadcastAs());
        $this->assertEqualsCanonicalizing(
            ['private-user.'.$ana->id, 'private-user.'.$luis->id],
            collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all(),
        );
        $this->assertSame($match->id, $payload['match']['id']);
        $this->assertEqualsCanonicalizing([$ana->id, $luis->id], $users->keys()->all());
        $this->assertNull($users[$ana->id]['photo_url']);
        $this->assertSame($firstPhoto->temporaryUrl(), $users[$luis->id]['photo_url']);
        $this->assertArrayNotHasKey('email', $users[$luis->id]);
    }

    public function test_the_match_list_shows_the_other_person_newest_first(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        $sofia = $this->studentWithProfile($this->utt);
        $older = UserMatch::factory()->between($ana, $luis)->create();
        $newer = UserMatch::factory()->between($sofia, $ana)->create();

        Sanctum::actingAs($ana);
        $this->getJson('/api/matches')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.user.id', $sofia->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.user.id', $luis->id)
            ->assertJsonMissingPath('data.0.user.email')
            ->assertJsonPath('meta.per_page', 20);

        Sanctum::actingAs($luis);
        $this->getJson('/api/matches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $ana->id);
    }

    public function test_the_match_list_hides_blocked_and_suspended_people_and_other_couples(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $blockedByAna = $this->studentWithProfile($this->utt);
        $blockedAna = $this->studentWithProfile($this->utt);
        $suspended = $this->studentWithProfile($this->utt);
        $suspended->forceFill(['suspended_at' => now()])->save();
        $visible = $this->studentWithProfile($this->utt);
        $otherCouple = [$this->studentWithProfile($this->utt), $this->studentWithProfile($this->utt)];

        foreach ([$blockedByAna, $blockedAna, $suspended, $visible] as $other) {
            UserMatch::factory()->between($ana, $other)->create();
        }
        UserMatch::factory()->between(...$otherCouple)->create();
        DB::table('blocks')->insert([
            ['blocker_id' => $ana->id, 'blocked_id' => $blockedByAna->id],
            ['blocker_id' => $blockedAna->id, 'blocked_id' => $ana->id],
        ]);

        Sanctum::actingAs($ana);
        $this->getJson('/api/matches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $visible->id);

        Sanctum::actingAs($blockedAna);
        $this->getJson('/api/matches')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_cannot_see_matches(): void
    {
        Sanctum::actingAs(User::factory()->moderator($this->utt)->create());
        $this->getJson('/api/matches')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/matches')->assertForbidden();
    }
}
