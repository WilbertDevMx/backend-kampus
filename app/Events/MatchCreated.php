<?php

namespace App\Events;

use App\Http\Resources\MatchedUserResource;
use App\Models\UserMatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dos estudiantes se dieron "Me interesa" mutuamente (RF-07).
 *
 * Es la parte Observer del flujo: quien crea el match solo dispara el evento, y las reacciones se
 * cuelgan de él. Por ahora, el aviso por WebSocket a los dos en su canal privado `user.{id}` (T-13), para
 * que la app muestre "Es un match" sin recargar. Se transmite hasta que se confirma la transacción.
 */
class MatchCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public UserMatch $match) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->match->user_one_id),
            new PrivateChannel('user.'.$this->match->user_two_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'match.created';
    }

    /**
     * Los dos canales reciben lo mismo: la app toma de `users` a la persona que no es ella.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->match->loadMissing(UserMatch::userSummaryRelations());

        return [
            'match' => [
                'id' => $this->match->id,
                'matched_at' => $this->match->created_at?->toIso8601String(),
                'users' => [
                    (new MatchedUserResource($this->match->userOne))->resolve(),
                    (new MatchedUserResource($this->match->userTwo))->resolve(),
                ],
            ],
        ];
    }
}
