<?php

namespace Tests\Unit;

use App\Video\LiveKit\LiveKitProvider;
use App\Video\VideoCallUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adapter de LiveKit (T-19): tokens firmados y llamadas a la API de salas.
 */
class LiveKitProviderTest extends TestCase
{
    private const SECRET = 'secreto-de-prueba-de-al-menos-32-caracteres';

    public function test_the_join_token_is_a_signed_jwt_limited_to_one_room(): void
    {
        $this->freezeTime();
        $access = $this->provider()->accessFor('match-7', 'user-3', 'Ana López', 2);

        $this->assertSame('wss://kampus-prueba.livekit.cloud', $access->url);
        $this->assertSame('match-7', $access->room);
        $this->assertSame(now()->addSeconds(LiveKitProvider::TOKEN_TTL_SECONDS)->getTimestamp(), $access->expiresAt->getTimestamp());

        [$header, $claims, $signature] = explode('.', $access->token);
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $this->decode($header));
        $this->assertSame(
            $this->base64Url(hash_hmac('sha256', "{$header}.{$claims}", self::SECRET, true)),
            $signature,
            'La firma debe ser HS256 con la API secret.',
        );

        $payload = $this->decode($claims);
        $this->assertSame('APIprueba', $payload['iss']);
        $this->assertSame('user-3', $payload['sub']);
        $this->assertSame('Ana López', $payload['name']);
        $this->assertSame(now()->getTimestamp(), $payload['nbf']);
        $this->assertSame($access->expiresAt->getTimestamp(), $payload['exp']);
        $this->assertSame([
            'room' => 'match-7',
            'roomJoin' => true,
            'canPublish' => true,
            'canSubscribe' => true,
            'canPublishData' => true,
        ], $payload['video']);
        $this->assertArrayNotHasKey('roomCreate', $payload['video'], 'El token de la app no puede crear ni cerrar salas.');

        // LiveKit Cloud solo hace cumplir el límite de participantes que viene en el token.
        $this->assertSame(['maxParticipants' => 2, 'emptyTimeout' => 300], $payload['roomConfig']);
    }

    public function test_it_creates_rooms_with_a_participant_limit_through_the_room_service(): void
    {
        Http::fake(['kampus-prueba.livekit.cloud/*' => Http::response(['name' => 'match-7'])]);

        $this->provider()->openRoom('match-7', 2);

        Http::assertSent(function (Request $request): bool {
            $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
            $claims = $this->decode(explode('.', $token)[1] ?? '');

            return $request->url() === 'https://kampus-prueba.livekit.cloud/twirp/livekit.RoomService/CreateRoom'
                && $request['name'] === 'match-7'
                && $request['max_participants'] === 2
                && ($claims['video'] ?? null) === ['roomCreate' => true];
        });
    }

    public function test_closing_a_room_that_no_longer_exists_is_not_an_error(): void
    {
        Http::fake(['*/DeleteRoom' => Http::response(['code' => 'not_found', 'msg' => 'room not found'], 404)]);

        $this->provider()->closeRoom('match-7');

        Http::assertSent(fn (Request $request): bool => $request['room'] === 'match-7');
    }

    public function test_it_reports_when_livekit_fails_or_is_not_configured(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        try {
            $this->provider()->openRoom('match-7', 2);
            $this->fail('Debió lanzar VideoCallUnavailable.');
        } catch (VideoCallUnavailable $error) {
            $this->assertStringContainsString('500', $error->getMessage());
        }

        $this->expectException(VideoCallUnavailable::class);
        (new LiveKitProvider(null, null, null))->accessFor('match-7', 'user-3', 'Ana', 2);
    }

    private function provider(): LiveKitProvider
    {
        return new LiveKitProvider('wss://kampus-prueba.livekit.cloud', 'APIprueba', self::SECRET);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $segment): array
    {
        return json_decode((string) base64_decode(strtr($segment, '-_', '+/')), true) ?? [];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
