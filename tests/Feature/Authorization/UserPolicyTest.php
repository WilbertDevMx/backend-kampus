<?php

namespace Tests\Feature\Authorization;

use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Permisos del staff sobre las cuentas (T-40): un moderador solo actúa sobre estudiantes de su
 * universidad; un administrador, sobre todos; un estudiante, sobre nadie más que él mismo.
 */
class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    private University $uadec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT', 'email_domains' => ['utt.edu.mx']]);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC', 'email_domains' => ['uadec.edu.mx']]);
    }

    public function test_moderator_can_view_and_moderate_students_of_their_university(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $student = User::factory()->student($this->utt)->create();

        $this->assertTrue($moderator->can('viewAny', User::class));
        $this->assertTrue($moderator->can('view', $student));
        $this->assertTrue($moderator->can('moderate', $student));
    }

    public function test_moderator_cannot_touch_students_of_another_university(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $other = User::factory()->student($this->uadec)->create();

        $this->assertFalse($moderator->can('view', $other));
        $this->assertFalse($moderator->can('moderate', $other));
    }

    public function test_moderator_cannot_moderate_other_staff_even_in_their_university(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $colleague = User::factory()->moderator($this->utt)->create();
        $admin = User::factory()->admin()->create();

        $this->assertFalse($moderator->can('moderate', $colleague));
        $this->assertFalse($moderator->can('moderate', $admin));
    }

    public function test_moderator_without_university_cannot_moderate_anyone(): void
    {
        $moderator = User::factory()->create(['role' => 'moderator', 'university_id' => null]);
        $student = User::factory()->create(['university_id' => null]);

        $this->assertFalse($moderator->can('moderate', $student));
    }

    public function test_admin_can_moderate_users_of_any_university_but_not_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->can('moderate', User::factory()->student($this->uadec)->create()));
        $this->assertTrue($admin->can('moderate', User::factory()->moderator($this->utt)->create()));
        $this->assertFalse($admin->can('moderate', $admin));
        $this->assertTrue($admin->can('view', $admin));
    }

    public function test_student_cannot_view_or_moderate_other_accounts(): void
    {
        $student = User::factory()->student($this->utt)->create();
        $classmate = User::factory()->student($this->utt)->create();

        $this->assertFalse($student->can('viewAny', User::class));
        $this->assertFalse($student->can('view', $classmate));
        $this->assertFalse($student->can('moderate', $classmate));
        $this->assertTrue($student->can('view', $student));
    }

    public function test_manageable_by_scope_filters_users_by_role_and_university(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $admin = User::factory()->admin()->create();
        $uttStudent = User::factory()->student($this->utt)->create();
        $uadecStudent = User::factory()->student($this->uadec)->create();
        $uttModerator = User::factory()->moderator($this->utt)->create();

        $this->assertEqualsCanonicalizing(
            [$uttStudent->id],
            User::manageableBy($moderator)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$moderator->id, $uttStudent->id, $uadecStudent->id, $uttModerator->id],
            User::manageableBy($admin)->pluck('id')->all(),
        );
        $this->assertSame([], User::manageableBy($uttStudent)->pluck('id')->all());
    }
}
