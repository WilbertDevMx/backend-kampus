<?php

namespace App\Events;

use App\Http\Resources\MatchedUserResource;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Alguien del match empezó una videollamada (T-19). Se avisa a la otra persona por su canal `user.{id}`
 * para que la app muestre la llamada entrante. El aviso no trae el token: quien contesta pide el suyo.
 */
class VideoCallStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public UserMatch $match,
        public User $caller,
        public User $callee,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->callee->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'call.started';
    }

    /**
     * @return array{call: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        $this->caller->loadMissing(['university', 'profile.photos' => fn ($photos) => $photos->orderBy('position')]);

        return [
            'call' => [
                'match_id' => $this->match->getKey(),
                'caller' => (new MatchedUserResource($this->caller))->resolve(),
            ],
        ];
    }
}
