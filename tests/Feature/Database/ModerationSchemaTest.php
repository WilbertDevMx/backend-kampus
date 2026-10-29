<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Columnas del módulo de Administración (T-02) según docs/base-de-datos/esquema.md, secciones 2.12 a 2.17.
 */
class ModerationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_and_reports_have_the_moderation_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('groups', [
            'status', 'is_official', 'reviewed_by', 'reviewed_at', 'rejection_reason',
        ]));
        $this->assertTrue(Schema::hasColumn('reports', 'university_id'));
    }

    public function test_moderation_actions_is_an_append_only_log(): void
    {
        $this->assertTrue(Schema::hasColumns('moderation_actions', [
            'id', 'actor_id', 'action', 'target_type', 'target_id', 'report_id', 'university_id', 'reason', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('moderation_actions', 'updated_at'));
    }

    public function test_group_members_and_blocks_now_have_timestamps(): void
    {
        $this->assertTrue(Schema::hasColumns('group_members', ['created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('blocks', ['created_at', 'updated_at']));
    }

    public function test_a_new_group_starts_pending_and_not_official(): void
    {
        $id = DB::table('groups')->insertGetId(['name' => 'Club de ajedrez', 'category' => 'cultural']);

        $group = DB::table('groups')->find($id);

        $this->assertSame('pending', $group->status);
        $this->assertFalse((bool) $group->is_official);
    }

    public function test_a_moderation_action_requires_a_reason(): void
    {
        $actor = User::factory()->create();
        $action = [
            'actor_id' => $actor->id,
            'action' => 'suspend_user',
            'target_type' => 'user',
            'target_id' => $actor->id,
        ];

        DB::table('moderation_actions')->insert([...$action, 'reason' => 'Acoso reportado por dos usuarios.']);
        $this->assertDatabaseCount('moderation_actions', 1);
        $this->assertNotNull(DB::table('moderation_actions')->value('created_at'));

        $this->expectException(QueryException::class);
        DB::table('moderation_actions')->insert($action);
    }

    public function test_the_moderation_migrations_can_be_rolled_back(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('moderation_actions'));
        $this->assertFalse(Schema::hasColumn('groups', 'status'));
        $this->assertFalse(Schema::hasColumn('reports', 'university_id'));
        $this->assertFalse(Schema::hasColumn('blocks', 'created_at'));
        $this->assertFalse(Schema::hasColumn('group_members', 'updated_at'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('moderation_actions'));
    }
}
