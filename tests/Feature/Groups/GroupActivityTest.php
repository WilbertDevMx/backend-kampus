<?php

namespace Tests\Feature\Groups;

use App\Models\Group;
use App\Models\GroupActivity;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupActivityTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    private User $admin;

    private User $member;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create();
        $this->admin = User::factory()->student($this->utt)->create(['name' => 'Ana']);
        $this->member = User::factory()->student($this->utt)->create();
        $this->group = Group::factory()->createdBy($this->admin)->approved()->create();
        $this->group->members()->attach($this->member->id, ['role' => 'member']);
    }

    public function test_a_group_admin_publishes_an_activity(): void
    {
        Sanctum::actingAs($this->admin);
        $startsAt = now()->addDays(5)->setTime(17, 0);

        $this->postJson("/api/groups/{$this->group->id}/activities", [
            'title' => 'Torneo relámpago',
            'description' => 'Equipos de cinco.',
            'location' => 'Cancha 2, UTT',
            'starts_at' => $startsAt->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Torneo relámpago')
            ->assertJsonPath('data.location', 'Cancha 2, UTT')
            ->assertJsonPath('data.starts_at', $startsAt->toIso8601String())
            ->assertJsonPath('data.created_by.id', $this->admin->id);

        $this->assertDatabaseHas('group_activities', ['group_id' => $this->group->id, 'title' => 'Torneo relámpago', 'created_by' => $this->admin->id]);
    }

    public function test_activities_need_a_title_and_a_future_date(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/groups/{$this->group->id}/activities", [])->assertInvalid(['title', 'starts_at']);
        $this->postJson("/api/groups/{$this->group->id}/activities", [
            'title' => 'Ayer',
            'starts_at' => now()->subDay()->toIso8601String(),
        ])->assertInvalid(['starts_at']);
    }

    public function test_only_admins_of_an_approved_group_publish_activities(): void
    {
        $payload = ['title' => 'Reunión', 'starts_at' => now()->addDay()->toIso8601String()];

        Sanctum::actingAs($this->member);
        $this->postJson("/api/groups/{$this->group->id}/activities", $payload)->assertForbidden();

        $pending = Group::factory()->createdBy($this->admin)->create();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/groups/{$pending->id}/activities", $payload)
            ->assertInvalid(['group' => 'El grupo todavía no está aprobado.']);

        Sanctum::actingAs(User::factory()->student($this->utt)->create());
        $this->postJson("/api/groups/{$pending->id}/activities", $payload)->assertNotFound();

        $this->assertDatabaseCount('group_activities', 0);
    }

    public function test_anyone_who_sees_the_group_lists_upcoming_or_past_activities(): void
    {
        $later = GroupActivity::factory()->create(['group_id' => $this->group->id, 'starts_at' => now()->addDays(9)]);
        $soon = GroupActivity::factory()->create(['group_id' => $this->group->id, 'starts_at' => now()->addDay()]);
        $past = GroupActivity::factory()->create(['group_id' => $this->group->id, 'starts_at' => now()->subDay()]);
        GroupActivity::factory()->create();
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        $this->assertSame(
            [$soon->id, $later->id],
            $this->getJson("/api/groups/{$this->group->id}/activities")->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$past->id],
            $this->getJson("/api/groups/{$this->group->id}/activities?past=1")->assertOk()->json('data.*.id'),
        );
    }

    public function test_a_group_admin_edits_and_deletes_an_activity_of_their_own_group(): void
    {
        $activity = GroupActivity::factory()->create(['group_id' => $this->group->id]);
        $foreign = GroupActivity::factory()->create();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/groups/{$this->group->id}/activities/{$activity->id}", ['location' => 'Auditorio'])
            ->assertOk()
            ->assertJsonPath('data.location', 'Auditorio');
        $this->patchJson("/api/groups/{$this->group->id}/activities/{$foreign->id}", ['location' => 'Auditorio'])
            ->assertNotFound();

        Sanctum::actingAs($this->member);
        $this->deleteJson("/api/groups/{$this->group->id}/activities/{$activity->id}")->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/groups/{$this->group->id}/activities/{$activity->id}")->assertNoContent();

        $this->assertModelMissing($activity);
        $this->assertModelExists($foreign);
    }
}
