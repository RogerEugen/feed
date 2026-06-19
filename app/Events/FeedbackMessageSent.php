<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FeedbackMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $channel,
        public array $message,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new Channel('feedback.' . $this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'feedback.message';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
