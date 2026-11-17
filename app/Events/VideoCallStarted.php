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
 * Alguien del match empezó una videollamada (T-19) o una llamada de voz (T-20). Se avisa a la otra persona por
 * su canal `user.{id}` para que la app muestre la llamada entrante. El aviso no trae el token: quien contesta
 * pide el suyo. `type` (`video` o `audio`) le dice a la app qué tipo de llamada mostrar.
 */
class VideoCallStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public UserMatch $match,
        public User $caller,
        public User $callee,
        public string $type = 'video',
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
                'type' => $this->type,
                'caller' => (new MatchedUserResource($this->caller))->resolve(),
            ],
        ];
    }
}
