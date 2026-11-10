<?php

namespace Tests\Feature\Admin;

use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\University;
use App\Models\User;
use App\Notifications\GroupReviewedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupReviewTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    private University $uadec;

    private User $moderator;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC']);
        $this->moderator = User::factory()->moderator($this->utt)->create(['name' => 'Moderador UTT']);
        $this->admin = User::factory()->admin()->create();
        $this->student = User::factory()->student($this->utt)->create(['name' => 'Ana']);
    }

    public function test_a_moderator_sees_only_the_pending_groups_of_their_university_oldest_first(): void
    {
        $newer = Group::factory()->createdBy($this->student)->create(['created_at' => now()->subHour()]);
        $older = Group::factory()->createdBy($this->student)->create(['created_at' => now()->subDay()]);
        Group::factory()->approved()->create(['university_id' => $this->utt->id]);
        $foreign = Group::factory()->create(['university_id' => $this->uadec->id]);
        Sanctum::actingAs($this->moderator);

        $this->getJson('/api/admin/groups')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.0.creator.name', 'Ana')
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($this->admin);
        $this->assertEqualsCanonicalizing(
            [$older->id, $newer->id, $foreign->id],
            $this->getJson('/api/admin/groups')->json('data.*.id'),
        );
    }

    public function test_a_moderator_approves_a_group_logs_it_and_tells_the_creator(): void
    {
        Notification::fake();
        $group = Group::factory()->createdBy($this->student)->create();
        Sanctum::actingAs($this->moderator);

        $this->postJson("/api/admin/groups/{$group->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by.name', 'Moderador UTT');

        $group->refresh();
        $this->assertSame(GroupStatus::Approved, $group->status);
        $this->assertNotNull($group->reviewed_at);
        $this->assertDatabaseHas('moderation_actions', [
            'actor_id' => $this->moderator->id,
            'action' => 'approve_group',
            'target_type' => 'group',
            'target_id' => $group->id,
            'university_id' => $this->utt->id,
        ]);
        Notification::assertSentTo($this->student, GroupReviewedNotification::class, function (GroupReviewedNotification $notification): bool {
            return $notification instanceof ShouldQueue
                && str_contains($notification->toMail($this->student)->subject, 'ya está publicado');
        });

        Sanctum::actingAs($this->student);
        $this->getJson('/api/groups')->assertJsonPath('data.0.id', $group->id);
    }

    public function test_rejecting_requires_a_reason_that_the_creator_sees(): void
    {
        Notification::fake();
        $group = Group::factory()->createdBy($this->student)->create();
        Sanctum::actingAs($this->moderator);

        $this->postJson("/api/admin/groups/{$group->id}/reject")->assertInvalid(['reason']);
        $this->postJson("/api/admin/groups/{$group->id}/reject", ['reason' => str_repeat('a', 301)])->assertInvalid(['reason']);

        $this->postJson("/api/admin/groups/{$group->id}/reject", ['reason' => 'El nombre no es apropiado.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'El nombre no es apropiado.');

        $this->assertDatabaseHas('moderation_actions', ['action' => 'reject_group', 'target_id' => $group->id, 'reason' => 'El nombre no es apropiado.']);
        Notification::assertSentTo($this->student, GroupReviewedNotification::class, function (GroupReviewedNotification $notification): bool {
            $mail = $notification->toMail($this->student);

            return str_contains($mail->subject, 'no se aprobó') && in_array('El nombre no es apropiado.', $mail->introLines, true);
        });

        Sanctum::actingAs($this->student);
        $this->getJson("/api/groups/{$group->id}")->assertJsonPath('data.rejection_reason', 'El nombre no es apropiado.');
    }

    public function test_a_moderator_cannot_review_groups_of_another_university_and_nobody_reviews_twice(): void
    {
        Notification::fake();
        $foreign = Group::factory()->create(['university_id' => $this->uadec->id]);
        $approved = Group::factory()->approved()->create(['university_id' => $this->utt->id]);
        Sanctum::actingAs($this->moderator);

        $this->postJson("/api/admin/groups/{$foreign->id}/approve")->assertNotFound();
        $this->postJson("/api/admin/groups/{$approved->id}/reject", ['reason' => 'Tarde.'])
            ->assertInvalid(['group' => 'El grupo ya fue revisado.']);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/groups/{$foreign->id}/approve")->assertOk();

        $this->assertDatabaseCount('moderation_actions', 1);
    }

    public function test_staff_create_official_groups_that_are_published_right_away(): void
    {
        Sanctum::actingAs($this->moderator);

        $response = $this->postJson('/api/admin/groups', [
            'name' => 'Selección de Futbol UTT',
            'category' => 'deportivo',
            'admin_user_id' => $this->student->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_official', true)
            ->assertJsonPath('data.university.short_name', 'UTT')
            ->assertJsonPath('data.members_count', 1);

        $groupId = $response->json('data.id');
        $this->assertDatabaseHas('group_members', ['group_id' => $groupId, 'user_id' => $this->student->id, 'role' => 'admin']);
        $this->assertDatabaseHas('moderation_actions', ['action' => 'approve_group', 'target_id' => $groupId]);

        Sanctum::actingAs($this->student);
        $this->getJson('/api/groups')
            ->assertJsonPath('data.0.id', $groupId)
            ->assertJsonPath('data.0.is_official', true)
            ->assertJsonPath('data.0.my_role', 'admin');
    }

    public function test_official_group_rules_by_role(): void
    {
        $foreignStudent = User::factory()->student($this->uadec)->create();
        $payload = ['name' => 'Club', 'category' => 'social'];

        Sanctum::actingAs($this->moderator);
        $this->postJson('/api/admin/groups', [...$payload, 'university_id' => $this->uadec->id])
            ->assertInvalid(['university_id']);
        $this->postJson('/api/admin/groups', [...$payload, 'admin_user_id' => $foreignStudent->id])
            ->assertInvalid(['admin_user_id']);
        $this->postJson('/api/admin/groups', [...$payload, 'admin_user_id' => $this->admin->id])
            ->assertInvalid(['admin_user_id']);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/admin/groups', [...$payload, 'admin_user_id' => $foreignStudent->id])
            ->assertCreated()
            ->assertJsonPath('data.university', null);

        $this->assertDatabaseCount('groups', 1);
    }

    public function test_students_cannot_use_the_review_panel(): void
    {
        $group = Group::factory()->createdBy($this->student)->create();
        Sanctum::actingAs($this->student);

        $this->getJson('/api/admin/groups')->assertForbidden();
        $this->postJson("/api/admin/groups/{$group->id}/approve")->assertForbidden();
        $this->postJson('/api/admin/groups', ['name' => 'Club', 'category' => 'social'])->assertForbidden();

        $this->assertSame(GroupStatus::Pending, $group->fresh()->status);
    }
}
