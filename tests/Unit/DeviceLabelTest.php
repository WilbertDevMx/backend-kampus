<?php

namespace Tests\Unit;

use App\Support\Security\DeviceLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeviceLabelTest extends TestCase
{
    #[DataProvider('userAgents')]
    public function test_it_names_the_device_from_the_user_agent(?string $userAgent, string $expected): void
    {
        $this->assertSame($expected, DeviceLabel::fromUserAgent($userAgent));
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function userAgents(): array
    {
        return [
            'chrome en windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'Chrome en Windows'],
            'edge en windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Edge en Windows'],
            'safari en iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari en iOS'],
            'chrome en android' => ['Mozilla/5.0 (Linux; Android 15; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Chrome en Android'],
            'firefox en mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox en macOS'],
            'app en android' => ['okhttp/4.12.0', 'App de Kampus'],
            'app en ios' => ['Kampus/1 CFNetwork/1568.100.1 Darwin/24.0.0', 'App de Kampus en iOS'],
            'solo el sistema' => ['Mozilla/5.0 (X11; Linux x86_64)', 'Navegador en Linux'],
            'desconocido' => ['curl/8.9.1', 'Dispositivo desconocido'],
            'vacío' => [null, 'Dispositivo desconocido'],
        ];
    }
}
