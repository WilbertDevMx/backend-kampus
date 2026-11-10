<?php

namespace Tests\Unit;

use App\Support\Location\Geohash;
use PHPUnit\Framework\TestCase;

class GeohashTest extends TestCase
{
    public function test_it_encodes_and_decodes_the_reference_example(): void
    {
        $this->assertSame('u4pruydqqvj', Geohash::encode(57.64911, 10.40744, 11));

        $center = Geohash::center('u4pruydqqvj');
        $this->assertEqualsWithDelta(57.64911, $center['lat'], 0.0001);
        $this->assertEqualsWithDelta(10.40744, $center['lng'], 0.0001);
    }

    public function test_the_center_of_a_cell_encodes_back_to_the_same_cell(): void
    {
        $cell = Geohash::encode(25.5428, -103.4068, 6);
        $center = Geohash::center($cell);

        $this->assertSame($cell, Geohash::encode($center['lat'], $center['lng'], 6));
    }

    public function test_a_cell_has_eight_distinct_neighbors_around_it(): void
    {
        $cell = Geohash::encode(25.5428, -103.4068, 5);
        $cells = Geohash::withNeighbors($cell);
        $bounds = Geohash::bounds($cell);
        $center = Geohash::center($cell);

        $this->assertCount(9, $cells);
        $this->assertContains($cell, $cells);

        foreach ($cells as $neighbor) {
            $this->assertSame(5, strlen($neighbor));
            $neighborCenter = Geohash::center($neighbor);
            $this->assertLessThanOrEqual(1.01 * ($bounds['max_lat'] - $bounds['min_lat']), abs($neighborCenter['lat'] - $center['lat']));
            $this->assertLessThanOrEqual(1.01 * ($bounds['max_lng'] - $bounds['min_lng']), abs($neighborCenter['lng'] - $center['lng']));
        }
    }

    public function test_it_rejects_characters_outside_the_geohash_alphabet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Geohash::bounds('9u5fai');
    }

    public function test_distance_uses_the_haversine_formula(): void
    {
        $this->assertEqualsWithDelta(111.19, Geohash::distanceKm(0, 0, 0, 1), 0.01);
        $this->assertSame(0.0, Geohash::distanceKm(25.5, -103.4, 25.5, -103.4));
    }
}
