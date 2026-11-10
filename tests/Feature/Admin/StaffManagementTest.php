<?php

namespace Tests\Feature\Admin;

use App\Enums\ModerationActionType;
use App\Enums\UserRole;
use App\Models\ModerationAction;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Kampus2026segura';

    private University $utt;

    private University $uadec;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT', 'email_domains' => ['utt.edu.mx']]);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC', 'email_domains' => ['uadec.edu.mx']]);
        $this->admin = User::factory()->admin()->create(['email' => 'admin@utt.edu.mx']);
    }

    public function test_an_admin_creates_a_moderator_for_one_university(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/admin/staff', $this->moderatorPayload(['email' => '  Moderador@UTT.edu.mx ']))
            ->assertCreated()
            ->assertJsonPath('data.email', 'moderador@utt.edu.mx')
            ->assertJsonPath('data.role', 'moderator')
            ->assertJsonPath('data.university.short_name', 'UTT')
            ->assertJsonPath('data.active', true)
            ->assertJsonMissingPath('data.password');

        $moderator = User::query()->findOrFail($response->json('data.id'));
        $this->assertSame(UserRole::Moderator, $moderator->role);
        $this->assertSame($this->utt->id, $moderator->university_id);
        $this->assertTrue($moderator->hasVerifiedEmail());
        $this->assertTrue(Hash::check(self::PASSWORD, $moderator->password));
        $this->assertDatabaseHas('moderation_actions', [
            'actor_id' => $this->admin->id,
            'action' => 'create_staff',
            'target_type' => 'user',
            'target_id' => $moderator->id,
            'university_id' => $this->utt->id,
            'reason' => 'Moderación de la UTT para el piloto.',
        ]);
    }

    public function test_an_admin_creates_another_admin_without_a_university(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/staff', $this->moderatorPayload([
            'email' => 'coordinacion@uadec.edu.mx',
            'role' => 'admin',
            'university_id' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.university', null);

        $this->assertDatabaseHas('users', ['email' => 'coordinacion@uadec.edu.mx', 'role' => 'admin', 'university_id' => null]);
        $this->assertDatabaseHas('moderation_actions', ['action' => 'create_staff', 'university_id' => null]);
    }

    public function test_the_email_must_belong_to_the_assigned_university(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/staff', $this->moderatorPayload(['email' => 'moderador@uadec.edu.mx']))
            ->assertInvalid(['email' => 'El correo debe ser del dominio de la universidad asignada.']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload(['email' => 'moderador@gmail.com']))
            ->assertInvalid(['email' => 'El correo debe ser de una institución participante.']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload([
            'email' => 'admin2@gmail.com',
            'role' => 'admin',
            'university_id' => null,
        ]))->assertInvalid(['email' => 'El correo debe ser de una institución participante.']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_a_moderator_needs_exactly_one_active_university_and_an_admin_none(): void
    {
        $inactive = University::factory()->create(['email_domains' => ['cerrada.edu.mx'], 'is_active' => false]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/staff', $this->moderatorPayload(['university_id' => null]))
            ->assertInvalid(['university_id']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload([
            'email' => 'moderador@cerrada.edu.mx',
            'university_id' => $inactive->id,
        ]))->assertInvalid(['university_id']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload(['university_id' => 'UTT']))
            ->assertInvalid(['university_id']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload(['role' => 'admin']))
            ->assertInvalid(['university_id']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_the_new_account_needs_a_strong_password_a_unique_email_and_a_reason(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/staff', $this->moderatorPayload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))->assertInvalid(['password']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload(['email' => 'ADMIN@utt.edu.mx']))
            ->assertInvalid(['email']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload(['reason' => '']))
            ->assertInvalid(['reason']);
        $this->postJson('/api/admin/staff', $this->moderatorPayload(['role' => 'student']))
            ->assertInvalid(['role']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_only_admins_can_manage_staff(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $student = User::factory()->student($this->utt)->create();

        $this->getJson('/api/admin/staff')->assertUnauthorized();

        foreach ([$moderator, $student] as $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/admin/staff')->assertForbidden();
            $this->postJson('/api/admin/staff', $this->moderatorPayload())->assertForbidden();
            $this->patchJson("/api/admin/staff/{$this->admin->id}", ['active' => false, 'reason' => 'x'])
                ->assertForbidden();
        }

        $this->assertNull($this->admin->fresh()->suspended_at);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_the_staff_list_shows_moderators_and_admins_but_not_students(): void
    {
        $moderator = User::factory()->moderator($this->uadec)->create(['name' => 'Beatriz']);
        User::factory()->student($this->utt)->create();
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/admin/staff')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 20);

        $this->assertEqualsCanonicalizing([$this->admin->id, $moderator->id], $response->json('data.*.id'));
        $this->assertSame(
            'UAdeC',
            collect($response->json('data'))->firstWhere('id', $moderator->id)['university']['short_name'],
        );
    }

    public function test_deactivating_a_staff_account_closes_its_sessions_and_blocks_login(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create(['email' => 'moderador@utt.edu.mx']);
        $moderator->createToken('panel');
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/staff/{$moderator->id}", [
            'active' => false,
            'reason' => 'Terminó su servicio social.',
        ])
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.deactivated_at', fn (?string $date): bool => $date !== null);

        $this->assertNotNull($moderator->fresh()->suspended_at);
        $this->assertSame(0, $moderator->tokens()->count());
        $this->assertDatabaseHas('users', ['id' => $moderator->id]);
        $this->assertDatabaseHas('moderation_actions', [
            'actor_id' => $this->admin->id,
            'action' => 'deactivate_staff',
            'target_id' => $moderator->id,
            'university_id' => $this->utt->id,
            'reason' => 'Terminó su servicio social.',
        ]);

        $this->postJson('/api/login', ['email' => 'moderador@utt.edu.mx', 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta está suspendida.');
    }

    public function test_a_deactivated_staff_account_can_be_reactivated(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create(['suspended_at' => now()]);
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/staff/{$moderator->id}", ['active' => true, 'reason' => 'Regresa al equipo.'])
            ->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.deactivated_at', null);

        $this->assertNull($moderator->fresh()->suspended_at);
        $this->assertDatabaseHas('moderation_actions', ['action' => 'reactivate_user', 'target_id' => $moderator->id]);
    }

    public function test_invalid_status_changes_are_rejected(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $deactivated = User::factory()->moderator($this->utt)->create(['suspended_at' => now()]);
        $student = User::factory()->student($this->utt)->create();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/staff/{$this->admin->id}", ['active' => false, 'reason' => 'Prueba.'])
            ->assertInvalid(['active' => 'No puedes cambiar el estado de tu propia cuenta.']);
        $this->patchJson("/api/admin/staff/{$moderator->id}", ['active' => true, 'reason' => 'Prueba.'])
            ->assertInvalid(['active' => 'La cuenta ya está activa.']);
        $this->patchJson("/api/admin/staff/{$deactivated->id}", ['active' => false, 'reason' => 'Prueba.'])
            ->assertInvalid(['active' => 'La cuenta ya está desactivada.']);
        $this->patchJson("/api/admin/staff/{$moderator->id}", ['active' => false])
            ->assertInvalid(['reason']);
        $this->patchJson("/api/admin/staff/{$student->id}", ['active' => false, 'reason' => 'Prueba.'])
            ->assertNotFound();

        $this->assertNull($moderator->fresh()->suspended_at);
        $this->assertNull($student->fresh()->suspended_at);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_the_moderation_log_cannot_be_edited_or_deleted(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $entry = ModerationAction::recordOnUser(
            $this->admin,
            ModerationActionType::CreateStaff,
            $moderator,
            'Alta original.',
            $this->utt->id,
        );

        $this->assertFalse($entry->update(['reason' => 'Motivo cambiado.']));
        $this->assertFalse($entry->delete());

        $this->assertDatabaseHas('moderation_actions', ['id' => $entry->id, 'reason' => 'Alta original.']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function moderatorPayload(array $overrides = []): array
    {
        return [
            'name' => 'Moderador UTT',
            'email' => 'moderador@utt.edu.mx',
            'role' => 'moderator',
            'university_id' => $this->utt->id,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'reason' => 'Moderación de la UTT para el piloto.',
            ...$overrides,
        ];
    }
}
