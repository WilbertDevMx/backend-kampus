<?php

namespace Tests\Feature\Location;

use App\Models\Profile;
use App\Models\University;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student(University::factory()->create())->create();
    }

    public function test_the_zone_catalog_lists_campuses_first_and_can_be_filtered(): void
    {
        $this->seed([CatalogSeeder::class, ZoneSeeder::class]);
        $utt = University::query()->where('short_name', 'UTT')->firstOrFail();
        Sanctum::actingAs($this->student);

        $zones = $this->getJson('/api/zones')->assertOk()->json('data');

        $this->assertCount(9, $zones);
        $this->assertSame(['campus', 'campus', 'campus'], array_slice(array_column($zones, 'type'), 0, 3));
        $this->assertSame(['lat', 'lng'], array_keys($zones[0]['center']));
        $this->getJson('/api/zones?type=colonia')->assertJsonCount(6, 'data');
        $this->getJson("/api/zones?university_id={$utt->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Campus UTT');
        $this->getJson('/api/zones?type=pais')->assertInvalid(['type']);
    }

    public function test_the_zone_seeder_can_run_twice(): void
    {
        $this->seed([CatalogSeeder::class, ZoneSeeder::class, ZoneSeeder::class]);

        $this->assertDatabaseCount('zones', 9);
    }

    public function test_a_student_declares_and_removes_their_zone(): void
    {
        $zone = Zone::factory()->create(['name' => 'Torreón Jardín']);
        Sanctum::actingAs($this->student);

        $this->getJson('/api/location')
            ->assertOk()
            ->assertExactJson(['data' => ['zone' => null, 'nearby_enabled' => false, 'geohash' => null]]);

        $this->putJson('/api/location/zone', ['zone_id' => $zone->id])
            ->assertOk()
            ->assertJsonPath('data.zone.name', 'Torreón Jardín');
        $this->assertDatabaseHas('profiles', ['user_id' => $this->student->id, 'zone_id' => $zone->id]);

        $this->putJson('/api/location/zone', ['zone_id' => null])
            ->assertOk()
            ->assertJsonPath('data.zone', null);
        $this->putJson('/api/location/zone', ['zone_id' => 999999])->assertInvalid(['zone_id']);
        $this->putJson('/api/location/zone', [])->assertInvalid(['zone_id']);
    }

    public function test_activating_nearby_stores_only_an_approximate_cell(): void
    {
        Sanctum::actingAs($this->student);

        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbj'])
            ->assertOk()
            ->assertJsonPath('data.nearby_enabled', true)
            ->assertJsonPath('data.geohash', '9u5fbj');
        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbm'])->assertOk();

        $this->assertDatabaseCount('user_locations', 1);
        $this->assertDatabaseHas('user_locations', ['user_id' => $this->student->id, 'geohash' => '9u5fbm']);
        $this->assertDatabaseHas('profiles', ['user_id' => $this->student->id, 'nearby_enabled' => true]);
    }

    public function test_precise_cells_and_coordinates_are_rejected(): void
    {
        Sanctum::actingAs($this->student);

        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbjx2'])
            ->assertInvalid(['geohash' => 'La celda debe ser un geohash de 6 caracteres.']);
        $this->putJson('/api/location/nearby', ['geohash' => '9u5fba'])->assertInvalid(['geohash']);
        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbj', 'lat' => 25.5428, 'lng' => -103.4068])
            ->assertInvalid(['lat', 'lng']);
        $this->putJson('/api/location/zone', ['zone_id' => null, 'latitude' => 25.5428, 'longitude' => -103.4068])
            ->assertInvalid(['latitude', 'longitude']);

        $this->assertDatabaseCount('user_locations', 0);
    }

    public function test_deactivating_nearby_deletes_the_cell(): void
    {
        Sanctum::actingAs($this->student);
        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbj'])->assertOk();

        $this->deleteJson('/api/location/nearby')
            ->assertOk()
            ->assertExactJson(['data' => ['zone' => null, 'nearby_enabled' => false, 'geohash' => null]]);

        $this->assertDatabaseCount('user_locations', 0);
        $this->assertDatabaseHas('profiles', ['user_id' => $this->student->id, 'nearby_enabled' => false]);
    }

    public function test_the_profile_update_cannot_turn_nearby_on_or_off(): void
    {
        Profile::factory()->for($this->student)->create();
        Sanctum::actingAs($this->student);
        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbj'])->assertOk();

        $this->patchJson('/api/profile', ['nearby_enabled' => false])->assertStatus(422);

        $this->assertDatabaseHas('user_locations', ['user_id' => $this->student->id]);
        $this->assertDatabaseHas('profiles', ['user_id' => $this->student->id, 'nearby_enabled' => true]);
    }

    public function test_deleting_the_account_deletes_the_cell(): void
    {
        Sanctum::actingAs($this->student);
        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbj'])->assertOk();

        $this->student->delete();

        $this->assertDatabaseCount('user_locations', 0);
    }

    public function test_staff_cannot_use_the_location_routes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/zones')->assertForbidden();
        $this->putJson('/api/location/nearby', ['geohash' => '9u5fbj'])->assertForbidden();
    }
}
