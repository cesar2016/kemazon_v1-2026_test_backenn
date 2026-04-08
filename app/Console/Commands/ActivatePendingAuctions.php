<?php

namespace App\Console\Commands;

use App\Models\Auction;
use Illuminate\Console\Command;

class ActivatePendingAuctions extends Command
{
    protected $signature = 'auctions:activate-pending';
    protected $description = 'Activa las subastas pendientes cuya fecha de inicio ha llegado';

    public function handle(): int
    {
        $pendingAuctions = Auction::where('status', 'pending')
            ->where('starts_at', '<=', now())
            ->where('is_active', true)
            ->get();

        foreach ($pendingAuctions as $auction) {
            $auction->update(['status' => 'active']);
        }

        $this->info("{$pendingAuctions->count()} subastas activadas.");

        return Command::SUCCESS;
    }
}
