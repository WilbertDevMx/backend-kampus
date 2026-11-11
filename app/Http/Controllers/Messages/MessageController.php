<?php

namespace App\Http\Controllers\Messages;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\User;
use App\Models\UserMatch;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Mensajes', 'Chat entre matches (T-14).', weight: 9)]
class MessageController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

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

    public function store(StoreMessageRequest $request, int $match): JsonResponse
    {
        $conversation = $this->visibleMatch($request->user(), $match);
        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->getKey(),
            'type' => 'text',
            'body' => $request->validated('body'),
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
