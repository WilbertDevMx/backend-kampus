<?php

namespace Tests\Feature\Admin;

use App\Models\Report;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportReviewTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    private University $uadec;

    private User $moderator;

    private User $admin;

    private User $reported;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC']);
        $this->moderator = User::factory()->moderator($this->utt)->create(['name' => 'Moderador UTT']);
        $this->admin = User::factory()->admin()->create();
        $this->reported = User::factory()->student($this->utt)->create(['name' => 'Luis']);
    }

    public function test_a_moderator_sees_only_the_pending_reports_of_their_university_oldest_first(): void
    {
        $newer = Report::factory()->against($this->reported)->create(['created_at' => now()->subHour()]);
        $older = Report::factory()->against($this->reported)->create(['created_at' => now()->subDay()]);
        Report::factory()->against($this->reported)->dismissed($this->moderator)->create();
        $foreign = Report::factory()->create(['university_id' => $this->uadec->id]);
        Sanctum::actingAs($this->moderator);

        $this->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.reported.name', 'Luis')
            ->assertJsonPath('data.0.reported.suspended', false)
            ->assertJsonPath('data.0.reported.reports_count', 3)
            ->assertJsonPath('data.0.university.short_name', 'UTT')
            ->assertJsonPath('data.1.id', $newer->id);

        Sanctum::actingAs($this->admin);
        $this->assertEqualsCanonicalizing(
            [$older->id, $newer->id, $foreign->id],
            $this->getJson('/api/admin/reports')->json('data.*.id'),
        );
    }

    public function test_the_list_filters_by_status_reported_user_and_reason(): void
    {
        $pending = Report::factory()->against($this->reported)->create(['created_at' => now()->subDays(3)]);
        $dismissed = Report::factory()->against($this->reported)->dismissed($this->moderator)->create(['created_at' => now()->subDays(2)]);
        $other = User::factory()->student($this->utt)->create();
        $harassment = Report::factory()->against($other)->create(['reason' => 'acoso', 'created_at' => now()->subDay()]);
        Sanctum::actingAs($this->moderator);

        $this->assertSame(
            [$harassment->id, $dismissed->id, $pending->id],
            $this->getJson('/api/admin/reports?status=all')->assertOk()->json('data.*.id'),
        );
        $this->assertSame([$dismissed->id], $this->getJson('/api/admin/reports?status=dismissed')->json('data.*.id'));
        $this->assertSame([$pending->id], $this->getJson("/api/admin/reports?user_id={$this->reported->id}")->json('data.*.id'));
        $this->assertSame([$harassment->id], $this->getJson('/api/admin/reports?reason=acoso')->json('data.*.id'));

        $this->getJson('/api/admin/reports?status=todos')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/admin/reports?reason=insultos')->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_students_and_guests_cannot_use_the_moderation_panel(): void
    {
        $report = Report::factory()->against($this->reported)->create();

        $this->getJson('/api/admin/reports')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->student($this->utt)->create());
        $this->getJson('/api/admin/reports')->assertForbidden();
        $this->patchJson("/api/admin/reports/{$report->id}", ['status' => 'dismissed', 'reason' => 'x'])->assertForbidden();
    }

    public function test_a_moderator_dismisses_a_report_and_it_is_logged(): void
    {
        $report = Report::factory()->against($this->reported)->create();
        Sanctum::actingAs($this->moderator);

        $this->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'dismissed',
            'reason' => 'No hay evidencia de spam.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed')
            ->assertJsonPath('data.reviewed_by.name', 'Moderador UTT')
            ->assertJsonPath('data.reported.suspended', false);

        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'dismissed', 'reviewed_by' => $this->moderator->id]);
        $this->assertDatabaseHas('moderation_actions', [
            'actor_id' => $this->moderator->id,
            'action' => 'dismiss_report',
            'target_type' => 'report',
            'target_id' => $report->id,
            'report_id' => $report->id,
            'university_id' => $this->utt->id,
            'reason' => 'No hay evidencia de spam.',
        ]);
        $this->assertNull($this->reported->fresh()->suspended_at);
    }

    public function test_resolving_a_report_can_suspend_the_reported_account_and_close_its_sessions(): void
    {
        $report = Report::factory()->against($this->reported)->create(['reason' => 'acoso']);
        $this->reported->createToken('celular');
        Sanctum::actingAs($this->moderator);

        $this->patchJson("/api/admin/reports/{$report->id}", [
            'status' => 'reviewed',
            'reason' => 'Mandó insultos por el chat; se suspende.',
            'suspend' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewed')
            ->assertJsonPath('data.reported.suspended', true);

        $this->assertNotNull($this->reported->fresh()->suspended_at);
        $this->assertSame(0, $this->reported->tokens()->count());
        $this->assertDatabaseHas('moderation_actions', ['action' => 'resolve_report', 'target_id' => $report->id]);
        $this->assertDatabaseHas('moderation_actions', [
            'actor_id' => $this->moderator->id,
            'action' => 'suspend_user',
            'target_type' => 'user',
            'target_id' => $this->reported->id,
            'report_id' => $report->id,
            'university_id' => $this->utt->id,
            'reason' => 'Mandó insultos por el chat; se suspende.',
        ]);

        // Criterio de terminado de T-37: una cuenta suspendida no puede iniciar sesión.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $this->reported->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta está suspendida.');
    }

    public function test_an_account_that_is_already_suspended_is_not_suspended_twice(): void
    {
        $this->reported->forceFill(['suspended_at' => now()->subDay()])->save();
        $report = Report::factory()->against($this->reported)->create();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/reports/{$report->id}", ['status' => 'reviewed', 'reason' => 'Reincidente.', 'suspend' => true])
            ->assertOk()
            ->assertJsonPath('data.reported.suspended', true);

        $this->assertDatabaseCount('moderation_actions', 1);
        $this->assertDatabaseHas('moderation_actions', ['action' => 'resolve_report', 'actor_id' => $this->admin->id]);
    }

    public function test_a_moderator_cannot_see_or_review_reports_of_another_university(): void
    {
        $foreign = Report::factory()->create(['university_id' => $this->uadec->id]);
        Sanctum::actingAs($this->moderator);

        $this->getJson("/api/admin/reports/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/admin/reports/{$foreign->id}", ['status' => 'dismissed', 'reason' => 'x'])->assertNotFound();
        $this->assertDatabaseHas('reports', ['id' => $foreign->id, 'status' => 'pending']);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/reports/{$foreign->id}")->assertOk()->assertJsonPath('data.university.short_name', 'UAdeC');
    }

    public function test_a_report_is_reviewed_only_once(): void
    {
        $report = Report::factory()->against($this->reported)->dismissed($this->admin)->create();
        Sanctum::actingAs($this->moderator);

        $this->patchJson("/api/admin/reports/{$report->id}", ['status' => 'reviewed', 'reason' => 'Lo reviso de nuevo.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'El reporte ya fue revisado.']);

        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_reviewing_requires_a_reason_and_a_dismissed_report_cannot_suspend(): void
    {
        $report = Report::factory()->against($this->reported)->create();
        Sanctum::actingAs($this->moderator);

        $this->patchJson("/api/admin/reports/{$report->id}", ['status' => 'reviewed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->patchJson("/api/admin/reports/{$report->id}", ['status' => 'pending', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/admin/reports/{$report->id}", ['status' => 'dismissed', 'reason' => 'x', 'suspend' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['suspend' => 'Un reporte descartado no puede suspender la cuenta.']);

        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'pending']);
        $this->assertNull($this->reported->fresh()->suspended_at);
    }
}
