<?php

namespace App\Services;

use App\Events\AuctionEnded;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

class AuctionService
{
    /**
     * Finaliza una subasta y envía todas las notificaciones necesarias.
     */
    public function endAuction(Auction $auction): bool
    {
        // Si ya no está activa, no hacemos nada
        if ($auction->status !== 'active') {
            return false;
        }

        return DB::transaction(function () use ($auction) {
            // Lock the row to prevent concurrent processing
            $auction = Auction::where('id', $auction->id)->lockForUpdate()->first();

            if (!$auction || $auction->status !== 'active') {
                return false;
            }

            $winner = $auction->winningBid?->user;
            $reserveMet = $auction->reserveMet();

            // Obtener todos los participantes únicos excepto el ganador
            $participants = Bid::where('auction_id', $auction->id)
                ->where('user_id', '!=', $winner?->id)
                ->distinct()
                ->pluck('user_id');

            if ($winner && $reserveMet) {
                $auction->update([
                    'winner_id' => $winner->id,
                    'status' => 'ended',
                ]);

                // Actualizar el producto: marcar como inactivo y sin stock (comprado)
                $auction->product->update([
                    'is_active' => false,
                    'stock' => 0,
                ]);

                // Notificar al ganador
                Notification::send(
                    $winner->id,
                    'auction_won',
                    '🏆 ¡Felicidades! Has ganado la subasta',
                    "Has ganado la subasta de '{$auction->product->name}' por $ " . number_format($auction->current_price, 2, ',', '.'),
                    ['auction_id' => $auction->id, 'product_id' => $auction->product_id]
                );

                // Notificar al vendedor
                Notification::send(
                    $auction->product->user_id,
                    'auction_sold',
                    '💰 Tu artículo ha sido vendido',
                    "Tu '{$auction->product->name}' ha sido vendido por $ " . number_format($auction->current_price, 2, ',', '.'),
                    ['auction_id' => $auction->id, 'product_id' => $auction->product_id]
                );

                // Notificar a los perdedores
                foreach ($participants as $userId) {
                    Notification::send(
                        $userId,
                        'auction_lost',
                        '☹️ La subasta ha terminado',
                        "La subasta de '{$auction->product->name}' ha terminado. Lamentablemente no has ganado esta vez.",
                        ['auction_id' => $auction->id, 'product_id' => $auction->product_id]
                    );
                }
            } else {
                $auction->update(['status' => 'ended']);

                // Si hubo pujas pero no alcanzó reserva o no hubo ganador
                $allParticipants = Bid::where('auction_id', $auction->id)
                    ->distinct()
                    ->pluck('user_id');

                foreach ($allParticipants as $userId) {
                    Notification::send(
                        $userId,
                        'auction_ended',
                        '☹️ Subasta finalizada sin éxito',
                        "La subasta de '{$auction->product->name}' terminó sin alcanzar el precio de reserva.",
                        ['auction_id' => $auction->id, 'product_id' => $auction->product_id]
                    );
                }

                // Notificar al vendedor de que no se vendió
                Notification::send(
                    $auction->product->user_id,
                    'auction_ended',
                    '📢 Tu subasta ha terminado',
                    "La subasta de '{$auction->product->name}' terminó sin ofertas suficientes para el precio de reserva.",
                    ['auction_id' => $auction->id, 'product_id' => $auction->product_id]
                );
            }

            event(new AuctionEnded($auction->fresh(['product', 'winner'])));
            return true;
        });
    }

    /**
     * Verifica si una subasta ha expirado y la finaliza si es necesario.
     */
    public function checkAndMarkEnded(Auction $auction): bool
    {
        if (in_array($auction->status, ['active', 'pending']) && $auction->ends_at <= now()) {
            return $this->endAuction($auction);
        }
        return false;
    }

    /**
     * Activa subastas pendientes cuya fecha de inicio ya pasó.
     */
    public function activatePending(): int
    {
        $now = now();
        $pending = Auction::where('status', 'pending')
            ->where('starts_at', '<=', $now)
            ->where('is_active', true)
            ->get();

        foreach ($pending as $auction) {
            $auction->update(['status' => 'active']);
        }

        return $pending->count();
    }
}
