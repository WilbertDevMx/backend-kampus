<?php

namespace Tests\Feature\Moderation;

use App\Models\Report;
use App\Models\University;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Discovery\CreatesStudents;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    private University $utt;

    private University $uadec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create();
        $this->uadec = University::factory()->create();
    }

    public function test_a_report_goes_to_the_university_of_the_reported_student(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->uadec);
        Sanctum::actingAs($ana);

        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'spam'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.blocked', false)
            ->assertJsonPath('message', 'Recibimos tu reporte. Lo revisará el equipo de moderación.');

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $ana->id,
            'reported_id' => $luis->id,
            'reason' => 'spam',
            'status' => 'pending',
            'university_id' => $this->uadec->id,
        ]);
        $this->assertDatabaseCount('blocks', 0);
    }

    public function test_a_report_can_block_in_the_same_step_and_attach_the_conversation(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        $match = UserMatch::factory()->between($ana, $luis)->create();
        Sanctum::actingAs($ana);

        $this->postJson('/api/reports', [
            'user_id' => $luis->id,
            'reason' => 'acoso',
            'description' => 'Mensajes ofensivos en el chat.',
            'match_id' => $match->id,
            'block' => true,
        ])->assertCreated()->assertJsonPath('data.blocked', true);

        $this->assertDatabaseHas('reports', ['reported_id' => $luis->id, 'match_id' => $match->id]);
        $this->assertDatabaseHas('blocks', ['blocker_id' => $ana->id, 'blocked_id' => $luis->id]);
    }

    public function test_the_evidence_must_be_a_conversation_between_both(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        $sofia = $this->studentWithProfile($this->utt);
        $foreign = UserMatch::factory()->between($luis, $sofia)->create();
        Sanctum::actingAs($ana);

        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'spam', 'match_id' => $foreign->id])
            ->assertInvalid(['match_id' => 'La conversación no es entre ustedes dos.']);

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_the_report_payload_is_validated(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        $moderator = User::factory()->moderator($this->utt)->create();
        Sanctum::actingAs($ana);

        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'me_cae_mal'])->assertInvalid(['reason']);
        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'otro'])->assertInvalid(['description']);
        $this->postJson('/api/reports', ['user_id' => $ana->id, 'reason' => 'spam'])->assertInvalid(['user_id']);
        $this->postJson('/api/reports', ['user_id' => $moderator->id, 'reason' => 'spam'])->assertInvalid(['user_id']);

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_the_same_person_cannot_be_reported_twice_while_the_report_is_pending(): void
    {
        $ana = $this->studentWithProfile($this->utt);
        $luis = $this->studentWithProfile($this->utt);
        Sanctum::actingAs($ana);

        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'spam'])->assertCreated();
        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'acoso'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ya reportaste a esta persona y tu reporte está en revisión.');

        Report::query()->firstOrFail()->forceFill(['status' => 'dismissed'])->save();
        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'acoso'])->assertCreated();

        $this->assertDatabaseCount('reports', 2);
    }

    public function test_staff_cannot_report(): void
    {
        $luis = $this->studentWithProfile($this->utt);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/reports', ['user_id' => $luis->id, 'reason' => 'spam'])->assertForbidden();
    }
}
