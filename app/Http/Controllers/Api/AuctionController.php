<?php

namespace App\Http\Controllers\Api;

use App\Events\AuctionEnded;
use App\Events\NewBid;
use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AuctionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $auctionService = new \App\Services\AuctionService();
        $auctionService->activatePending();

        $activeAuctions = Auction::where('status', 'active')->with('product')->get();
        foreach ($activeAuctions as $auction) {
            $auctionService->checkAndMarkEnded($auction);
        }

        $query = Auction::with(['product:id,name,slug,thumbnail,user_id', 'product.user:id,name', 'winningBid.user:id,name'])
            ->active();

        $filter = $request->get('filter', 'active');

        switch ($filter) {
            case 'ending':
                $query->where('ends_at', '<=', now()->addHours(6));
                $query->orderBy('ends_at', 'asc');
                break;
            case 'new':
                $query->where('created_at', '>=', now()->subDays(3));
                $query->orderBy('created_at', 'desc');
                break;
            default:
                $query->orderBy('ends_at', 'asc');
                break;
        }

        if ($request->has('search')) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = min($request->get('per_page', 20), 50);
        $auctions = $query->paginate($perPage);

        return response()->json($auctions);
    }

    public function show(int $id): JsonResponse
    {
        $auction = Auction::findOrFail($id);

        // Proactively manage auction status
        $auctionService = new \App\Services\AuctionService();
        $auctionService->activatePending();
        $auctionService->checkAndMarkEnded($auction);

        $auction->load([
            'product:id,name,slug,thumbnail,images,description,user_id',
            'product.user:id,name,avatar,created_at',
            'bids' => function ($query) {
                $query->orderBy('amount', 'desc')->limit(10);
            },
            'bids.user:id,name,avatar',
            'winningBid.user:id,name,avatar',
        ]);

        $totalBids = $auction->bids()->count();

        return response()->json([
            'auction' => $auction,
            'total_bids' => $totalBids,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_seller) {
            return response()->json(['message' => 'Debes ser vendedor para crear subastas'], 403);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'starting_price' => 'required|numeric|min:0',
            'reserve_price' => 'nullable|numeric|min:0',
            'buy_now_price' => 'nullable|numeric|gt:starting_price',
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
            'has_reserve' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = Product::findOrFail($request->product_id);

        if ($product->user_id !== $user->id) {
            return response()->json(['message' => 'Este producto no te pertenece'], 403);
        }

        $auction = Auction::create([
            'product_id' => $request->product_id,
            'starting_price' => $request->starting_price,
            'current_price' => $request->starting_price,
            'reserve_price' => $request->reserve_price,
            'buy_now_price' => $request->buy_now_price,
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'is_active' => true,
            'has_reserve' => $request->has_reserve ?? false,
            'status' => 'pending',
        ]);

        Notification::send(
            $user->id,
            'new_auction',
            '¡Subasta creada!',
            "Tu subasta para '{$product->name}' ha sido publicada exitosamente.",
            ['auction_id' => $auction->id]
        );

        return response()->json([
            'message' => 'Subasta creada',
            'auction' => $auction,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $auction = Auction::findOrFail($id);

        if ($auction->product->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'No tienes permiso'], 403);
        }

        if ($auction->bids()->count() > 0) {
            return response()->json(['message' => 'No puedes editar una subasta con pujas'], 400);
        }

        $validator = Validator::make($request->all(), [
            'starting_price' => 'sometimes|numeric|min:0',
            'reserve_price' => 'nullable|numeric|min:0',
            'buy_now_price' => 'nullable|numeric|gt:starting_price',
            'starts_at' => 'sometimes|date|after:now',
            'ends_at' => 'sometimes|date|after:starts_at',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $auction->update($request->only([
            'starting_price',
            'reserve_price',
            'buy_now_price',
            'starts_at',
            'ends_at',
            'is_active'
        ]));

        return response()->json([
            'message' => 'Subasta actualizada',
            'auction' => $auction->fresh(),
        ]);
    }

    public function placeBid(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Debes iniciar sesión para ofertar'], 401);
        }

        $auction = Auction::findOrFail($id);

        if (!in_array($auction->status, ['active', 'pending'])) {
            return response()->json(['message' => 'La subasta no está activa'], 400);
        }

        if ($auction->isEnded()) {
            return response()->json(['message' => 'La subasta ha terminado'], 400);
        }

        if ($auction->product->user_id === $user->id) {
            return response()->json(['message' => 'No puedes pujar en tu propia subasta'], 400);
        }

        $currentPrice = floatval($auction->current_price);
        $minIncrement = $this->calculateMinIncrement($currentPrice);
        $minBid = $currentPrice + $minIncrement;

        $amount = floatval($request->amount);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:' . $minBid,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '¡Ups! La oferta mínima ahora es de $' . number_format($minBid, 0, ',', '.') . '. ¡Sube un poco más!'
            ], 422);
        }

        $previousWinnerId = $auction->winner_id;

        DB::transaction(function () use ($auction, $user, $amount, $request, &$previousWinnerId) {
            // Desmarcar ganador anterior
            $auction->bids()->where('is_winning', true)->update(['is_winning' => false]);

            // Registrar oferta manual
            Bid::create([
                'auction_id' => $auction->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'is_auto_bid' => false,
                'is_winning' => true,
                'ip_address' => $request->ip(),
            ]);

            $auction->update([
                'current_price' => $amount,
                'winner_id' => $user->id,
            ]);

            // Procesar posibles auto-ofertas de otros usuarios
            $notifiedUsers = [];
            $this->processAutoBids($auction, $user->id, $notifiedUsers);

            // Limpiar al final de la transacción con el precio final resultante
            $this->clearExhaustedAutoBids($auction);
        });

        // Notificar inicio de participación
        $hasHistory = Bid::where('auction_id', $auction->id)
            ->where('user_id', $user->id)
            ->where('is_winning', false)
            ->exists();

        if (!$hasHistory) {
            Notification::send(
                $user->id,
                'auction_participation',
                '¡Has comenzado a participar!',
                "Ya estás pujando en la subasta de '{$auction->product->name}'. ¡Mucha suerte!",
                ['auction_id' => $auction->id]
            );
        } else {
            Notification::send(
                $user->id,
                'auction_leading',
                '¡Vas ganando!',
                "Tu oferta es la más alta en '{$auction->product->name}'. ¡Mantente así!",
                ['auction_id' => $auction->id]
            );
        }

        $auction->refresh();

        // Notificar al ganador anterior si fue superado por esta puja o por una auto-puja subsiguiente
        if ($previousWinnerId && $previousWinnerId !== $auction->winner_id) {
            Notification::send(
                $previousWinnerId,
                'outbid',
                '¡Te han sobrepasado! 👎',
                "Tu oferta en {$auction->product->name} fue superada. ¡No te quedes fuera, vuelve a pujar!",
                ['auction_id' => $auction->id]
            );
        }

        // Si el usuario que pujó manualmente fue superado inmediatamente por una auto-puja
        if ($auction->winner_id !== $user->id) {
            Notification::send(
                $user->id,
                'outbid_auto',
                '¡Alguien tiene auto-oferta! 🚀',
                "Tu oferta fue superada automáticamente por otro comprador. ¡Sube tu apuesta para ganar!",
                ['auction_id' => $auction->id]
            );
        }

        event(new NewBid($auction->fresh(['product', 'bids.user', 'winningBid.user'])));

        return response()->json([
            'message' => $auction->winner_id === $user->id
                ? '¡Genial! Vas liderando la subasta 😎'
                : '¡Cuidado! Alguien tiene activada la "Oferta Automática" y sigue ganando 🚨',
            'auction' => $auction->fresh(['product', 'bids.user', 'winningBid.user']),
        ]);
    }

    public function configureAutoBid(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Debes iniciar sesión'], 401);
        }

        $auction = Auction::findOrFail($id);

        if (!in_array($auction->status, ['active', 'pending'])) {
            return response()->json(['message' => 'La subasta no está activa'], 400);
        }

        if ($auction->isEnded()) {
            return response()->json(['message' => 'La subasta ha terminado'], 400);
        }

        if ($auction->product->user_id === $user->id) {
            return response()->json(['message' => 'No puedes ofertar en tu propia subasta'], 400);
        }

        $maxBid = floatval($request->max_bid);
        $currentPrice = floatval($auction->current_price);
        $minIncrement = $this->calculateMinIncrement($currentPrice);

        // El monto máximo debe ser al menos el precio actual + incremento
        $minPossibleMax = $currentPrice + $minIncrement;

        if ($maxBid < $minPossibleMax) {
            return response()->json([
                'message' => '¡Piensa en grande! Tu oferta máxima debe ser al menos $' . number_format($minPossibleMax, 0, ',', '.')
            ], 422);
        }

        // Verificar si ya existe una auto-oferta igual o superior de otro usuario
        $existingAutoBid = Bid::where('auction_id', $auction->id)
            ->where('user_id', '!=', $user->id)
            ->whereNotNull('max_bid')
            ->where('max_bid', '>=', $maxBid)
            ->exists();

        if ($existingAutoBid) {
            return response()->json([
                'message' => '¡Esa oferta ya está cubierta! Ya existe una auto-oferta mayor configurada por otro usuario. Prueba con un monto superior.'
            ], 422);
        }

        $previousWinnerId = $auction->winner_id;

        DB::transaction(function () use ($auction, $user, $maxBid, $minIncrement, $currentPrice, $request) {
            // Cancelar auto-ofertas previas del mismo usuario para esta subasta
            Bid::where('auction_id', $auction->id)
                ->where('user_id', $user->id)
                ->whereNotNull('max_bid')
                ->update(['max_bid' => null]);

            // Si el usuario no es el ganador actual, debe pujar el siguiente incremento
            if ($auction->winner_id !== $user->id) {
                $auction->bids()->where('is_winning', true)->update(['is_winning' => false]);

                $bidAmount = $currentPrice + $minIncrement;

                Bid::create([
                    'auction_id' => $auction->id,
                    'user_id' => $user->id,
                    'amount' => $bidAmount,
                    'max_bid' => $maxBid,
                    'is_auto_bid' => true,
                    'is_winning' => true,
                    'ip_address' => $request->ip(),
                ]);

                $auction->update([
                    'current_price' => $bidAmount,
                    'winner_id' => $user->id,
                ]);

                // Procesar guerras de auto-ofertas si existen
                $notifiedUsers = [];
                $this->processAutoBids($auction, $user->id, $notifiedUsers);
            } else {
                // Si ya es el ganador, solo actualizamos su max_bid en su puja ganadora actual
                $currentWinningBid = Bid::where('auction_id', $auction->id)
                    ->where('user_id', $user->id)
                    ->where('is_winning', true)
                    ->first();

                if ($currentWinningBid) {
                    $currentWinningBid->update(['max_bid' => $maxBid]);
                } else {
                    // Fallback
                    Bid::create([
                        'auction_id' => $auction->id,
                        'user_id' => $user->id,
                        'amount' => $currentPrice,
                        'max_bid' => $maxBid,
                        'is_auto_bid' => true,
                        'is_winning' => true,
                        'ip_address' => $request->ip(),
                    ]);
                }
            }

            $this->clearExhaustedAutoBids($auction);
        });

        $auction->refresh();

        if ($previousWinnerId && $previousWinnerId !== $auction->winner_id) {
            Notification::send(
                $previousWinnerId,
                'outbid',
                '¡Te han sobrepasado! 💪',
                "Alguien activó una oferta automática en {$auction->product->name} y te superó. ¡No te rindas!",
                ['auction_id' => $auction->id]
            );
        }

        event(new NewBid($auction->fresh(['product', 'bids.user', 'winningBid.user'])));

        return response()->json([
            'message' => '¡Oferta automática activada con éxito! 😎 Estás al mando.',
            'auction' => $auction->fresh(['product', 'bids.user', 'winningBid.user']),
        ]);
    }

    private function processAutoBids(Auction $auction, int $lastBidderId, array &$notifiedUsers = []): void
    {
        $currentBidAmount = floatval($auction->current_price);
        $minIncrement = $this->calculateMinIncrement($currentBidAmount);
        $nextMinBid = $currentBidAmount + $minIncrement;

        // Buscar la mejor auto-oferta disponible que NO sea del último postor
        $bestAutoBid = Bid::where('auction_id', $auction->id)
            ->where('user_id', '!=', $lastBidderId)
            ->whereNotNull('max_bid')
            ->where('max_bid', '>=', $nextMinBid)
            ->orderBy('max_bid', 'desc')
            ->first();

        if (!$bestAutoBid) {
            return;
        }

        $autoBidAmount = min($nextMinBid, $bestAutoBid->max_bid);

        // Desmarcar ganador actual
        $auction->bids()->where('is_winning', true)->update(['is_winning' => false]);

        // Crear la nueva puja del auto-postor
        Bid::create([
            'auction_id' => $auction->id,
            'user_id' => $bestAutoBid->user_id,
            'amount' => $autoBidAmount,
            'max_bid' => $bestAutoBid->max_bid,
            'is_auto_bid' => true,
            'is_winning' => true,
            'ip_address' => request()->ip(),
        ]);

        $auction->update([
            'current_price' => $autoBidAmount,
            'winner_id' => $bestAutoBid->user_id,
        ]);

        // La limpieza se hará al final de la recursión en placeBid/configureAutoBid
        // Pero marcamos como agotado si llegó al límite
        if ($autoBidAmount >= $bestAutoBid->max_bid) {
            $bestAutoBid->update(['max_bid' => null]);
        }

        // Notificar al postor automático que su puja subió
        if (!in_array($bestAutoBid->user_id, $notifiedUsers)) {
            Notification::send(
                $bestAutoBid->user_id,
                'auto_bid_triggered',
                '¡Tu oferta automática sigue ganando! 😎',
                "Alguien pujó, pero tu oferta automática te mantiene liderando {$auction->product->name} con \${$autoBidAmount}.",
                ['auction_id' => $auction->id]
            );
            $notifiedUsers[] = $bestAutoBid->user_id;
        }

        // Llamada recursiva para procesar si hay OTRA auto-oferta que supere a esta
        $this->processAutoBids($auction, $bestAutoBid->user_id, $notifiedUsers);
    }

    private function calculateMinIncrement(float $currentPrice): float
    {
        if ($currentPrice < 20000) {
            return ceil($currentPrice * 0.10);
        } elseif ($currentPrice <= 100000) {
            return ceil($currentPrice * 0.05);
        } else {
            return 10000;
        }
    }

    public function buyNow(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $auction = Auction::findOrFail($id);

        if ($auction->status !== 'active') {
            return response()->json(['message' => 'La subasta no está activa'], 400);
        }

        if (!$auction->buy_now_price) {
            return response()->json(['message' => 'Esta subasta no tiene precio de compra inmediata'], 400);
        }

        if ($auction->product->user_id === $user->id) {
            return response()->json(['message' => 'No puedes comprar tu propio producto'], 400);
        }

        DB::transaction(function () use ($auction, $user, $request) {
            Bid::create([
                'auction_id' => $auction->id,
                'user_id' => $user->id,
                'amount' => $auction->buy_now_price,
                'is_winning' => true,
                'ip_address' => $request->ip(),
            ]);

            $auction->update([
                'current_price' => $auction->buy_now_price,
                'winner_id' => $user->id,
                'status' => 'ended',
            ]);
        });

        Notification::send(
            $user->id,
            'product_purchased',
            '¡Compra Exitosa! 🎉',
            "Has comprado '{$auction->product->name}' por \${$auction->buy_now_price}. ¡Es tuyo!",
            ['auction_id' => $auction->id]
        );

        Notification::send(
            $auction->product->user_id,
            'product_sold',
            '¡Vendido! 💰',
            "Tu producto '{$auction->product->name}' fue comprado inmediatamente por \${$auction->buy_now_price}.",
            ['auction_id' => $auction->id]
        );

        event(new AuctionEnded($auction->fresh()));

        return response()->json([
            'message' => 'Compra realizada',
            'auction' => $auction,
        ]);
    }

    public function endAuction(int $id): JsonResponse
    {
        $auction = Auction::with(['product'])->findOrFail($id);
        (new \App\Services\AuctionService())->endAuction($auction);

        return response()->json([
            'message' => 'Subasta finalizada',
            'auction' => $auction->fresh(['product', 'winner', 'winningBid']),
        ]);
    }

    public function myAuctions(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = Auction::whereHas('product', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->with(['product:id,name,slug,images', 'bids', 'winningBid.user:id,name']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $auctions = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($auctions);
    }

    public function myBids(): JsonResponse
    {
        $user = auth()->user();

        $bids = Bid::where('user_id', $user->id)
            ->with(['auction.product:id,name,slug,images', 'auction.winningBid.user:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($bids);
    }

    private function clearExhaustedAutoBids(Auction $auction): void
    {
        $currentPrice = floatval($auction->current_price);
        $minIncrement = $this->calculateMinIncrement($currentPrice);
        $nextMin = $currentPrice + $minIncrement;

        Bid::where('auction_id', $auction->id)
            ->whereNotNull('max_bid')
            ->where('max_bid', '<', $nextMin)
            ->update(['max_bid' => null]);
    }
}
