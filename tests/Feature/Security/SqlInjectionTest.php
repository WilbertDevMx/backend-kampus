<?php

namespace Tests\Feature\Security;

use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Discovery\CreatesStudents;
use Tests\TestCase;

/**
 * Los textos que escribe el usuario llegan a la base como parámetros, nunca como parte del SQL.
 * Se prueban las dos consultas que arman SQL a mano con un texto del usuario (la búsqueda del panel y
 * el filtro de carrera de Descubrir), el inicio de sesión y los filtros que solo aceptan valores fijos.
 */
class SqlInjectionTest extends TestCase
{
    use CreatesStudents;
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function payloads(): array
    {
        return [
            'comilla y OR siempre verdadero' => ["' OR '1'='1"],
            'comentario al final' => ["' OR 1=1 --"],
            'comodín y OR' => ["%' OR '1'='1' --"],
            'UNION' => ["' UNION SELECT password FROM users --"],
            'segunda sentencia' => ["'; DROP TABLE users; --"],
            'diagonal invertida' => ["\\' OR 1=1 --"],
        ];
    }

    #[DataProvider('payloads')]
    public function test_the_panel_user_search_treats_sql_as_plain_text(string $payload): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        User::factory()->student($this->utt)->count(3)->create();
        Sanctum::actingAs($moderator);

        $this->getJson('/api/admin/users?search='.rawurlencode($payload))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(4, User::query()->count());
    }

    public function test_quotes_in_the_panel_search_are_searched_literally(): void
    {
        $moderator = User::factory()->moderator($this->utt)->create();
        User::factory()->student($this->utt)->create(['name' => "Sinéad O'Connor"]);
        User::factory()->student($this->utt)->create(['name' => 'Ana Torres']);
        Sanctum::actingAs($moderator);

        $this->assertSame(
            ["Sinéad O'Connor"],
            $this->getJson('/api/admin/users?search='.rawurlencode("o'con"))->assertOk()->json('data.*.name'),
        );
    }

    #[DataProvider('payloads')]
    public function test_the_discovery_career_filter_treats_sql_as_plain_text(string $payload): void
    {
        $viewer = $this->studentWithProfile($this->utt);
        $this->studentWithProfile($this->utt, ['career' => 'Ingeniería en Software']);
        $this->studentWithProfile($this->utt, ['career' => 'Mecatrónica']);
        $literal = $this->studentWithProfile($this->utt, ['career' => "Carrera {$payload} rara"]);
        Sanctum::actingAs($viewer);

        $this->putJson('/api/discover/filters', ['career' => $payload])
            ->assertOk()
            ->assertJsonPath('data.career', $payload);

        // Solo sale quien tiene ese texto, tal cual, en su carrera.
        $this->getJson('/api/discover')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $literal->id);

        $this->assertTrue(Schema::hasTable('users'));
    }

    #[DataProvider('payloads')]
    public function test_sql_in_the_login_does_not_log_anyone_in(string $payload): void
    {
        $student = User::factory()->student($this->utt)->create(['email' => 'ana@uttcampus.edu.mx']);

        // Como correo: no tiene formato de correo.
        $this->postJson('/api/login', ['email' => $payload, 'password' => $payload])
            ->assertUnprocessable()
            ->assertJsonMissingPath('token');

        // Como contraseña de una cuenta que sí existe: es solo una contraseña incorrecta.
        $this->postJson('/api/login', ['email' => $student->email, 'password' => $payload])
            ->assertUnprocessable()
            ->assertJsonMissingPath('token');

        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_filters_with_fixed_values_reject_anything_else(): void
    {
        Sanctum::actingAs(User::factory()->moderator($this->utt)->create());

        $this->getJson('/api/admin/users?sort='.rawurlencode('name; DROP TABLE users'))->assertInvalid(['sort']);
        $this->getJson('/api/admin/users?status='.rawurlencode("active' OR '1'='1"))->assertInvalid(['status']);
        $this->getJson('/api/admin/users?role='.rawurlencode("student' --"))->assertInvalid(['role']);
        $this->getJson('/api/admin/users?university_id='.rawurlencode('1 OR 1=1'))->assertInvalid(['university_id']);

        $this->assertTrue(Schema::hasTable('users'));
    }
}
