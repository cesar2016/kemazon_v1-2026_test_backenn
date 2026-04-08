<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = Order::with(['items.product:id,name,slug,images', 'shippingAddress'])
            ->where('user_id', $user->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($orders);
    }

    public function show(int $id): JsonResponse
    {
        $user = auth()->user();

        $order = Order::with([
            'items.product:id,name,slug,images,user_id',
            'items.product.user:id,name',
            'shippingAddress',
            'user:id,name,email',
        ])->where('user_id', $user->id)->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    public function sellerOrders(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_seller) {
            return response()->json(['message' => 'Debes ser vendedor'], 403);
        }

        $query = Order::whereHas('items', function ($q) use ($user) {
            $q->where('seller_id', $user->id);
        })
        ->with(['items' => function ($q) use ($user) {
            $q->where('seller_id', $user->id)->with('product');
        }, 'user:id,name,email', 'shippingAddress'])
        ->select('orders.*')
        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
        ->where('order_items.seller_id', $user->id)
        ->groupBy('orders.id');

        if ($request->has('status')) {
            $query->where('orders.status', $request->status);
        }

        $orders = $query->orderBy('orders.created_at', 'desc')->paginate(20);

        return response()->json($orders);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $order = Order::with('items')->findOrFail($id);

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'status' => 'required|in:processing,shipped,delivered,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $sellerItems = $order->items->where('seller_id', $user->id);

        if ($sellerItems->isEmpty() && !$user->is_admin) {
            return response()->json(['message' => 'No tienes productos en este pedido'], 403);
        }

        if ($request->status === 'cancelled') {
            foreach ($sellerItems as $item) {
                if ($item->type === 'direct') {
                    $item->product->increment('stock', $item->quantity);
                }
            }
        }

        $order->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Estado actualizado',
            'order' => $order->fresh(),
        ]);
    }

    public function updatePaymentStatus(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $order = Order::findOrFail($id);

        if ($order->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'No tienes permiso'], 403);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'payment_status' => 'required|in:paid,failed,refunded',
            'payment_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $order->update([
            'payment_status' => $request->payment_status,
            'payment_id' => $request->payment_id,
        ]);

        if ($request->payment_status === 'paid') {
            $order->update(['status' => 'processing']);
        }

        return response()->json([
            'message' => 'Pago actualizado',
            'order' => $order->fresh(),
        ]);
    }

    public function cancel(int $id): JsonResponse
    {
        $user = auth()->user();
        $order = Order::findOrFail($id);

        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'No tienes permiso'], 403);
        }

        if (!in_array($order->status, ['pending', 'processing'])) {
            return response()->json(['message' => 'No puedes cancelar este pedido'], 400);
        }

        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                if ($item->type === 'direct') {
                    $item->product->increment('stock', $item->quantity);
                }
            }

            $order->update(['status' => 'cancelled']);
        });

        return response()->json([
            'message' => 'Pedido cancelado',
            'order' => $order->fresh(),
        ]);
    }
}
