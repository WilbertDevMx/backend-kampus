<?php

namespace Tests\Feature\Admin;

use App\Enums\ModerationActionType;
use App\Models\ModerationAction;
use App\Models\Profile;
use App\Models\Report;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserModerationTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    private University $uadec;

    private User $moderator;

    private User $admin;

    private User $ana;

    private User $luis;

    private User $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC']);
        $this->moderator = User::factory()->moderator($this->utt)->create(['name' => 'Moderador UTT']);
        $this->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $this->ana = User::factory()->student($this->utt)->create(['name' => 'Ana Torres', 'email' => 'ana.torres@uttcampus.edu.mx']);
        $this->luis = User::factory()->student($this->utt)->create(['name' => 'Luis Pérez', 'email' => 'luis@uttcampus.edu.mx']);
        $this->foreign = User::factory()->student($this->uadec)->create(['name' => 'Bruno']);
    }

    public function test_a_moderator_lists_only_the_students_of_their_university(): void
    {
        User::factory()->moderator($this->utt)->create(['name' => 'Otro moderador']);
        Report::factory()->against($this->luis)->by($this->ana)->count(2)->create();
        Report::factory()->against($this->luis)->by($this->ana)->dismissed()->create();
        Sanctum::actingAs($this->moderator);

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ana Torres')
            ->assertJsonPath('data.0.role', 'student')
            ->assertJsonPath('data.0.university.short_name', 'UTT')
            ->assertJsonPath('data.0.suspended', false)
            ->assertJsonPath('data.1.name', 'Luis Pérez')
            ->assertJsonPath('data.1.reports_count', 3)
            ->assertJsonPath('data.1.pending_reports_count', 2);

        // El administrador ve todas las cuentas, staff incluido, menos la suya.
        Sanctum::actingAs($this->admin);
        $names = $this->getJson('/api/admin/users')->assertOk()->json('data.*.name');
        $this->assertContains('Bruno', $names);
        $this->assertContains('Moderador UTT', $names);
        $this->assertNotContains('Admin', $names);
    }

    public function test_the_list_searches_by_name_or_email_and_filters_and_sorts(): void
    {
        $this->luis->forceFill(['suspended_at' => now()])->save();
        Report::factory()->against($this->ana)->by($this->luis)->create();
        Sanctum::actingAs($this->moderator);

        $this->assertSame(['Ana Torres'], $this->getJson('/api/admin/users?search=TORRES')->json('data.*.name'));
        $this->assertSame(['Luis Pérez'], $this->getJson('/api/admin/users?search=luis@utt')->json('data.*.name'));
        // Los comodines de LIKE se buscan como texto.
        $this->assertSame([], $this->getJson('/api/admin/users?search=%25')->json('data'));

        $this->assertSame(['Luis Pérez'], $this->getJson('/api/admin/users?status=suspended')->json('data.*.name'));
        $this->assertSame(['Ana Torres'], $this->getJson('/api/admin/users?status=active')->json('data.*.name'));

        $other = User::factory()->student($this->utt)->create(['name' => 'Aaron']);
        Report::factory()->against($other)->by($this->ana)->count(2)->create();
        $this->assertSame(
            ['Aaron', 'Ana Torres', 'Luis Pérez'],
            $this->getJson('/api/admin/users?sort=reports')->json('data.*.name'),
        );

        Sanctum::actingAs($this->admin);
        $this->assertSame(['Bruno'], $this->getJson("/api/admin/users?university_id={$this->uadec->id}")->json('data.*.name'));
        $this->assertSame(['Moderador UTT'], $this->getJson('/api/admin/users?role=moderator')->json('data.*.name'));

        $this->getJson('/api/admin/users?status=borrados')->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_the_detail_shows_the_profile_the_reports_and_the_moderation_history(): void
    {
        Profile::factory()->for($this->luis)->create(['career' => 'Ingeniería en Software', 'semester' => 5]);
        $report = Report::factory()->against($this->luis)->by($this->ana)->create(['reason' => 'acoso', 'description' => null]);
        ModerationAction::recordOnUser($this->admin, ModerationActionType::SuspendUser, $this->luis, 'Primera falta.', $this->utt->id);
        Sanctum::actingAs($this->moderator);

        $this->getJson("/api/admin/users/{$this->luis->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Luis Pérez')
            ->assertJsonPath('data.profile.career', 'Ingeniería en Software')
            ->assertJsonPath('data.profile.completed', true)
            ->assertJsonPath('data.reports.0.id', $report->id)
            ->assertJsonPath('data.reports.0.reason', 'acoso')
            ->assertJsonPath('data.reports.0.reporter.name', 'Ana Torres')
            ->assertJsonPath('data.moderation_history.0.action', 'suspend_user')
            ->assertJsonPath('data.moderation_history.0.actor.name', 'Admin')
            ->assertJsonPath('data.pending_reports_count', 1);

        $this->getJson("/api/admin/users/{$this->ana->id}")->assertOk()->assertJsonPath('data.profile', null);
    }

    public function test_a_moderator_cannot_see_accounts_outside_their_university_or_staff(): void
    {
        Sanctum::actingAs($this->moderator);

        $this->getJson("/api/admin/users/{$this->foreign->id}")->assertNotFound();
        $this->getJson("/api/admin/users/{$this->admin->id}")->assertNotFound();
        $this->getJson("/api/admin/users/{$this->moderator->id}")->assertNotFound();
        $this->postJson("/api/admin/users/{$this->foreign->id}/suspend", ['reason' => 'x', 'report_id' => 1])->assertNotFound();
    }

    public function test_students_cannot_use_the_user_panel(): void
    {
        Sanctum::actingAs($this->ana);

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson("/api/admin/users/{$this->luis->id}/suspend", ['reason' => 'x'])->assertForbidden();
    }

    public function test_a_moderator_suspends_only_with_a_report_about_that_account(): void
    {
        $reportOnAna = Report::factory()->against($this->ana)->by($this->luis)->create();
        Sanctum::actingAs($this->moderator);

        $this->postJson("/api/admin/users/{$this->luis->id}/suspend", ['reason' => 'Acoso.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id' => 'Indica el reporte que justifica la suspensión.']);

        $this->postJson("/api/admin/users/{$this->luis->id}/suspend", ['reason' => 'Acoso.', 'report_id' => $reportOnAna->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id' => 'El reporte no es sobre esta cuenta.']);

        $this->postJson("/api/admin/users/{$this->luis->id}/suspend", ['report_id' => $reportOnAna->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertNull($this->luis->fresh()->suspended_at);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_suspending_with_a_pending_report_closes_the_sessions_and_resolves_the_report(): void
    {
        $report = Report::factory()->against($this->luis)->create();
        $this->luis->createToken('celular');
        Sanctum::actingAs($this->moderator);

        $this->postJson("/api/admin/users/{$this->luis->id}/suspend", [
            'reason' => 'Acoso confirmado por el chat.',
            'report_id' => $report->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.suspended', true)
            ->assertJsonPath('data.pending_reports_count', 0)
            ->assertJsonPath('data.moderation_history.0.action', 'suspend_user')
            ->assertJsonPath('data.moderation_history.0.report_id', $report->id);

        $this->assertNotNull($this->luis->fresh()->suspended_at);
        $this->assertSame(0, $this->luis->tokens()->count());
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'reviewed', 'reviewed_by' => $this->moderator->id]);
        $this->assertDatabaseHas('moderation_actions', [
            'actor_id' => $this->moderator->id,
            'action' => 'suspend_user',
            'target_id' => $this->luis->id,
            'report_id' => $report->id,
            'reason' => 'Acoso confirmado por el chat.',
        ]);
        $this->assertDatabaseHas('moderation_actions', ['action' => 'resolve_report', 'target_id' => $report->id]);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'luis@uttcampus.edu.mx', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta está suspendida.');
    }

    public function test_an_admin_can_suspend_without_a_report_but_not_twice(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$this->foreign->id}/suspend", ['reason' => 'Suplantación de identidad.'])
            ->assertOk()
            ->assertJsonPath('data.suspended', true);
        $this->assertDatabaseHas('moderation_actions', [
            'action' => 'suspend_user',
            'target_id' => $this->foreign->id,
            'report_id' => null,
            'university_id' => $this->uadec->id,
        ]);

        $this->postJson("/api/admin/users/{$this->foreign->id}/suspend", ['reason' => 'Otra vez.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'La cuenta ya está suspendida.']);
        $this->assertSame(1, ModerationAction::query()->where('action', 'suspend_user')->count());
    }

    public function test_staff_accounts_are_not_suspended_from_the_user_panel(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$this->moderator->id}/suspend", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'Las cuentas de staff se desactivan desde la gestión de staff.']);
        $this->assertNull($this->moderator->fresh()->suspended_at);
    }

    public function test_a_suspended_account_is_reactivated_and_can_log_in_again(): void
    {
        $this->luis->forceFill(['suspended_at' => now()->subDay()])->save();
        Sanctum::actingAs($this->moderator);

        $this->postJson("/api/admin/users/{$this->luis->id}/reactivate", ['reason' => 'Apeló y se revisó el caso.'])
            ->assertOk()
            ->assertJsonPath('data.suspended', false)
            ->assertJsonPath('data.moderation_history.0.action', 'reactivate_user');
        $this->assertDatabaseHas('moderation_actions', [
            'actor_id' => $this->moderator->id,
            'action' => 'reactivate_user',
            'target_id' => $this->luis->id,
            'reason' => 'Apeló y se revisó el caso.',
        ]);

        $this->postJson("/api/admin/users/{$this->luis->id}/reactivate", ['reason' => 'Otra vez.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'La cuenta no está suspendida.']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'luis@uttcampus.edu.mx', 'password' => 'password'])->assertCreated();
    }
}
