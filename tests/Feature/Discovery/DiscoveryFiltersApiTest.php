<?php

namespace Tests\Feature\Discovery;

use App\Models\Interest;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscoveryFiltersApiTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create();
    }

    public function test_filters_default_to_none_for_a_student_without_profile(): void
    {
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        $this->getJson('/api/discover/filters')
            ->assertOk()
            ->assertExactJson(['data' => ['universities' => [], 'career' => null, 'interests' => []]]);
    }

    public function test_filters_are_saved_normalized_and_persist_between_sessions(): void
    {
        $uadec = University::factory()->create();
        $interests = Interest::factory()->count(2)->create();
        $student = $this->studentWithProfile($this->utt);
        Sanctum::actingAs($student);

        $expected = [
            'universities' => [$this->utt->id, $uadec->id],
            'career' => 'Medicina',
            'interests' => $interests->pluck('id')->all(),
        ];

        $this->putJson('/api/discover/filters', [
            'universities' => [$this->utt->id, $uadec->id],
            'career' => '  Medicina ',
            'interests' => $interests->pluck('id')->all(),
            'role' => 'admin',
        ])->assertOk()->assertExactJson(['data' => $expected]);

        $this->assertSame($expected, $student->profile->fresh()->discovery_filters);
        $this->getJson('/api/discover/filters')->assertExactJson(['data' => $expected]);
    }

    public function test_saving_filters_replaces_the_previous_ones(): void
    {
        $student = $this->studentWithProfile($this->utt, [
            'discovery_filters' => ['universities' => [$this->utt->id], 'career' => 'Medicina', 'interests' => []],
        ]);
        Sanctum::actingAs($student);

        $this->putJson('/api/discover/filters', ['career' => 'Derecho'])
            ->assertOk()
            ->assertExactJson(['data' => ['universities' => [], 'career' => 'Derecho', 'interests' => []]]);
    }

    public function test_filters_reject_inactive_universities_and_unknown_interests(): void
    {
        $inactive = University::factory()->create(['is_active' => false]);
        Sanctum::actingAs($this->studentWithProfile($this->utt));

        $this->putJson('/api/discover/filters', [
            'universities' => [$inactive->id],
            'interests' => [999999],
            'career' => str_repeat('a', 121),
        ])->assertInvalid(['universities.0', 'interests.0', 'career']);

        $this->putJson('/api/discover/filters', ['universities' => 'UTT'])->assertInvalid(['universities']);
        $this->putJson('/api/discover/filters', ['universities' => ['UTT'], 'interests' => ['música']])
            ->assertInvalid(['universities.0', 'interests.0']);
    }

    public function test_the_profile_update_validates_discovery_filters_the_same_way(): void
    {
        $student = $this->studentWithProfile($this->utt);
        Sanctum::actingAs($student);

        $this->patchJson('/api/profile', ['discovery_filters' => ['universities' => [999999]]])
            ->assertInvalid(['discovery_filters.universities.0']);
        $this->patchJson('/api/profile', ['discovery_filters' => ['gender' => 'f']])
            ->assertInvalid(['discovery_filters']);

        $this->patchJson('/api/profile', ['discovery_filters' => ['career' => ' Derecho ']])
            ->assertOk()
            ->assertJsonPath('data.profile.discovery_filters', [
                'universities' => [],
                'career' => 'Derecho',
                'interests' => [],
            ]);

        $this->patchJson('/api/profile', ['discovery_filters' => null])
            ->assertOk()
            ->assertJsonPath('data.profile.discovery_filters', null);
    }
}
