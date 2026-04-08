<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();

        $items = CartItem::with(['product:id,name,slug,images,thumbnail,price,stock,user_id', 'auction'])
            ->where('user_id', $user->id)
            ->get();

        $grouped = $items->groupBy('product.user_id');

        $total = $items->sum(function ($item) {
            return $item->price * $item->quantity;
        });

        return response()->json([
            'items' => $items,
            'grouped_by_seller' => $grouped,
            'total' => $total,
            'total_items' => $items->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'type' => 'required|in:direct,auction',
            'auction_id' => 'nullable|exists:auctions,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $product = \App\Models\Product::findOrFail($request->product_id);

            if ($product->stock < $request->quantity && $request->type === 'direct') {
                return response()->json(['message' => 'Stock insuficiente'], 400);
            }

            $price = $product->price;

            if ($request->type === 'auction') {
                if (!$request->auction_id) {
                    return response()->json(['message' => 'ID de subasta requerido'], 400);
                }

                $auction = \App\Models\Auction::findOrFail($request->auction_id);

                if (!$auction->isEnded()) {
                    return response()->json(['message' => 'La subasta aún no ha finalizado'], 400);
                }

                if ($auction->winner_id !== $user->id) {
                    return response()->json(['message' => 'No eres el ganador de esta subasta'], 403);
                }

                $price = $auction->current_price;
            }

            $existing = CartItem::where('user_id', $user->id)
                ->where('product_id', $request->product_id)
                ->where('type', $request->type)
                ->first();

            if ($existing) {
                if ($request->type === 'direct') {
                    $existing->update(['quantity' => $existing->quantity + $request->quantity]);
                }

                return response()->json([
                    'message' => 'El producto ya está en el carrito o fue actualizado',
                    'item' => $existing->fresh(),
                ]);
            }

            $item = CartItem::create([
                'user_id' => $user->id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'price' => $price,
                'type' => $request->type,
                'auction_id' => $request->auction_id,
            ]);

            return response()->json([
                'message' => 'Producto agregado al carrito',
                'item' => $item->load(['product:id,name,slug,images,thumbnail,price,stock,user_id']),
            ], 201);
        } catch (\Exception $e) {
            Log::error("Error adding to cart: " . $e->getMessage());
            return response()->json(['message' => 'Error interno al agregar al carrito'], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $item = CartItem::where('user_id', $user->id)->findOrFail($id);

        if ($item->type === 'auction') {
            return response()->json(['message' => 'No puedes modificar la cantidad de una subasta ganada'], 400);
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($item->product->stock < $request->quantity) {
            return response()->json(['message' => 'Stock insuficiente'], 400);
        }

        $item->update(['quantity' => $request->quantity]);

        return response()->json([
            'message' => 'Carrito actualizado',
            'item' => $item->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        $item = CartItem::where('user_id', $user->id)->findOrFail($id);

        $item->delete();

        return response()->json(['message' => 'Producto eliminado del carrito']);
    }

    public function clear(): JsonResponse
    {
        $user = auth()->user();

        CartItem::where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Carrito vaciado']);
    }

    public function checkout(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'shipping_address_id' => 'required|exists:addresses,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $items = CartItem::with(['product', 'auction'])
            ->where('user_id', $user->id)
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'El carrito está vacío'], 400);
        }

        // Group items by seller_id
        $sellerGroups = $items->groupBy(function ($item) {
            return $item->product->user_id;
        });

        $orders = [];

        try {
            DB::beginTransaction();

            foreach ($sellerGroups as $sellerId => $sellerItems) {
                // Check stock for all items in this group
                foreach ($sellerItems as $item) {
                    if ($item->type === 'direct' && $item->product->stock < $item->quantity) {
                        return response()->json([
                            'message' => "Stock insuficiente para {$item->product->name}",
                        ], 400);
                    }
                }

                $seller = User::find($sellerId);
                $subtotal = $sellerItems->sum(fn($item) => $item->price * $item->quantity);
                $shippingCost = 0; // Future enhancement

                $order = Order::create([
                    'user_id' => $user->id,
                    'seller_id' => $sellerId,
                    'order_number' => Order::generateOrderNumber(),
                    'status' => 'pending',
                    'subtotal' => $subtotal,
                    'shipping_cost' => $shippingCost,
                    'total' => $subtotal + $shippingCost,
                    'payment_status' => 'pending',
                    'shipping_address_id' => $request->shipping_address_id,
                ]);

                foreach ($sellerItems as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'seller_id' => $sellerId,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'total' => $item->price * $item->quantity,
                        'type' => $item->type,
                        'auction_id' => $item->auction_id,
                    ]);

                    if ($item->type === 'direct') {
                        $item->product->decrement('stock', $item->quantity);
                    }
                }

                // MercadoPago Integration
                if ($seller && $seller->mercadopago_access_token) {
                    try {
                        $mpService = new MercadoPagoService($seller->mercadopago_access_token);
                        $preference = $mpService->createPreference($order->load('items.product'));

                        $order->update([
                            'mercadopago_preference_id' => $preference['id'],
                            'payment_url' => $preference['init_point'],
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Error creating MP preference for Order {$order->id}: " . $e->getMessage());
                    }
                }

                $orders[] = $order->load('items.product');
            }

            // Clear cart
            CartItem::where('user_id', $user->id)->delete();

            DB::commit();

            return response()->json([
                'message' => 'Pedido(s) creado(s)',
                'orders' => $orders,
                'payment_url' => count($orders) === 1 ? $orders[0]->payment_url : null,
                'has_payment_url' => count($orders) > 0 && collect($orders)->contains(fn($o) => !empty($o->payment_url)),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Checkout error: " . $e->getMessage());
            return response()->json([
                'message' => 'Error durante el proceso de pago',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}