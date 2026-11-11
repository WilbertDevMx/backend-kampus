<?php

namespace Database\Seeders;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\Interest;
use App\Models\Photo;
use App\Models\Profile;
use App\Models\Swipe;
use App\Models\University;
use App\Models\User;
use App\Models\UserLocation;
use App\Models\UserMatch;
use App\Models\Zone;
use App\Support\Location\Geohash;
use Database\Seeders\Demo\DemoAvatar;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Datos de demostración (T-34, RF-05, RNF-04): estudiantes ficticios de las tres universidades, con perfil
 * completo, fotos, intereses, zona y, la mitad, "Cerca de ti" activo en Torreón. Además, una cuenta para
 * presentar con "Me interesa" pendientes y matches ya hechos.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * - Necesita los catálogos: CatalogSeeder y ZoneSeeder.
 * - Nunca corre con APP_ENV=production, salvo con DEMO_DATA_ENABLED=true (el entorno de pruebas).
 * - Se puede correr varias veces: borra los usuarios de demostración anteriores y sus fotos, y los vuelve a
 *   crear iguales (la semilla aleatoria es fija). No toca a ningún otro usuario.
 * - No usa Faker, que no se instala en la imagen de producción.
 */
class DemoSeeder extends Seeder
{
    /** Todos los correos de demostración usan este dominio reservado (.test nunca recibe correo). */
    public const EMAIL_DOMAIN = 'demo.kampus.test';

    /** Cuenta para presentar Kampus. */
    public const PRESENTER_EMAIL = 'demo@'.self::EMAIL_DOMAIN;

    public const STUDENTS_PER_UNIVERSITY = 22;

    /** Colonia céntrica de la cuenta para presentar (ZoneSeeder). */
    private const PRESENTER_ZONE = 'Torreón Jardín';

    private const SEED = 2026;

    private const FIRST_NAMES = [
        'Ana', 'Sofía', 'Valeria', 'Camila', 'Regina', 'Ximena', 'Daniela', 'Mariana', 'Fernanda', 'Andrea',
        'Paola', 'Renata', 'Natalia', 'Lucía', 'Itzel', 'Karla', 'Diana', 'Alejandra', 'Gabriela', 'Montserrat',
        'Diego', 'Santiago', 'Emiliano', 'Sebastián', 'Mateo', 'Leonardo', 'Daniel', 'Carlos', 'Luis', 'Jorge',
        'Ricardo', 'Fernando', 'Alejandro', 'Iván', 'Eduardo', 'Rodrigo', 'Andrés', 'Héctor', 'Óscar', 'Adrián',
    ];

    private const LAST_NAMES = [
        'López', 'García', 'Martínez', 'Hernández', 'González', 'Rodríguez', 'Pérez', 'Sánchez', 'Ramírez', 'Flores',
        'Torres', 'Rivera', 'Gómez', 'Díaz', 'Reyes', 'Morales', 'Cruz', 'Ortiz', 'Gutiérrez', 'Chávez',
        'Ramos', 'Ruiz', 'Mendoza', 'Aguilar', 'Castillo', 'Jiménez', 'Vázquez', 'Moreno', 'Romero', 'Herrera',
    ];

    /** Carreras de cada universidad. */
    private const CAREERS = [
        'UTT' => [
            'Ingeniería en Desarrollo y Gestión de Software',
            'Ingeniería en Tecnologías de la Información',
            'Ingeniería en Mecatrónica',
            'Ingeniería en Mantenimiento Industrial',
            'Licenciatura en Gestión de Negocios',
        ],
        'UAdeC' => [
            'Medicina',
            'Derecho',
            'Arquitectura',
            'Psicología',
            'Contaduría Pública',
            'Ingeniería Civil',
        ],
        'ITL' => [
            'Ingeniería en Sistemas Computacionales',
            'Ingeniería Industrial',
            'Ingeniería Electrónica',
            'Ingeniería Mecánica',
            'Ingeniería en Gestión Empresarial',
        ],
    ];

