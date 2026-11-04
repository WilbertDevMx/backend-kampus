<?php

namespace Tests\Feature\Moderation;

use App\Models\University;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Discovery\CreatesStudents;
use Tests\TestCase;

class BlockApiTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create();
    }

    public function test_a_student_blocks_and_unblocks_someone(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        Sanctum::actingAs($ana);

        $this->postJson('/api/blocks', ['user_id' => $luis->id])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $luis->id);
        $this->postJson('/api/blocks', ['user_id' => $luis->id])->assertOk();
        $this->assertDatabaseCount('blocks', 1);

        $this->getJson('/api/blocks')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $luis->id)
            ->assertJsonPath('data.0.name', $luis->name);

        $this->deleteJson("/api/blocks/{$luis->id}")->assertNoContent();
        $this->deleteJson("/api/blocks/{$luis->id}")->assertNotFound();
        $this->assertDatabaseCount('blocks', 0);
    }

    public function test_a_block_hides_both_people_from_discovery_matches_and_chat(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        $match = UserMatch::factory()->between($ana, $luis)->create();

        Sanctum::actingAs($luis);
        $this->postJson('/api/blocks', ['user_id' => $ana->id])->assertCreated();

        Sanctum::actingAs($ana);
        $this->getJson('/api/discover')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/matches')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/swipes', ['user_id' => $luis->id, 'liked' => true])->assertInvalid(['user_id']);
        $this->assertFalse(UserMatch::query()->visibleTo($ana)->whereKey($match->id)->exists());

        Sanctum::actingAs($luis);
        $this->deleteJson("/api/blocks/{$ana->id}")->assertNoContent();

        Sanctum::actingAs($ana);
        $this->getJson('/api/matches')->assertJsonCount(1, 'data');
    }

    public function test_only_other_students_can_be_blocked(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $moderator = User::factory()->moderator($this->utt)->create();
        Sanctum::actingAs($ana);

        foreach ([$ana->id, $moderator->id, 999999] as $userId) {
            $this->postJson('/api/blocks', ['user_id' => $userId])
                ->assertInvalid(['user_id' => 'Este perfil no está disponible.']);
        }
        $this->postJson('/api/blocks', [])->assertInvalid(['user_id']);

        $this->assertDatabaseCount('blocks', 0);
    }

    public function test_staff_cannot_use_blocks(): void
    {
        $luis = $this->studentWithProfile($this->utt);
        Sanctum::actingAs(User::factory()->moderator($this->utt)->create());

        $this->getJson('/api/blocks')->assertForbidden();
        $this->postJson('/api/blocks', ['user_id' => $luis->id])->assertForbidden();
    }
}
