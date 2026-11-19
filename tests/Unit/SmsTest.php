<?php

namespace Tests\Unit;

use App\Sms\SmsDeliveryFailed;
use App\Sms\Vonage\VonageSmsSender;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsTest extends TestCase
{
    public function test_mexican_numbers_are_normalized_to_e164(): void
    {
        foreach (['8711234567', '871 123 4567', '(871) 123-4567', '+52 871 123 4567', '52 1 871 123 4567', '+5218711234567'] as $input) {
            $this->assertSame('+528711234567', PhoneNumber::normalize($input), $input);
        }

        foreach (['', '12345', '+1 871 123 4567', '87112345678', 'ocho siete uno'] as $input) {
            $this->assertNull(PhoneNumber::normalize($input), $input);
        }

        $this->assertSame('******4567', PhoneNumber::mask('+528711234567'));
    }

    public function test_vonage_receives_the_message_in_unicode_and_without_the_plus(): void
    {
        Http::fake([VonageSmsSender::ENDPOINT => Http::response(['message-count' => '1', 'messages' => [['status' => '0']]])]);

        (new VonageSmsSender('llave', 'secreto', 'Kampus'))->send('+528711234567', 'Tu código es 123456.');

        Http::assertSent(fn (Request $request): bool => $request->url() === VonageSmsSender::ENDPOINT
            && $request['to'] === '528711234567'
            && $request['from'] === 'Kampus'
            && $request['type'] === 'unicode'
            && $request['text'] === 'Tu código es 123456.'
            && $request['api_key'] === 'llave');
    }

    public function test_a_message_rejected_by_vonage_throws(): void
    {
        Http::fake([VonageSmsSender::ENDPOINT => Http::response([
            'message-count' => '1',
            'messages' => [['status' => '29', 'error-text' => 'Non-White-Listed Destination - rejected']],
        ])]);

        $this->expectException(SmsDeliveryFailed::class);
        $this->expectExceptionMessage('Non-White-Listed Destination');

        (new VonageSmsSender('llave', 'secreto', 'Kampus'))->send('+528711234567', 'Hola');
    }

    public function test_missing_keys_fail_without_calling_vonage(): void
    {
        Http::fake();

        try {
            (new VonageSmsSender('', '', 'Kampus'))->send('+528711234567', 'Hola');
            $this->fail('Debió fallar sin llaves.');
        } catch (SmsDeliveryFailed $exception) {
            $this->assertStringContainsString('VONAGE_KEY', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
