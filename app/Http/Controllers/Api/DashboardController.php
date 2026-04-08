<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function sellerStats(): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_seller) {
            return response()->json(['message' => 'Debes ser vendedor'], 403);
        }

        $stats = [
            'products' => [
                'total' => Product::where('user_id', $user->id)->count(),
                'active' => Product::where('user_id', $user->id)->where('is_active', true)->count(),
            ],
            'auctions' => [
                'total' => Auction::whereHas('product', fn($q) => $q->where('user_id', $user->id))->count(),
                'active' => Auction::whereHas('product', fn($q) => $q->where('user_id', $user->id))
                    ->where('status', 'active')->count(),
                'ended' => Auction::whereHas('product', fn($q) => $q->where('user_id', $user->id))
                    ->where('status', 'ended')->count(),
            ],
            'sales' => [
                'total_orders' => OrderItem::where('seller_id', $user->id)->count(),
                'pending_orders' => OrderItem::where('seller_id', $user->id)
                    ->whereHas('order', fn($q) => $q->whereIn('status', ['pending', 'processing']))->count(),
                'total_revenue' => OrderItem::where('seller_id', $user->id)
                    ->whereHas('order', fn($q) => $q->where('payment_status', 'paid'))
                    ->sum('total'),
            ],
        ];

        return response()->json(['stats' => $stats]);
    }

    public function recentActivity(): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_seller) {
            return response()->json(['message' => 'Debes ser vendedor'], 403);
        }

        $recentOrders = Order::whereHas('items', fn($q) => $q->where('seller_id', $user->id))
            ->with(['user:id,name', 'items' => fn($q) => $q->where('seller_id', $user->id)->with('product:id,name')])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentAuctions = Auction::whereHas('product', fn($q) => $q->where('user_id', $user->id))
            ->with(['product:id,name', 'bids'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'recent_orders' => $recentOrders,
            'recent_auctions' => $recentAuctions,
        ]);
    }

    public function salesReport(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_seller) {
            return response()->json(['message' => 'Debes ser vendedor'], 403);
        }

        $startDate = $request->get('start_date', now()->startOfMonth());
        $endDate = $request->get('end_date', now()->endOfMonth());

        $sales = OrderItem::where('seller_id', $user->id)
            ->whereHas('order', fn($q) => $q
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('payment_status', 'paid'))
            ->with('product:id,name')
            ->select('product_id', DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(total) as total_sales'))
            ->groupBy('product_id')
            ->orderBy('total_sales', 'desc')
            ->get();

        $totalRevenue = $sales->sum('total_sales');
        $totalQuantity = $sales->sum('total_quantity');

        return response()->json([
            'sales' => $sales,
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_quantity' => $totalQuantity,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    public function adminStats(): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_admin) {
            return response()->json(['message' => 'Solo administradores'], 403);
        }

        $stats = [
            'users' => [
                'total' => \App\Models\User::count(),
                'sellers' => \App\Models\User::where('is_seller', true)->count(),
                'new_this_month' => \App\Models\User::where('created_at', '>=', now()->startOfMonth())->count(),
            ],
            'products' => [
                'total' => Product::count(),
                'active' => Product::where('is_active', true)->count(),
            ],
            'auctions' => [
                'total' => Auction::count(),
                'active' => Auction::where('status', 'active')->count(),
                'ended' => Auction::where('status', 'ended')->count(),
            ],
            'orders' => [
                'total' => Order::count(),
                'pending' => Order::where('status', 'pending')->count(),
                'total_revenue' => Order::where('payment_status', 'paid')->sum('total'),
            ],
        ];

        return response()->json(['stats' => $stats]);
    }
}
