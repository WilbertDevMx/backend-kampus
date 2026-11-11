<?php

namespace App\Http\Controllers\Messages;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use App\Models\UserMatch;
use App\Support\Images\ImageSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MessageController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    /** Imágenes por minuto que puede enviar cada estudiante (T-15). */
    private const MAX_IMAGES_PER_MINUTE = 10;

    public function index(Request $request, int $match): AnonymousResourceCollection
    {
        $conversation = $this->visibleMatch($request->user(), $match);
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ]);
        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);

        return MessageResource::collection(
            $conversation->messages()
                ->orderByDesc('id')
                ->paginate($perPage),
        );
    }

    public function store(StoreMessageRequest $request, ImageSanitizer $sanitizer, int $match): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->visibleMatch($user, $match);
        $image = $request->file('image');

        if ($image === null) {
            $message = $conversation->messages()->create([
                'sender_id' => $user->getKey(),
                'type' => Message::TYPE_TEXT,
                'body' => $request->validated('body'),
            ]);

            MessageSent::dispatch($message);

            return (new MessageResource($message))->response()->setStatusCode(201);
        }

        // Las imágenes pesan más que el texto: tienen su propio límite, aparte de los 30 mensajes por minuto.
        $limiterKey = 'chat-images:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_IMAGES_PER_MINUTE)) {
            return response()->json([
                'message' => 'Estás enviando demasiadas imágenes. Espera un momento.',
            ], 429, ['Retry-After' => RateLimiter::availableIn($limiterKey)]);
        }

        RateLimiter::hit($limiterKey, 60);

        // RNF-11: las fotos del celular traen la ubicación GPS exacta en el EXIF. Se re-codifican antes de
        // guardarlas y nunca se guarda el archivo original.
        try {
            $cleanImage = $sanitizer->sanitize($image);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'image' => 'El archivo no es una imagen válida.',
            ]);
        }

        $disk = Storage::disk(config('filesystems.chat_images_disk'));
        $path = 'chats/'.$conversation->getKey().'/'.Str::uuid().'.'.$image->extension();

        if (! $disk->put($path, $cleanImage, ['visibility' => 'private'])) {
            return response()->json(['message' => 'No fue posible enviar la imagen.'], 503);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $user->getKey(),
            'type' => Message::TYPE_IMAGE,
            'body' => $request->validated('body'),
            'attachment_path' => $path,
        ]);

        MessageSent::dispatch($message);

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    public function markRead(Request $request, int $match): JsonResponse
    {
        $conversation = $this->visibleMatch($request->user(), $match);
        $readCount = $conversation->messages()
            ->where('sender_id', '!=', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => [
                'read_count' => $readCount,
            ],
        ]);
    }

    private function visibleMatch(User $user, int $matchId): UserMatch
    {
        return UserMatch::query()
            ->visibleTo($user)
            ->whereKey($matchId)
            ->firstOrFail();
    }
}
