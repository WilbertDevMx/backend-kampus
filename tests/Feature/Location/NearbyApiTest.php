<?php

namespace Tests\Feature\Location;

use App\Models\Swipe;
use App\Models\University;
use App\Models\User;
use App\Models\UserLocation;
use App\Models\Zone;
use App\Support\Location\Geohash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Discovery\CreatesStudents;
use Tests\TestCase;

/**
 * Vista "Cerca" (T-35, RF-19, RNF-11).
 */
class NearbyApiTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    /** Un punto de Torreón; los demás se ubican a partir de aquí. */
    private const LAT = 25.5428;

    private const LNG = -103.4068;

    private University $utt;

    private University $uadec;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
        $this->uadec = University::factory()->create(['short_name' => 'UAdeC']);
        $this->zone = Zone::factory()->create(['center_lat' => 25.54, 'center_lng' => -103.41]);
    }

    public function test_only_a_student_who_shares_their_cell_can_see_who_is_nearby(): void
    {
        $withoutNearby = $this->studentWithProfile($this->utt, ['nearby_enabled' => false]);
        Sanctum::actingAs($withoutNearby);

        $this->getJson('/api/discover/nearby')
            ->assertForbidden()
            ->assertJson(['code' => 'nearby_disabled']);

        // Activo en el perfil pero sin celda guardada: tampoco.
        $withoutCell = $this->studentWithProfile($this->utt, ['nearby_enabled' => true]);
        Sanctum::actingAs($withoutCell);

        $this->getJson('/api/discover/nearby')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/discover/nearby')->assertForbidden();
    }

    public function test_it_lists_nearby_students_by_approximate_distance(): void
    {
        $viewer = $this->nearbyStudent(self::LAT, self::LNG);
        $threeKm = $this->nearbyStudent(self::LAT + 0.03, self::LNG);
        $sameBlock = $this->nearbyStudent(self::LAT + 0.003, self::LNG);
        $this->nearbyStudent(25.4232, -101.0053); // Saltillo, a más de 200 km.
        $this->studentWithProfile($this->utt, ['nearby_enabled' => false, 'zone_id' => $this->zone->id]);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/discover/nearby')->assertOk();

        $this->assertSame([$sameBlock->id, $threeKm->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.nearby.distance_km', 0)
            ->assertJsonPath('data.0.nearby.distance_label', 'Menos de 1 km')
            ->assertJsonPath('meta.radius_km', 10);
        $this->assertContains($response->json('data.1.nearby.distance_km'), [2, 3, 4]);
        $this->assertSame('≈ '.$response->json('data.1.nearby.distance_km').' km', $response->json('data.1.nearby.distance_label'));
        $this->assertSame(['lat', 'lng'], array_keys($response->json('meta.center')));
    }

    public function test_it_never_exposes_a_cell_or_a_real_coordinate(): void
    {
        $viewer = $this->nearbyStudent(self::LAT, self::LNG);
        $withZone = $this->nearbyStudent(self::LAT + 0.01, self::LNG + 0.01);
        $withoutZone = $this->nearbyStudent(self::LAT - 0.01, self::LNG - 0.01, zone: false);
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/discover/nearby')->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('geohash', $body);
        $this->assertStringNotContainsString($withZone->location->geohash, $body);
        $this->assertStringNotContainsString($withoutZone->location->geohash, $body);

        $positions = collect($response->json('data'))->mapWithKeys(fn (array $user) => [$user['id'] => $user['nearby']['position']]);

        // En el centro de su zona, con un desplazamiento de 150 a 500 m.
        $fromZone = Geohash::distanceKm($this->zone->center_lat, $this->zone->center_lng, $positions[$withZone->id]['lat'], $positions[$withZone->id]['lng']);
        $this->assertGreaterThanOrEqual(0.14, $fromZone);
        $this->assertLessThanOrEqual(0.51, $fromZone);

        // Sin zona: alrededor del centro de su celda de ~5 km, no de la de ~1 km.
        $coarse = Geohash::center(substr($withoutZone->location->geohash, 0, 5));
        $fromCoarse = Geohash::distanceKm($coarse['lat'], $coarse['lng'], $positions[$withoutZone->id]['lat'], $positions[$withoutZone->id]['lng']);
        $this->assertGreaterThanOrEqual(0.14, $fromCoarse);
        $this->assertLessThanOrEqual(0.51, $fromCoarse);

        // El desplazamiento es fijo: el punto no cambia entre consultas.
        $this->assertSame($response->json('data'), $this->getJson('/api/discover/nearby')->json('data'));
    }

    public function test_blocked_and_already_evaluated_students_do_not_appear(): void
    {
        $viewer = $this->nearbyStudent(self::LAT, self::LNG);
        $blockedByViewer = $this->nearbyStudent(self::LAT + 0.004, self::LNG);
        $blockedViewer = $this->nearbyStudent(self::LAT + 0.005, self::LNG);
        $evaluated = $this->nearbyStudent(self::LAT + 0.006, self::LNG);
        $visible = $this->nearbyStudent(self::LAT + 0.007, self::LNG);
        DB::table('blocks')->insert([
            ['blocker_id' => $viewer->id, 'blocked_id' => $blockedByViewer->id],
            ['blocker_id' => $blockedViewer->id, 'blocked_id' => $viewer->id],
        ]);
        Swipe::factory()->create(['swiper_id' => $viewer->id, 'swiped_id' => $evaluated->id]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover/nearby')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_it_applies_the_saved_discovery_filters_and_the_limit(): void
    {
        $viewer = $this->nearbyStudent(self::LAT, self::LNG, profile: ['discovery_filters' => ['universities' => [$this->uadec->id]]]);
        $this->nearbyStudent(self::LAT + 0.002, self::LNG);
        $fromUadec = $this->nearbyStudent(self::LAT + 0.004, self::LNG, university: $this->uadec);
        $this->nearbyStudent(self::LAT + 0.006, self::LNG, university: $this->uadec);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/discover/nearby?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fromUadec->id);
        $this->getJson('/api/discover/nearby?limit=51')->assertInvalid(['limit']);
    }

    /**
     * Estudiante con "Cerca de ti" activo y la celda de ~1 km que mandaría su celular.
     *
     * @param  array<string, mixed>  $profile
     */
    private function nearbyStudent(float $lat, float $lng, bool $zone = true, array $profile = [], ?University $university = null): User
    {
        $user = $this->studentWithProfile($university ?? $this->utt, [
            'nearby_enabled' => true,
            'zone_id' => $zone ? $this->zone->id : null,
            ...$profile,
        ]);
        UserLocation::query()->create(['user_id' => $user->id, 'geohash' => Geohash::encode($lat, $lng, 6)]);

        return $user->fresh();
    }
}
