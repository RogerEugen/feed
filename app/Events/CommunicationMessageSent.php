<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommunicationMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $channel,
        public array $message,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('communication.' . $this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'communication.message';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
