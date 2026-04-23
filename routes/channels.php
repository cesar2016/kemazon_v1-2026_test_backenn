<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('auctions', function ($user) {
    return true;
});

Broadcast::channel('auction.{auctionId}', function ($user, $auctionId) {
    return true;
});
