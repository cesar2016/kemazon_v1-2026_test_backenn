<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    private function generateThumbnail(array $images): ?string
    {
        if (empty($images)) {
            return null;
        }
        return $images[0] ?? null;
    }

    private function prepareProductData(array $data): array
    {
        if (isset($data['thumbnail']) && !empty($data['thumbnail'])) {
            $data['thumbnail'] = $data['thumbnail'];
        } elseif (isset($data['images']) && is_array($data['images']) && !empty($data['images'])) {
            $data['thumbnail'] = $this->generateThumbnail($data['images']);
        } else {
            $data['thumbnail'] = null;
        }
        return $data;
    }
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['user:id,name,avatar', 'category:id,name,slug', 'auction'])
            ->withCount([
                'auction as bids_count' => function ($q) {
                    // This is slightly tricky in Eloquent for nested relations in index
                    // but we can use a subquery or a direct count if we use withCount on the auction relation
                }
            ])
            ->active()
            ->select('id', 'name', 'slug', 'price', 'stock', 'type', 'is_active', 'user_id', 'category_id', 'thumbnail', 'created_at');

        // Actually, a better way to get bids_count for the auction relation is to use a specific withCount on the Auction model inside the 'with' of Product
        $query = Product::with([
            'user:id,name,avatar',
            'category:id,name,slug',
            'auction' => function ($q) {
                $q->withCount('bids');
            }
        ])
            ->active()
            ->select('id', 'name', 'slug', 'price', 'stock', 'type', 'is_active', 'user_id', 'category_id', 'thumbnail', 'created_at')
            ->withCount(['likes', 'visits']);

        if ($request->has('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        $sortBy = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = min($request->get('per_page', 20), 50);
        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->with([
                'user:id,name,avatar,created_at',
                'category:id,name,slug',
                'auction',
                'auction.winner:id,name',
                'auction.bids.user:id,name'
            ])
            ->firstOrFail();

        // Proactively manage auction status
        if ($product->auction) {
            $auctionService = new \App\Services\AuctionService();
            $auctionService->activatePending();
            $auctionService->checkAndMarkEnded($product->auction);
            // Refresh to get updated status and winner if it just ended or changed
            $product->load(['auction', 'auction.winner:id,name', 'auction.bids.user:id,name']);
        }

        $productArray = $product->toArray();

        if (isset($productArray['auction']['bids'])) {
            $authUserId = auth('api')->id();
            foreach ($productArray['auction']['bids'] as &$bid) {
                if ($bid['user_id'] != $authUserId) {
                    unset($bid['max_bid']);
                }
            }
        }

        $productArray['likes_count'] = $product->likes_count;
        $productArray['valid_visits_count'] = $product->valid_visits_count;
        $productArray['is_liked'] = $product->isLikedByUser(auth('api')->id(), request()->ip());

        return response()->json(['product' => $productArray]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_seller) {
            return response()->json(['message' => 'Debes ser vendedor para crear productos'], 403);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'string',
            'thumbnail' => 'nullable|string',
            'type' => 'required|in:direct,auction',
            'specifications' => 'nullable|array',
        ];

        if ($request->type === 'direct') {
            $rules['price'] = 'required|numeric|min:0';
            $rules['stock'] = 'required|integer|min:0';
        } else {
            $rules['price'] = 'nullable|numeric|min:0';
            $rules['stock'] = 'nullable|integer|min:0';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $productData = $this->prepareProductData([
            'user_id' => $user->id,
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . uniqid(),
            'category_id' => $request->category_id,
            'description' => $request->description,
            'price' => $request->type === 'direct' ? $request->price : 0,
            'stock' => $request->type === 'direct' ? $request->stock : 0,
            'sku' => 'KMA-' . strtoupper(Str::random(8)),
            'images' => $request->images ?? [],
            'thumbnail' => $request->thumbnail ?? null,
            'type' => $request->type,
            'specifications' => $request->specifications,
            'is_active' => true,
        ]);

        $product = Product::create($productData);

        \App\Models\Notification::send(
            $user->id,
            'new_product',
            '¡Producto publicado!',
            "Tu producto '{$product->name}' ya está disponible para la venta.",
            ['product_id' => $product->id]
        );

        return response()->json([
            'message' => 'Producto creado',
            'product' => $product,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $product = Product::with('auction')->findOrFail($id);

        if ($product->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'No tienes permiso'], 403);
        }

        if ($product->type === 'direct') {
            $rules = [
                'name' => 'sometimes|string|max:255',
                'category_id' => 'nullable|exists:categories,id',
                'description' => 'nullable|string',
                'price' => 'sometimes|numeric|min:0',
                'stock' => 'sometimes|integer|min:0',
                'images' => 'nullable|array',
                'images.*' => 'string',
                'specifications' => 'nullable|array',
                'is_active' => 'sometimes|boolean',
            ];
        } else {
            $auction = $product->auction;

            if ($auction && $auction->bids()->count() > 0) {
                return response()->json([
                    'message' => 'No puedes editar un producto con ofertas activas'
                ], 403);
            }

            if ($auction && $auction->is_active) {
                return response()->json([
                    'message' => 'No puedes editar una subasta mientras está activa'
                ], 403);
            }

            $rules = [
                'name' => 'sometimes|string|max:255',
                'category_id' => 'nullable|exists:categories,id',
                'description' => 'nullable|string',
                'images' => 'nullable|array',
                'images.*' => 'string',
                'specifications' => 'nullable|array',
                'is_active' => 'sometimes|boolean',
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->except(['user_id', 'slug', 'sku', 'type', 'price', 'stock']);
        $data = $this->prepareProductData($data);

        if ($request->has('name') && $request->name !== $product->name) {
            $data['slug'] = Str::slug($request->name) . '-' . uniqid();
        }

        $product->update($data);

        return response()->json([
            'message' => 'Producto actualizado',
            'product' => $product->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        $product = Product::findOrFail($id);

        if ($product->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'No tienes permiso'], 403);
        }

        $product->delete();

        return response()->json(['message' => 'Producto eliminado']);
    }

    public function myProducts(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = $user->products()
            ->with(['category:id,name', 'auction', 'auction.bids'])
            ->select('id', 'name', 'slug', 'price', 'stock', 'type', 'is_active', 'user_id', 'category_id', 'thumbnail', 'created_at', 'updated_at');

        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $products = $query->orderBy('created_at', 'desc')->paginate(20);

        $products->getCollection()->transform(function ($product) {
            $product->auction_with_bids = $product->auction && $product->auction->bids()->count() > 0;
            return $product;
        });

        return response()->json($products);
    }

    public function showById(int $id): JsonResponse
    {
        $user = auth()->user();

        $product = $user->products()
            ->with(['category:id,name,slug', 'auction'])
            ->findOrFail($id);

        return response()->json(['product' => $product]);
    }
}
