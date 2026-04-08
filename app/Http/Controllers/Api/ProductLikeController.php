<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductLike;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductLikeController extends Controller
{
    public function toggle(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        $user = Auth::guard('api')->user();
        $ip = $request->ip();

        if ($user) {
            $like = ProductLike::where('product_id', $product->id)
                ->where('user_id', $user->id)
                ->first();
        } else {
            $like = ProductLike::where('product_id', $product->id)
                ->where('user_id', null)
                ->where('ip_address', $ip)
                ->first();
        }

        if ($like) {
            $like->delete();
            $message = 'Me gusta eliminado';
            $liked = false;
        } else {
            ProductLike::create([
                'product_id' => $product->id,
                'user_id' => $user ? $user->id : null,
                'ip_address' => $ip,
            ]);
            $message = 'Me gusta agregado';
            $liked = true;
        }

        return response()->json([
            'message' => $message,
            'liked' => $liked,
            'likes_count' => $product->likes()->count(),
        ]);
    }

    public function index($productId)
    {
        $product = Product::findOrFail($productId);

        $likers = $product->likes()
            ->with('user:id,name,avatar')
            ->get()
            ->map(function ($like) {
                if ($like->user) {
                    return [
                        'id' => $like->user->id,
                        'name' => $like->user->name,
                        'avatar' => $like->user->avatar,
                        'type' => 'user',
                    ];
                }
                return [
                    'id' => null,
                    'name' => 'Visitante',
                    'avatar' => null,
                    'type' => 'guest',
                ];
            });

        return response()->json([
            'likers' => $likers,
            'count' => $likers->count(),
        ]);
    }
}
