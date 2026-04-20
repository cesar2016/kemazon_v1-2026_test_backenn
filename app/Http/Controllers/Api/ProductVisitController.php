<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProductVisitController extends Controller
{
    /**
     * Log or update a product visit.
     * Uses a session_id from frontend to track activity duration.
     */
    public function ping(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        $user = Auth::guard('api')->user();
        $ip = $request->ip();
        $sessionId = $request->input('session_id');

        // Rule: Only one valid visit every 4 hours per user/IP
        // We look for a visit created in the last 4 hours
        $visit = ProductVisit::where('product_id', $product->id)
            ->where(function ($query) use ($user, $ip, $sessionId) {
                if ($user) {
                    $query->where('user_id', $user->id);
                } else {
                    $query->where('ip_address', $ip)
                        ->where('user_id', null);
                }
                // If we have a sessionId, we prioritize sticking to that session's visit
                if ($sessionId) {
                    $query->orWhere('session_id', $sessionId);
                }
            })
            ->where('created_at', '>=', Carbon::now()->subHours(4))
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$visit) {
            $payload = [
                'product_id' => $product->id,
                'user_id' => $user ? $user->id : null,
                'ip_address' => $ip,
                'session_id' => $sessionId,
                'duration' => 0,
                'last_active_at' => Carbon::now(),
            ];

            if (ProductVisit::hasIsValidColumn()) {
                $payload['is_valid'] = false;
            }

            $visit = ProductVisit::create($payload);
        }

        // Increment duration (assume ping happens every 5s)
        $visit->duration += 5;
        $visit->last_active_at = Carbon::now();

        // 5 second rule: duration >= 5s
        if (ProductVisit::hasIsValidColumn() && $visit->duration >= 5) {
            $visit->is_valid = true;
        }

        $visit->save();

        return response()->json([
            'status' => 'success',
            'is_valid' => ProductVisit::hasIsValidColumn() ? (bool) $visit->is_valid : $visit->duration >= 5,
            'duration' => $visit->duration,
        ]);
    }

    public function index($productId)
    {
        $product = Product::findOrFail($productId);

        $visitors = $product->visits()
            ->valid()
            ->with('user:id,name,avatar')
            ->get()
            ->map(function ($visit) {
                if ($visit->user) {
                    return [
                        'id' => $visit->user->id,
                        'name' => $visit->user->name,
                        'avatar' => $visit->user->avatar,
                        'type' => 'user',
                        'last_visit' => $visit->created_at->toDateTimeString(),
                    ];
                }
                return [
                    'id' => null,
                    'name' => 'Visitante',
                    'avatar' => null,
                    'type' => 'guest',
                    'last_visit' => $visit->created_at->toDateTimeString(),
                ];
            });

        Log::info('Product visitors fetched', [
            'product_id' => $product->id,
            'auth_user_id' => Auth::guard('api')->id(),
            'visitors_count' => $visitors->count(),
            'visitor_types' => $visitors->pluck('type')->values()->all(),
            'visitor_names' => $visitors->pluck('name')->values()->all(),
        ]);

        return response()->json([
            'visitors' => $visitors,
            'count' => $visitors->count(),
        ]);
    }
}
