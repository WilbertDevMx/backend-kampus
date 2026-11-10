<?php

namespace Tests\Feature\Database;

use App\Models\Photo;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserLocation;
use App\Models\UserMatch;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Datos de demostración (T-34).
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['filesystems.profile_photos_disk' => 's3', 'kampus.demo.password' => 'Demo-Kampus-2026']);
        $this->seed([CatalogSeeder::class, ZoneSeeder::class]);
    }

    public function test_it_creates_at_least_60_complete_students_across_the_three_universities(): void
    {
        $this->seed(DemoSeeder::class);

        $students = $this->demoStudents()->with(['university', 'profile.interests', 'profile.photos'])->get();

        $this->assertGreaterThanOrEqual(60, $students->count());
        $this->assertEqualsCanonicalizing(['UTT', 'UAdeC', 'ITL'], $students->pluck('university.short_name')->unique()->values()->all());

        foreach ($students as $student) {
            $this->assertNotNull($student->email_verified_at);
            $this->assertSame('student', $student->role->value);
            $this->assertNotNull($student->profile->career);
            $this->assertNotNull($student->profile->semester);
            $this->assertNotNull($student->profile->bio);
            $this->assertNotNull($student->profile->zone_id);
            $this->assertGreaterThanOrEqual(18, $student->profile->birthdate->age);
            $this->assertGreaterThanOrEqual(3, $student->profile->interests->count());
            $this->assertGreaterThanOrEqual(1, $student->profile->photos->count());
        }

        // Las fotos existen en el disco privado y son PNG generados, no fotos de terceros.
        $photo = Photo::query()->firstOrFail();
        Storage::disk('s3')->assertExists($photo->path);
        $this->assertStringStartsWith("\x89PNG", Storage::disk('s3')->get($photo->path));
    }

    public function test_about_half_share_their_cell_and_only_those_with_nearby_enabled(): void
    {
        $this->seed(DemoSeeder::class);

        $withNearby = $this->demoStudents()->whereHas('profile', fn ($profile) => $profile->where('nearby_enabled', true))->count();
        $total = $this->demoStudents()->count();

        $this->assertGreaterThan($total * 0.3, $withNearby);
        $this->assertLessThan($total * 0.8, $withNearby);
        $this->assertSame($withNearby, UserLocation::query()->count());
        $this->assertTrue(UserLocation::query()->pluck('geohash')->every(fn (string $cell) => strlen($cell) === 6));
    }

    public function test_the_presenter_account_has_matches_and_pending_likes(): void
    {
        $this->seed(DemoSeeder::class);

        $presenter = User::query()->where('email', DemoSeeder::PRESENTER_EMAIL)->firstOrFail();

        $this->assertSame(3, UserMatch::query()->involving($presenter)->count());
        $this->assertSame(8, Swipe::query()->where('swiped_id', $presenter->id)->where('liked', true)
            ->whereNotIn('swiper_id', Swipe::query()->where('swiper_id', $presenter->id)->select('swiped_id'))
            ->count());

        // Las 8 personas que ya le dieron "Me interesa" salen en su descubrimiento.
        Sanctum::actingAs($presenter);
        $this->assertCount(20, $this->getJson('/api/discover?limit=20')->assertOk()->json('data'));
        $this->getJson('/api/matches')->assertOk()->assertJsonCount(3, 'data');

        // Vive en una colonia céntrica: la vista Cerca tiene a quién mostrar.
        $this->assertGreaterThanOrEqual(15, count($this->getJson('/api/discover/nearby?limit=50')->assertOk()->json('data')));
    }

    public function test_it_can_run_twice_without_duplicating_or_touching_other_users(): void
    {
        $other = User::factory()->create(['email' => 'alguien@utt.edu.mx']);

        $this->seed(DemoSeeder::class);
        $first = $this->demoStudents()->count();
        $firstPhotos = Photo::query()->pluck('path');

        $this->seed(DemoSeeder::class);

        $this->assertSame($first, $this->demoStudents()->count());
        $this->assertSame($first, User::query()->where('email', 'like', '%@'.DemoSeeder::EMAIL_DOMAIN)->count());
        $this->assertModelExists($other);
        // Las fotos de la corrida anterior se borraron del disco.
        Storage::disk('s3')->assertMissing($firstPhotos->first());
    }

    public function test_it_never_runs_in_production_unless_the_test_environment_enables_it(): void
    {
        $this->app['env'] = 'production';

        // En producción, db:seed pide --force; aun así, DemoSeeder se niega.
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertSame(0, $this->demoStudents()->count());

        config(['kampus.demo.enabled' => true]);
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertGreaterThanOrEqual(60, $this->demoStudents()->count());
    }

    private function demoStudents()
    {
        return User::query()->where('email', 'like', '%@'.DemoSeeder::EMAIL_DOMAIN);
    }
}
