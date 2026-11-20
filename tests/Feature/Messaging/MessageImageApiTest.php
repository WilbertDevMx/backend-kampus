<?php

namespace Tests\Feature\Messaging;

use App\Events\MessageSent;
use App\Models\Block;
use App\Models\Message;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Envío de imágenes en el chat (T-15, RF-09).
 */
class MessageImageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_a_match_member_sends_an_image_with_an_optional_caption(): void
    {
        [$match, $sender] = $this->createConversation();
        Event::fake([MessageSent::class]);
        Sanctum::actingAs($sender);

        $response = $this->post("/api/matches/{$match->id}/messages", [
            'image' => UploadedFile::fake()->createWithContent('foto.jpg', $this->jpegWithGps()),
            'body' => 'Mira esto',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.body', 'Mira esto')
            ->assertJsonMissingPath('data.attachment_path');

        $message = Message::query()->sole();
        $this->assertStringStartsWith("chats/{$match->id}/", $message->attachment_path);
        $this->assertNotEmpty($response->json('data.image_url'));

        // Se guarda sin el EXIF, que trae la ubicación GPS del celular (RNF-11).
        $stored = Storage::disk('s3')->get($message->attachment_path);
        $this->assertStringNotContainsString("Exif\0\0", $stored);
        $this->assertStringNotContainsString('GPSLatitude-de-prueba', $stored);
        $this->assertSame('image/jpeg', getimagesizefromstring($stored)['mime']);

        // La otra persona la recibe en tiempo real con su URL firmada.
        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($message): bool {
            $payload = $event->broadcastWith()['message'];

            return $payload['id'] === $message->id
                && $payload['type'] === 'image'
                && is_string($payload['image_url'])
                && ! array_key_exists('attachment_path', $payload);
        });

        // Sin texto también se puede.
        $this->post("/api/matches/{$match->id}/messages", [
            'image' => UploadedFile::fake()->createWithContent('captura.png', $this->png()),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.body', null);
    }

    public function test_the_history_returns_a_signed_url_for_images_and_null_for_text(): void
    {
        [$match, $sender, $recipient] = $this->createConversation();
        Sanctum::actingAs($sender);
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => 'Hola'])->assertCreated();
        $this->post("/api/matches/{$match->id}/messages", [
            'image' => UploadedFile::fake()->createWithContent('foto.png', $this->png()),
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($recipient);
        $messages = $this->getJson("/api/matches/{$match->id}/messages")->assertOk()->json('data');

        $this->assertSame('image', $messages[0]['type']);
        $this->assertNotEmpty($messages[0]['image_url']);
        $this->assertSame('text', $messages[1]['type']);
        $this->assertNull($messages[1]['image_url']);
    }

    public function test_only_jpg_or_png_images_up_to_five_megabytes_are_accepted(): void
    {
        [$match, $sender] = $this->createConversation();
        Sanctum::actingAs($sender);
        $url = "/api/matches/{$match->id}/messages";

        $this->post($url, ['image' => UploadedFile::fake()->create('notas.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertInvalid(['image']);
        $this->post($url, ['image' => UploadedFile::fake()->image('foto.gif')], ['Accept' => 'application/json'])
            ->assertInvalid(['image']);
        $this->post($url, ['image' => UploadedFile::fake()->image('enorme.jpg')->size(5121)], ['Accept' => 'application/json'])
            ->assertInvalid(['image' => 'La imagen no puede pesar más de 5 MB.']);
        $this->post($url, ['image' => UploadedFile::fake()->createWithContent('rota.png', 'no es una imagen')], ['Accept' => 'application/json'])
            ->assertInvalid(['image']);
        $this->postJson($url, [])->assertInvalid(['body' => 'El mensaje no puede estar vacío.']);

        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_only_the_two_members_of_a_visible_match_can_send_images(): void
    {
        [$match, $member, $otherMember] = $this->createConversation();
        $image = fn (): UploadedFile => UploadedFile::fake()->createWithContent('foto.png', $this->png());

        Sanctum::actingAs(User::factory()->create());
        $this->post("/api/matches/{$match->id}/messages", ['image' => $image()], ['Accept' => 'application/json'])
            ->assertNotFound();

        Block::query()->create(['blocker_id' => $otherMember->id, 'blocked_id' => $member->id]);
        Sanctum::actingAs($member);
        $this->post("/api/matches/{$match->id}/messages", ['image' => $image()], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_images_have_their_own_limit_per_minute(): void
    {
        [$match, $sender] = $this->createConversation();
        Sanctum::actingAs($sender);
        $send = fn () => $this->post("/api/matches/{$match->id}/messages", [
            'image' => UploadedFile::fake()->createWithContent('foto.png', $this->png()),
        ], ['Accept' => 'application/json']);

        foreach (range(1, 10) as $attempt) {
            $send()->assertCreated();
        }

        $send()->assertTooManyRequests();
        // El texto sigue funcionando.
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => 'Sigo aquí'])->assertCreated();

        $this->travel(61)->seconds();
        $send()->assertCreated();
    }

    /**
     * @return array{0: UserMatch, 1: User, 2: User}
     */
    private function createConversation(): array
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $match = UserMatch::factory()->between($firstUser, $secondUser)->create();

        return [$match, $firstUser, $secondUser];
    }

    /**
     * JPEG válido con un segmento EXIF como el que deja la cámara del celular.
     */
    private function jpegWithGps(): string
    {
        $jpeg = $this->encode('imagejpeg');
        $payload = "Exif\0\0".'GPSLatitude-de-prueba';
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function png(): string
    {
        return $this->encode('imagepng');
    }

    private function encode(string $encoder): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        $encoder($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
