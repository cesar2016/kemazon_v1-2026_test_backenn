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

class AuctionEnded implements ShouldBroadcast
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
        return 'auction-ended';
    }

    public function broadcastWith(): array
    {
        return [
            'auction_id' => $this->auction->id,
            'status' => $this->auction->status,
            'winner_id' => $this->auction->winner_id,
            'winner_name' => $this->auction->winner?->name,
            'final_price' => $this->auction->current_price,
            'reserve_met' => $this->auction->reserveMet(),
        ];
    }
}
