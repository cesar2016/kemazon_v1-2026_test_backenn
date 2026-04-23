<?php

namespace App\Console\Commands;

use App\Events\AuctionEnded;
use App\Models\Auction;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EndExpiredAuctions extends Command
{
    protected $signature = 'auctions:end-expired';
    protected $description = 'Finaliza las subastas cuya fecha ha expirado';

    public function handle(): int
    {
        $auctionService = new \App\Services\AuctionService();

        $expiredAuctions = Auction::where('status', 'active')
            ->where('ends_at', '<=', now())
            ->with(['product', 'winningBid.user'])
            ->get();

        foreach ($expiredAuctions as $auction) {
            $auctionService->endAuction($auction);
        }

        $this->info("{$expiredAuctions->count()} subastas finalizadas.");

        return Command::SUCCESS;
    }

        $this->info("{$expiredAuctions->count()} subastas finalizadas.");

        return Command::SUCCESS;
    }
}
