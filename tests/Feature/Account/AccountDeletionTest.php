<?php

namespace Tests\Feature\Account;

use App\Enums\ModerationActionType;
use App\Jobs\DeleteStoredFiles;
use App\Models\Group;
use App\Models\Message;
use App\Models\ModerationAction;
use App\Models\Photo;
use App\Models\Profile;
use App\Models\Report;
use App\Models\Swipe;
use App\Models\University;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['profile_photos_disk', 'chat_images_disk', 'group_images_disk'] as $disk) {
            Storage::fake(config('filesystems.'.$disk));
        }

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
        $this->ana = User::factory()->student($this->utt)->create(['name' => 'Ana']);
        $this->luis = User::factory()->student($this->utt)->create(['name' => 'Luis']);
    }

    public function test_a_student_deletes_their_account_with_all_their_data(): void
    {
        $profile = Profile::factory()->create(['user_id' => $this->ana->getKey()]);
        $photo = Photo::factory()->create(['profile_id' => $profile->getKey(), 'path' => 'profiles/ana/1.jpg']);
        $this->photos()->put($photo->path, 'foto');

        $match = UserMatch::factory()->between($this->ana, $this->luis)->create();
        Message::factory()->create(['match_id' => $match->getKey(), 'sender_id' => $this->ana->getKey()]);
        // La imagen la mandó Luis, pero el chat completo se borra con la cuenta de Ana.
        Message::factory()->create([
            'match_id' => $match->getKey(),
            'sender_id' => $this->luis->getKey(),
            'type' => 'image',
            'attachment_path' => 'chats/'.$match->getKey().'/foto.jpg',
        ]);
        $this->chatImages()->put('chats/'.$match->getKey().'/foto.jpg', 'imagen');

        Swipe::factory()->create(['swiper_id' => $this->ana->getKey(), 'swiped_id' => $this->luis->getKey()]);
        DB::table('blocks')->insert(['blocker_id' => $this->luis->getKey(), 'blocked_id' => $this->ana->getKey(), 'created_at' => now()]);
        $reportByAna = Report::factory()->against($this->luis)->by($this->ana)->create();
        Report::factory()->against($this->ana)->by($this->luis)->create();
        $this->ana->createToken('celular');

        Sanctum::actingAs($this->ana);

        $this->deleteJson('/api/user', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('message', 'Eliminamos tu cuenta y tus datos.');

        $anaId = $this->ana->getKey();
        $this->assertDatabaseMissing('users', ['id' => $anaId]);
        $this->assertDatabaseMissing('profiles', ['user_id' => $anaId]);
        $this->assertDatabaseMissing('photos', ['id' => $photo->getKey()]);
        $this->assertDatabaseMissing('matches', ['id' => $match->getKey()]);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('swipes', 0);
        $this->assertDatabaseCount('blocks', 0);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $anaId]);
        $this->photos()->assertMissing('profiles/ana/1.jpg');
        $this->chatImages()->assertMissing('chats/'.$match->getKey().'/foto.jpg');

        // Luis sigue igual. El reporte que hizo Ana queda sin autor; el reporte contra ella se borra.
        $this->assertDatabaseHas('users', ['id' => $this->luis->getKey()]);
        $this->assertNull($reportByAna->fresh()->reporter_id);
        $this->assertSame(1, Report::query()->count());
    }

    public function test_deleting_the_account_asks_for_the_password(): void
    {
        Sanctum::actingAs($this->ana);

        $this->deleteJson('/api/user', ['password' => 'otra'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'La contraseña no es correcta.']);

        $this->deleteJson('/api/user')->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $this->ana->getKey()]);
    }

    public function test_staff_cannot_delete_their_own_account_from_settings(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        Sanctum::actingAs($moderator);

        $this->deleteJson('/api/user', ['password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Las cuentas de staff se dan de baja desde la gestión de staff.');

        $this->assertDatabaseHas('users', ['id' => $moderator->getKey()]);
    }

    public function test_groups_keep_an_admin_and_groups_left_empty_are_deleted(): void
    {
        $shared = Group::factory()->approved()->create(['university_id' => $this->utt->getKey()]);
        $shared->members()->attach($this->ana->getKey(), ['role' => 'admin', 'created_at' => now()->subDay()]);
        $shared->members()->attach($this->luis->getKey(), ['role' => 'member', 'created_at' => now()]);

        $alone = Group::factory()->approved()->create([
            'university_id' => $this->utt->getKey(),
            'image_path' => 'groups/solo.jpg',
        ]);
        $alone->members()->attach($this->ana->getKey(), ['role' => 'admin']);
        $this->groupImages()->put('groups/solo.jpg', 'imagen');

        Sanctum::actingAs($this->ana);
        $this->deleteJson('/api/user', ['password' => 'password'])->assertOk();

        $this->assertSame('admin', $shared->roleOf($this->luis)?->value);
        $this->assertDatabaseMissing('groups', ['id' => $alone->getKey()]);
        $this->groupImages()->assertMissing('groups/solo.jpg');
    }

    public function test_a_moderator_deletes_an_account_with_a_report_and_it_stays_in_the_log(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $report = Report::factory()->against($this->ana)->by($this->luis)->create();
        Sanctum::actingAs($moderator);

        $this->deleteJson('/api/admin/users/'.$this->ana->getKey(), ['reason' => 'Acoso reiterado.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id' => 'Indica el reporte que justifica la eliminación.']);

        $this->deleteJson('/api/admin/users/'.$this->ana->getKey(), ['reason' => 'Acoso reiterado.', 'report_id' => $report->getKey()])
            ->assertOk()
            ->assertJsonPath('message', 'Eliminamos la cuenta y sus datos.');

        $this->assertDatabaseMissing('users', ['id' => $this->ana->getKey()]);

        $action = ModerationAction::query()->sole();
        $this->assertSame(ModerationActionType::DeleteUser, $action->action);
        $this->assertSame($this->ana->getKey(), $action->target_id);
        $this->assertSame($moderator->getKey(), $action->actor_id);
        // El reporte se borra con la cuenta; su id queda en el motivo.
        $this->assertSame('Acoso reiterado. (reporte #'.$report->getKey().')', $action->reason);
    }

    public function test_the_panel_only_deletes_students_within_reach(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        $admin = User::factory()->admin()->create();
        $foreign = User::factory()->student(University::factory()->create())->create();
        $otherModerator = User::factory()->moderator($this->utt)->create();

        // Un estudiante de otra universidad no existe para el moderador.
        Sanctum::actingAs($moderator);
        $this->deleteJson('/api/admin/users/'.$foreign->getKey(), ['reason' => 'Spam.', 'report_id' => 1])->assertNotFound();

        // El reporte tiene que ser sobre esa cuenta.
        $other = Report::factory()->against($this->luis)->by($this->ana)->create();
        $this->deleteJson('/api/admin/users/'.$this->ana->getKey(), ['reason' => 'Spam.', 'report_id' => $other->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['report_id' => 'El reporte no es sobre esta cuenta.']);

        // El administrador no necesita reporte, pero el staff no se borra desde aquí.
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/admin/users/'.$otherModerator->getKey(), ['reason' => 'Baja.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user' => 'Las cuentas de staff se desactivan desde la gestión de staff.']);
        $this->deleteJson('/api/admin/users/'.$foreign->getKey(), ['reason' => 'Cuenta falsa.'])->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $foreign->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $otherModerator->getKey()]);
        $this->assertDatabaseHas('users', ['id' => $this->ana->getKey()]);

        // Un estudiante no entra al panel.
        Sanctum::actingAs($this->luis);
        $this->deleteJson('/api/admin/users/'.$this->ana->getKey(), ['reason' => 'x'])->assertForbidden();
    }

    public function test_files_that_could_not_be_deleted_make_the_job_retry(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->with('profiles/1.jpg')->andReturn(false);
        $disk->shouldReceive('exists')->with('profiles/1.jpg')->andReturn(true);
        $disk->shouldReceive('delete')->with('profiles/2.jpg')->andReturn(false);
        $disk->shouldReceive('exists')->with('profiles/2.jpg')->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('1 archivo(s) del disco s3 no se pudieron borrar.');

        (new DeleteStoredFiles('s3', ['profiles/1.jpg', 'profiles/2.jpg']))->handle();
    }

    private function photos(): Filesystem
    {
        return Storage::disk(config('filesystems.profile_photos_disk'));
    }

    private function chatImages(): Filesystem
    {
        return Storage::disk(config('filesystems.chat_images_disk'));
    }

    private function groupImages(): Filesystem
    {
        return Storage::disk(config('filesystems.group_images_disk'));
    }
}