    private const BIOS = [
        'Me gusta el café de especialidad y platicar de cualquier cosa. Busco gente para estudiar en la biblioteca.',
        'Fan del fútbol y de los tacos de los viernes. Siempre armo reta en las canchas del campus.',
        'Leo de todo, sobre todo ciencia ficción. Si tienes una recomendación, cuéntame.',
        'Toco la guitarra y busco gente para tocar los fines de semana.',
        'Me encanta la fotografía de calle. Los domingos salgo a caminar por el centro con mi cámara.',
        'Gamer de tiempo completo cuando no hay parciales. Juego en línea casi todas las noches.',
        'Voy al gimnasio en las mañanas y me gusta salir a correr al Bosque Venustiano Carranza.',
        'Me gusta programar proyectos personales y aprender cosas nuevas. Busco equipo para hackatones.',
        'Viajar es lo mío: quiero conocer todo el norte del país antes de graduarme.',
        'Cinéfilo. Siempre voy al estreno del jueves y luego a discutir la película con quien quiera.',
        'Me gusta el anime y dibujar. Busco amigos para ir a las convenciones.',
        'Voluntario en un refugio de perros. Si te gustan los animales, nos vamos a llevar bien.',
        'Recién llegué a Torreón y quiero conocer gente y lugares nuevos.',
        'Me gustan los juegos de mesa. Organizo partidas en la cafetería los miércoles.',
        'Salgo a la sierra cada que puedo. Busco gente para ir de excursión.',
        'Escucho de todo, desde rock hasta regional. Siempre ando buscando conciertos.',
        'Estudio mucho, pero también me gusta salir. Busco amigos para el cine y para estudiar.',
        'Me gusta cocinar y probar restaurantes nuevos. Acepto recomendaciones de la Laguna.',
    ];

    private Randomizer $random;

    public function run(): void
    {
        if (app()->isProduction() && ! config('kampus.demo.enabled')) {
            $this->command?->error('DemoSeeder no corre en producción. En el entorno de pruebas, define DEMO_DATA_ENABLED=true.');

            return;
        }

        $universities = University::query()->whereIn('short_name', array_keys(self::CAREERS))->get()->keyBy('short_name');
        $zones = Zone::query()->get();
        $interests = Interest::query()->pluck('id')->all();

        if ($universities->count() < 3 || $zones->isEmpty() || count($interests) < 5) {
            $this->command?->error('Faltan los catálogos: corre primero CatalogSeeder y ZoneSeeder.');

            return;
        }

        $this->random = new Randomizer(new Mt19937(self::SEED));
        $plainPassword = config('kampus.demo.password') ?: Str::password(16, symbols: false);
        $password = Hash::make($plainPassword);
        $disk = Storage::disk(config('filesystems.profile_photos_disk'));

        $this->removePreviousDemoUsers();

        $students = DB::transaction(function () use ($universities, $zones, $interests, $password, $disk): Collection {
            $students = collect();
            $number = 0;

            foreach ($universities as $shortName => $university) {
                for ($i = 0; $i < self::STUDENTS_PER_UNIVERSITY; $i++) {
                    $students->push($this->createStudent(++$number, $university, $shortName, $zones, $interests, $password, $disk));
                }
            }

            $presenter = $this->createStudent(0, $universities['UTT'], 'UTT', $zones, $interests, $password, $disk, presenter: true);
            $this->connect($presenter, $students);

            return $students->push($presenter);
        });

        $this->command?->info("Datos de demostración: {$students->count()} estudiantes.");
        $this->command?->info('Cuenta para presentar: '.self::PRESENTER_EMAIL);

        if (! config('kampus.demo.password')) {
            $this->command?->warn("Contraseña de las cuentas de demostración (generada en esta corrida): {$plainPassword}");
        }
    }

