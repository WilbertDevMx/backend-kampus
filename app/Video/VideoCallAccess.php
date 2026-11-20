<?php

namespace App\Video;

use Carbon\CarbonImmutable;

/**
 * Lo que la app necesita para conectarse a una sala: a qué servidor, a qué sala y con qué token.
 */
final readonly class VideoCallAccess
{
    public function __construct(
        public string $url,
        public string $room,
        public string $token,
        public CarbonImmutable $expiresAt,
    ) {}
}
