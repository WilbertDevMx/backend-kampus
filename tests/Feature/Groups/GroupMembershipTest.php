<?php

namespace Tests\Feature\Groups;

use App\Models\Group;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupMembershipTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create();
    }

    public function test_a_student_joins_an_approved_group_from_any_university(): void
    {
        $group = Group::factory()->approved()->create();
        $luis = User::factory()->student($this->utt)->create();
        Sanctum::actingAs($luis);

        $this->postJson("/api/groups/{$group->id}/members")
            ->assertCreated()
            ->assertJsonPath('data.my_role', 'member')
            ->assertJsonPath('data.members_count', 1);
        $this->postJson("/api/groups/{$group->id}/members")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ya eres integrante de este grupo.');

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $luis->id, 'role' => 'member']);
    }

    public function test_nobody_joins_a_group_in_review_or_rejected(): void
    {
        $pending = Group::factory()->create();
        $rejected = Group::factory()->rejected()->create();
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        $this->postJson("/api/groups/{$pending->id}/members")->assertNotFound();
        $this->postJson("/api/groups/{$rejected->id}/members")->assertNotFound();

        $this->assertDatabaseCount('group_members', 0);
    }

    public function test_a_member_leaves_the_group(): void
    {
        $ana = User::factory()->student($this->utt)->create();
        $luis = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->approved()->create();
        $group->members()->attach($luis->id, ['role' => 'member']);
        Sanctum::actingAs($luis);

        $this->deleteJson("/api/groups/{$group->id}/members/me")->assertNoContent();
        $this->deleteJson("/api/groups/{$group->id}/members/me")
            ->assertInvalid(['group' => 'No eres integrante de este grupo.']);

        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id, 'user_id' => $luis->id]);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $ana->id, 'role' => 'admin']);
    }

    public function test_when_the_last_admin_leaves_the_oldest_member_takes_over(): void
    {
        $ana = User::factory()->student($this->utt)->create();
        $oldest = User::factory()->student($this->utt)->create();
        $newest = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->approved()->create();
        $group->members()->attach($oldest->id, ['role' => 'member', 'created_at' => now()->subDays(2)]);
        $group->members()->attach($newest->id, ['role' => 'member', 'created_at' => now()->subDay()]);
        Sanctum::actingAs($ana);

        $this->deleteJson("/api/groups/{$group->id}/members/me")->assertNoContent();

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $oldest->id, 'role' => 'admin']);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $newest->id, 'role' => 'member']);
    }

    public function test_the_only_member_cannot_leave_and_has_to_delete_the_group(): void
    {
        $ana = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->approved()->create();
        Sanctum::actingAs($ana);

        $this->deleteJson("/api/groups/{$group->id}/members/me")
            ->assertInvalid(['group' => 'Eres el único integrante. Si ya no quieres el grupo, bórralo.']);

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $ana->id]);
    }

    public function test_only_members_see_the_member_list_admins_first(): void
    {
        $ana = User::factory()->student($this->utt)->create(['name' => 'Ana']);
        $luis = User::factory()->student($this->utt)->create(['name' => 'Luis']);
        $group = Group::factory()->createdBy($ana)->approved()->create();
        $group->members()->attach($luis->id, ['role' => 'member']);

        Sanctum::actingAs($luis);
        $this->getJson("/api/groups/{$group->id}/members")
            ->assertOk()
            ->assertJsonPath('data.0.id', $ana->id)
            ->assertJsonPath('data.0.role', 'admin')
            ->assertJsonPath('data.1.id', $luis->id)
            ->assertJsonMissingPath('data.0.email');

        Sanctum::actingAs(User::factory()->student($this->utt)->create());
        $this->getJson("/api/groups/{$group->id}/members")
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los integrantes ven la lista del grupo.');
    }
}
