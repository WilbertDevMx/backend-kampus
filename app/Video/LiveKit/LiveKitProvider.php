<?php

namespace App\Video\LiveKit;

use App\Video\VideoCallAccess;
use App\Video\VideoCallProvider;
use App\Video\VideoCallUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Adapter de LiveKit (T-19). Usa la API HTTP de LiveKit (Twirp con JSON) y firma los tokens con la llave
 * de la API, sin SDK: así no se agregan dependencias y el formato queda a la vista.
 *
 * - Los tokens son JWT con HS256: `iss` es la API key, `sub` la identidad y `video` los permisos.
 * - Las llamadas al servidor (crear y cerrar salas) usan un token propio con el permiso `roomCreate`.
 *
 * Referencia: https://docs.livekit.io/home/server/generating-tokens/ y la API `RoomService`.
 */
final class LiveKitProvider implements VideoCallProvider
{
    /** Cuánto dura el token para entrar. Una vez dentro, LiveKit renueva la sesión solo. */
    public const TOKEN_TTL_SECONDS = 900;

    /** Si nadie entra a la sala, LiveKit la cierra a los 5 minutos. */
    private const EMPTY_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $apiKey,
        private readonly ?string $apiSecret,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.livekit.url'),
            config('services.livekit.api_key'),
            config('services.livekit.api_secret'),
        );
    }

    public function openRoom(string $room, int $maxParticipants): void
    {
        // CreateRoom no falla si la sala ya existe: regresa la que hay.
        $this->roomService('CreateRoom', [
            'name' => $room,
            'max_participants' => $maxParticipants,
            'empty_timeout' => self::EMPTY_TIMEOUT_SECONDS,
        ]);
    }

    public function accessFor(string $room, string $identity, string $name): VideoCallAccess
    {
        $this->ensureConfigured();
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TOKEN_TTL_SECONDS);

        $token = $this->sign([
            'sub' => $identity,
            'name' => $name,
            'exp' => $expiresAt->getTimestamp(),
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ]);

        return new VideoCallAccess((string) $this->url, $room, $token, $expiresAt);
    }

    public function closeRoom(string $room): void
    {
        // Si la otra persona ya la cerró o LiveKit la cerró por estar vacía, responde 404: no es un error.
        $this->roomService('DeleteRoom', ['room' => $room], allowNotFound: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function roomService(string $method, array $payload, bool $allowNotFound = false): Response
    {
        $this->ensureConfigured();

        try {
            $response = Http::withToken($this->sign([
                'exp' => CarbonImmutable::now()->addMinute()->getTimestamp(),
                'video' => ['roomCreate' => true],
            ]))
                ->acceptJson()
                ->timeout(5)
                ->post($this->httpUrl()."/twirp/livekit.RoomService/{$method}", $payload);
        } catch (ConnectionException $error) {
            throw new VideoCallUnavailable('No fue posible conectar con LiveKit.', previous: $error);
        }

        if ($response->successful() || ($allowNotFound && $response->status() === 404)) {
            return $response;
        }

        throw new VideoCallUnavailable("LiveKit respondió {$response->status()} en {$method}.");
    }

    /**
     * Firma un JWT con HS256 y la llave de la API.
     *
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims): string
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $claims = ['iss' => $this->apiKey, 'nbf' => $now, 'jti' => (string) Str::uuid(), ...$claims];

        $segments = [
            self::base64Url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), (string) $this->apiSecret, true);

        return implode('.', [...$segments, self::base64Url($signature)]);
    }

    /**
     * La app se conecta por WebSocket (`wss://`); la API del servidor es la misma dirección por HTTPS.
     */
    private function httpUrl(): string
    {
        return rtrim((string) preg_replace('#^ws(s?)://#', 'http$1://', (string) $this->url), '/');
    }

    private function ensureConfigured(): void
    {
        if (blank($this->url) || blank($this->apiKey) || blank($this->apiSecret)) {
            throw new VideoCallUnavailable('Faltan LIVEKIT_URL, LIVEKIT_API_KEY o LIVEKIT_API_SECRET.');
        }
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
