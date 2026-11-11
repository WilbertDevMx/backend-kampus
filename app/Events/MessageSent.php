<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->message->match_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array{message: array<string, mixed>} */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'match_id' => $this->message->match_id,
                'sender_id' => $this->message->sender_id,
                'type' => $this->message->type,
                'body' => $this->message->body,
                'read_at' => $this->message->read_at?->toIso8601String(),
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
