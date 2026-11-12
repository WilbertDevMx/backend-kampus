<?php

namespace App\Events;

use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Alguien colgó o rechazó la videollamada (T-19). Se avisa a la otra persona para que la app cierre la
 * pantalla de llamada, aunque todavía no hubiera contestado.
 */
class VideoCallEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public UserMatch $match,
        public User $endedBy,
        public User $other,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->other->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'call.ended';
    }

    /**
     * @return array{call: array<string, int>}
     */
    public function broadcastWith(): array
    {
        return [
            'call' => [
                'match_id' => (int) $this->match->getKey(),
                'ended_by' => (int) $this->endedBy->getKey(),
            ],
        ];
    }
}
