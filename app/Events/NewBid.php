<?php

namespace App\Events;

use App\Models\Auction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewBid implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Auction $auction;

    public function __construct(Auction $auction)
    {
        $this->auction = $auction;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('auction.' . $this->auction->id),
            new Channel('auctions'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'new-bid';
    }

    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->auction->id,
            'current_price' => $this->auction->current_price,
            'bidder' => $this->auction->winningBid?->user?->name ?? 'Anónimo',
            'bid_count' => $this->auction->bids()->count(),
            'ends_at' => $this->auction->ends_at->toIso8601String(),
        ];
    }
}
