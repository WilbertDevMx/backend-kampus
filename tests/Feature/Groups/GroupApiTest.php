<?php

namespace Tests\Feature\Groups;

use App\Models\Group;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupApiTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    private University $uadec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC']);
    }

    public function test_a_student_creates_a_group_that_waits_for_review(): void
    {
        $ana = User::factory()->student($this->utt)->create();
        Sanctum::actingAs($ana);

        $response = $this->postJson('/api/groups', [
            'name' => 'Club de Fotografía',
            'description' => 'Salidas a fotografiar el campus.',
            'category' => 'cultural',
            'status' => 'approved',
            'is_official' => true,
            'university_id' => $this->uadec->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Club de Fotografía')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_official', false)
            ->assertJsonPath('data.university.short_name', 'UTT')
            ->assertJsonPath('data.my_role', 'admin')
            ->assertJsonPath('data.members_count', 1);

        $this->assertDatabaseHas('groups', [
            'id' => $response->json('data.id'),
            'status' => 'pending',
            'is_official' => false,
            'university_id' => $this->utt->id,
            'created_by' => $ana->id,
        ]);
        $this->assertDatabaseHas('group_members', ['group_id' => $response->json('data.id'), 'user_id' => $ana->id, 'role' => 'admin']);
    }

    public function test_creating_a_group_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        $this->postJson('/api/groups', [])->assertInvalid(['name', 'category']);
        $this->postJson('/api/groups', ['name' => 'Club', 'category' => 'otro'])->assertInvalid(['category']);
        $this->postJson('/api/groups', ['name' => str_repeat('a', 121), 'category' => 'social'])->assertInvalid(['name']);

        $this->assertDatabaseCount('groups', 0);
    }

    public function test_exploring_lists_only_approved_groups_with_filters_and_official_first(): void
    {
        $sports = Group::factory()->approved()->create(['university_id' => $this->utt->id, 'category' => 'deportivo', 'name' => 'Atletismo']);
        $official = Group::factory()->official()->create(['university_id' => $this->utt->id, 'category' => 'deportivo', 'name' => 'Zumba']);
        $otherUniversity = Group::factory()->approved()->create(['university_id' => $this->uadec->id, 'category' => 'cultural']);
        Group::factory()->create(['university_id' => $this->utt->id]);
        Group::factory()->rejected()->create(['university_id' => $this->utt->id]);
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        $this->assertSame(
            [$official->id, $sports->id, $otherUniversity->id],
            $this->getJson('/api/groups')->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$official->id, $sports->id],
            $this->getJson("/api/groups?university_id={$this->utt->id}")->json('data.*.id'),
        );
        $this->assertSame(
            [$otherUniversity->id],
            $this->getJson('/api/groups?category=cultural')->json('data.*.id'),
        );
        $this->getJson('/api/groups?category=otro')->assertInvalid(['category']);
    }

    public function test_a_group_in_review_is_only_visible_to_its_members(): void
    {
        $ana = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->create();

        Sanctum::actingAs($ana);
        $this->getJson("/api/groups/{$group->id}")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/groups/mine')->assertOk()->assertJsonPath('data.0.id', $group->id);

        Sanctum::actingAs(User::factory()->student($this->utt)->create());
        $this->getJson("/api/groups/{$group->id}")->assertNotFound();
        $this->getJson('/api/groups')->assertJsonCount(0, 'data');
    }

    public function test_only_group_admins_see_the_rejection_reason(): void
    {
        $ana = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->rejected('El nombre no es apropiado.')->create();

        Sanctum::actingAs($ana);
        $this->getJson("/api/groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'El nombre no es apropiado.');
    }

    public function test_group_admins_edit_the_group_without_sending_it_back_to_review(): void
    {
        $ana = User::factory()->student($this->utt)->create();
        $member = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->approved()->create(['name' => 'Club']);
        $group->members()->attach($member->id, ['role' => 'member']);

        Sanctum::actingAs($member);
        $this->patchJson("/api/groups/{$group->id}", ['name' => 'Otro'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo los administradores del grupo pueden hacerlo.');

        Sanctum::actingAs($ana);
        $this->patchJson("/api/groups/{$group->id}", ['name' => 'Club de Ajedrez', 'category' => 'academico', 'status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Club de Ajedrez')
            ->assertJsonPath('data.category', 'academico')
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_group_admins_delete_the_group_and_its_image(): void
    {
        Storage::fake('s3');
        $ana = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->approved()->create(['image_path' => 'groups/1/vieja.jpg']);
        Storage::disk('s3')->put('groups/1/vieja.jpg', 'imagen');

        Sanctum::actingAs(User::factory()->student($this->utt)->create());
        $this->deleteJson("/api/groups/{$group->id}")->assertForbidden();

        Sanctum::actingAs($ana);
        $this->deleteJson("/api/groups/{$group->id}")->assertNoContent();

        $this->assertModelMissing($group);
        $this->assertDatabaseCount('group_members', 0);
        Storage::disk('s3')->assertMissing('groups/1/vieja.jpg');
    }

    public function test_group_admins_upload_a_clean_image(): void
    {
        Storage::fake('s3');
        $ana = User::factory()->student($this->utt)->create();
        $group = Group::factory()->createdBy($ana)->create();
        Sanctum::actingAs($ana);

        $response = $this->postJson("/api/groups/{$group->id}/image", [
            'image' => UploadedFile::fake()->createWithContent('club.png', $this->validPng()),
        ])->assertOk();

        $path = $group->fresh()->image_path;
        $this->assertStringStartsWith("groups/{$group->id}/", $path);
        Storage::disk('s3')->assertExists($path);
        $this->assertNotEmpty($response->json('data.image_url'));

        $this->postJson("/api/groups/{$group->id}/image", [
            'image' => UploadedFile::fake()->createWithContent('roto.png', 'no es una imagen'),
        ])->assertInvalid(['image']);
    }

    public function test_staff_cannot_use_the_student_group_routes(): void
    {
        $group = Group::factory()->approved()->create(['university_id' => $this->utt->id]);

        foreach ([User::factory()->moderator($this->utt)->create(), User::factory()->admin()->create()] as $staff) {
            Sanctum::actingAs($staff);

            $this->getJson('/api/groups')->assertForbidden();
            $this->postJson('/api/groups', ['name' => 'Club', 'category' => 'social'])->assertForbidden();
            $this->postJson("/api/groups/{$group->id}/members")->assertForbidden();
        }
    }

    private function validPng(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