    /**
     * Borra los usuarios de demostración de una corrida anterior y sus fotos. Sus perfiles, swipes, matches y
     * ubicaciones se borran en cascada.
     */
    private function removePreviousDemoUsers(): void
    {
        $previous = User::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->with('profile.photos')->get();

        if ($previous->isEmpty()) {
            return;
        }

        $disk = Storage::disk(config('filesystems.profile_photos_disk'));
        $disk->delete($previous->flatMap(fn (User $user) => $user->profile?->photos->pluck('path') ?? [])->all());

        DB::transaction(function () use ($previous): void {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $previous->modelKeys())
                ->delete();
            User::query()->whereKey($previous->modelKeys())->delete();
        });
    }

    /**
     * @param  Collection<int, Zone>  $zones
     * @param  list<int>  $interests
     */
    private function createStudent(
        int $number,
        University $university,
        string $shortName,
        Collection $zones,
        array $interests,
        string $password,
        Filesystem $disk,
        bool $presenter = false,
    ): User {
        $firstName = $presenter ? 'Cuenta' : $this->pick(self::FIRST_NAMES);
        $lastNames = $presenter ? 'de Demostración' : $this->pick(self::LAST_NAMES).' '.$this->pick(self::LAST_NAMES);
        $email = $presenter
            ? self::PRESENTER_EMAIL
            : Str::slug(Str::ascii($firstName.' '.Str::before($lastNames, ' ')), '.').'.'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'@'.self::EMAIL_DOMAIN;

        $user = (new User)->forceFill([
            'name' => $firstName.' '.$lastNames,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Student,
            'university_id' => $university->getKey(),
            'email_verified_at' => now(),
        ]);
        $user->save();

        // La mitad declara su campus y el resto, una colonia. La cuenta para presentar vive en una colonia
        // céntrica: así casi todos los que tienen "Cerca de ti" quedan dentro de los 10 km de la vista Cerca.
        $campus = $zones->first(fn (Zone $zone) => $zone->university_id === $university->getKey());
        $colonias = $zones->whereNull('university_id')->values();
        $zone = match (true) {
            $presenter => $colonias->firstWhere('name', self::PRESENTER_ZONE) ?? $campus ?? $colonias->first(),
            $campus !== null && $this->random->getInt(0, 1) === 0 => $campus,
            default => $colonias[$this->random->getInt(0, $colonias->count() - 1)],
        };

        $nearby = $presenter || $this->random->getInt(1, 100) <= 55;

        $profile = Profile::query()->create([
            'user_id' => $user->getKey(),
            'career' => $this->pick(self::CAREERS[$shortName]),
            'semester' => $this->random->getInt(1, 10),
            'birthdate' => now()->subYears($this->random->getInt(18, 25))->subDays($this->random->getInt(0, 364))->toDateString(),
            'gender' => $this->genderOf($firstName, $presenter),
            'bio' => $this->pick(self::BIOS),
            'zone_id' => $zone?->getKey(),
            'nearby_enabled' => $nearby,
        ]);

        $profile->interests()->attach(
            collect($this->random->pickArrayKeys(array_flip($interests), $this->random->getInt(3, 5)))->all(),
        );

        $photoCount = $this->random->getInt(1, 3) === 1 ? 2 : 1;

        for ($position = 1; $position <= $photoCount; $position++) {
            $path = 'profiles/'.$user->getKey().'/'.Str::uuid().'.png';
            $disk->put($path, DemoAvatar::png($number * 7 + $position), ['visibility' => 'private']);
            Photo::query()->create(['profile_id' => $profile->getKey(), 'path' => $path, 'position' => $position]);
        }

        // Perfil obligatorio (T-49): sin `completed_at` no puede descubrir ni sale en Descubrir.
        $profile->syncCompletion();

        // "Cerca de ti": una celda de ~1 km a menos de ~1.5 km del centro de su zona, como la mandaría el celular.
        if ($nearby && $zone !== null) {
            UserLocation::query()->create([
                'user_id' => $user->getKey(),
                'geohash' => Geohash::encode(
                    $zone->center_lat + $this->random->getInt(-130, 130) / 10000,
                    $zone->center_lng + $this->random->getInt(-130, 130) / 10000,
                    6,
                ),
            ]);
        }

        return $user;
    }

    /**
     * Le da a la cuenta para presentar 3 matches ya hechos y 8 "Me interesa" pendientes: al evaluar a
     * esas personas en Descubrir, el match sale al momento. Además, unos matches entre otros estudiantes.
     *
     * @param  Collection<int, User>  $students
     */
    private function connect(User $presenter, Collection $students): void
    {
        $shuffled = collect($this->random->shuffleArray($students->all()));

        foreach ($shuffled->take(3) as $match) {
            $this->match($presenter, $match);
        }

        foreach ($shuffled->slice(3, 8) as $admirer) {
            Swipe::query()->create(['swiper_id' => $admirer->getKey(), 'swiped_id' => $presenter->getKey(), 'liked' => true]);
        }

        foreach ($shuffled->slice(11, 12)->chunk(2) as $pair) {
            [$a, $b] = $pair->values()->all();
            $this->match($a, $b);
        }
    }

    private function match(User $a, User $b): void
    {
        Swipe::query()->create(['swiper_id' => $a->getKey(), 'swiped_id' => $b->getKey(), 'liked' => true]);
        Swipe::query()->create(['swiper_id' => $b->getKey(), 'swiped_id' => $a->getKey(), 'liked' => true]);
        UserMatch::query()->create(UserMatch::pairOf($a, $b));
    }

    /**
     * Género según el nombre: los primeros 20 de FIRST_NAMES son de mujer. No usa el generador aleatorio
     * para no cambiar la secuencia de los demás datos.
     */
    private function genderOf(string $firstName, bool $presenter): Gender
    {
        if ($presenter) {
            return Gender::PrefieroNoDecir;
        }

        return array_search($firstName, self::FIRST_NAMES, true) < 20 ? Gender::Mujer : Gender::Hombre;
    }

    /**
     * @param  list<string>  $options
     */
    private function pick(array $options): string
    {
        return $options[$this->random->getInt(0, count($options) - 1)];
    }
}
