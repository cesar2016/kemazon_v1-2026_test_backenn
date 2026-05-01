<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    private function currentRequestOrigin(): ?string
    {
        if (!request()) {
            return null;
        }

        $forwardedProto = request()->headers->get('x-forwarded-proto');
        $scheme = $forwardedProto ? trim(explode(',', $forwardedProto)[0]) : request()->getScheme();
        $host = request()->headers->get('x-forwarded-host')
            ? trim(explode(',', request()->headers->get('x-forwarded-host'))[0])
            : request()->getHost();
        $port = request()->headers->get('x-forwarded-port') ?: request()->getPort();

        if (!$host) {
            return null;
        }

        $origin = $scheme . '://' . $host;
        $isStandardPort = ($scheme === 'http' && (int) $port === 80)
            || ($scheme === 'https' && (int) $port === 443);

        if ($port && !$isStandardPort && !str_contains($host, ':')) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private function buildAbsoluteStorageUrl(string $path): string
    {
        $relativePath = '/' . ltrim($path, '/');
        $baseUrl = config('app.frontend_url', config('app.url'));
        
        return rtrim($baseUrl, '/') . $relativePath;
    }

    private function getThumbnailSource(array $data): ?string
    {
        if (!empty($data['thumbnail'])) {
            return $data['thumbnail'];
        }

        if (!empty($data['images']) && is_array($data['images'])) {
            return $data['images'][0] ?? null;
        }

        return null;
    }

    private function isGeneratedThumbnailPath(?string $thumbnail): bool
    {
        return is_string($thumbnail) && str_contains($thumbnail, '/storage/uploads/product-thumbnails/');
    }

    private function removeGeneratedThumbnail(?string $thumbnail): void
    {
        if (!$this->isGeneratedThumbnailPath($thumbnail)) {
            return;
        }

        $relativePath = ltrim(str_replace('/storage/', '', $thumbnail), '/');

        if ($relativePath !== '' && Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    private function getBinaryImageContents(string $source): ?string
    {
        if (str_starts_with($source, 'data:image/')) {
            $parts = explode(',', $source, 2);

            if (count($parts) !== 2) {
                return null;
            }

            return base64_decode($parts[1], true) ?: null;
        }

        if (str_starts_with($source, '/storage/')) {
            $relativePath = ltrim(str_replace('/storage/', '', $source), '/');

            if (Storage::disk('public')->exists($relativePath)) {
                return Storage::disk('public')->get($relativePath);
            }
        }

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return @file_get_contents($source) ?: null;
        }

        if (is_file($source)) {
            return file_get_contents($source) ?: null;
        }

        return null;
    }

    private function generateThumbnailImage(string $binaryContents): ?string
    {
        $sourceImage = @imagecreatefromstring($binaryContents);

        if (!$sourceImage) {
            return null;
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($sourceImage);
            return null;
        }

        $targetWidth = 1200;
        $targetHeight = 630;
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $srcX = (int) round(($sourceWidth - $cropWidth) / 2);
            $srcY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $srcX = 0;
            $srcY = (int) round(($sourceHeight - $cropHeight) / 2);
        }

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $background = imagecolorallocate($targetImage, 255, 255, 255);
        imagefill($targetImage, 0, 0, $background);

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            $srcX,
            $srcY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );

        ob_start();
        imagejpeg($targetImage, null, 88);
        $thumbnailBinary = ob_get_clean() ?: null;

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        return $thumbnailBinary;
    }

    private function storeGeneratedThumbnail(array $data, ?Product $existingProduct = null): ?string
    {
        $source = $this->getThumbnailSource($data);

        if (!$source) {
            if ($existingProduct) {
                $this->removeGeneratedThumbnail($existingProduct->thumbnail);
            }

            return null;
        }

        if (str_starts_with($source, 'data:image/')) {
            return $source;
        }

        if ($existingProduct) {
            $this->removeGeneratedThumbnail($existingProduct->thumbnail);
        }

        return $source;
    }

    private function prepareProductData(array $data): array
    {
        if (empty($data['thumbnail']) && !empty($data['images']) && is_array($data['images'])) {
            $data['thumbnail'] = $data['images'][0] ?? null;
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
        $hasUsersTable = Schema::hasTable('users');

        $relations = [
            'category:id,name,slug',
            'auction',
        ];

        if ($hasUsersTable) {
            $relations[] = 'user:id,name,avatar,created_at';
            $relations[] = 'auction.winner:id,name';
            $relations[] = 'auction.bids.user:id,name';
        }

        $product = Product::where('slug', $slug)
            ->with($relations)
            ->firstOrFail();

        // Proactively manage auction status
        if ($product->auction) {
            $auctionService = new \App\Services\AuctionService();
            $auctionService->activatePending();
            $auctionService->checkAndMarkEnded($product->auction);
            // Refresh to get updated status and winner if it just ended or changed
            $product->load($hasUsersTable
                ? ['auction', 'auction.winner:id,name', 'auction.bids.user:id,name']
                : ['auction']
            );
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
        Log::info('[ProductController@store] Starting...');
        
        try {
            $user = auth()->user();
            Log::info('[ProductController@store] User authenticated: ' . $user->id);

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
                $rules['stock'] = 'required|integer|min:0';
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
                'price' => $request->price ?? 0,
                'stock' => $request->stock ?? 0,
                'sku' => 'KMA-' . strtoupper(Str::random(8)),
                'images' => $request->images ?? [],
                'thumbnail' => $request->thumbnail ?? null,
                'type' => $request->type,
                'specifications' => $request->specifications,
                'is_active' => true,
            ]);

            $product = Product::create($productData);

            try {
                $generatedThumbnail = $this->storeGeneratedThumbnail($productData, $product);
                if ($generatedThumbnail !== $product->thumbnail) {
                    $product->update(['thumbnail' => $generatedThumbnail]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to generate thumbnail during product creation: ' . $e->getMessage());
            }

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
        } catch (\Exception $e) {
            Log::error('Error in ProductController@store: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['message' => 'Error interno del servidor'], 500);
        }
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
                'thumbnail' => 'nullable|string',
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

            $rules = [
                'name' => 'sometimes|string|max:255',
                'category_id' => 'nullable|exists:categories,id',
                'description' => 'nullable|string',
                'images' => 'nullable|array',
                'images.*' => 'string',
                'thumbnail' => 'nullable|string',
                'specifications' => 'nullable|array',
                'is_active' => 'sometimes|boolean',
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->except(['user_id', 'slug', 'sku', 'type', 'stock', 'price']);
        
        if ($product->type === 'auction') {
            $data['stock'] = 1;
            $data['price'] = 0;
        }
        
        Log::info('[UPDATE PRODUCT] Raw data keys: ' . implode(',', array_keys($data)));
        Log::info('[UPDATE PRODUCT] thumbnail in request: ' . ($data['thumbnail'] ?? 'NOT SET'));
        Log::info('[UPDATE PRODUCT] images count: ' . (isset($data['images']) ? count($data['images']) : 0));
        
        $data = $this->prepareProductData($data);
        
        Log::info('[UPDATE PRODUCT] After prepareProductData, thumbnail: ' . ($data['thumbnail'] ?? 'NOT SET'));
            
        $product->update($data);
        
        $newThumbnail = $data['thumbnail'] ?? null;
        
        Log::info('[UPDATE PRODUCT] Current thumbnail in DB: ' . ($product->thumbnail ?? 'NOT SET'));
        Log::info('[UPDATE PRODUCT] New thumbnail to set: ' . ($newThumbnail ?? 'NOT SET'));
        Log::info('[UPDATE PRODUCT] Are they different? ' . ($newThumbnail !== $product->thumbnail ? 'YES' : 'NO'));
        
        if ($newThumbnail && $newThumbnail !== $product->thumbnail) {
            Log::info('[UPDATE PRODUCT] Updating thumbnail to: ' . $newThumbnail);
            $product->update(['thumbnail' => $newThumbnail]);
        } elseif (!$newThumbnail && !empty($data['images'])) {
            $newThumbnail = $data['images'][0];
            if ($newThumbnail !== $product->thumbnail) {
                Log::info('[UPDATE PRODUCT] Using first image as thumbnail: ' . $newThumbnail);
                $product->update(['thumbnail' => $newThumbnail]);
            }
        }
        
        $updatedProduct = $product->fresh();
        Log::info('[UPDATE PRODUCT] Final thumbnail in DB: ' . ($updatedProduct->thumbnail ?? 'NOT SET'));
        
        return response()->json([
            'message' => 'Producto actualizado',
            'product' => $updatedProduct,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        $product = Product::findOrFail($id);

        if ($product->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'No tienes permiso'], 403);
        }

        $this->removeGeneratedThumbnail($product->thumbnail);
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

    public function getImageBySlug(string $slug): \Illuminate\Http\Response
    {
        $product = Product::where('slug', $slug)->firstOrFail();
        
        $imageSource = $product->thumbnail ?: ($product->images[0] ?? null);

        if (!$imageSource) {
            abort(404, 'Imagen no encontrada');
        }

        $imageData = null;
        $mimeType = 'image/jpeg';

        if (str_starts_with($imageSource, 'data:image/')) {
            $parts = explode(',', $imageSource, 2);
            if (count($parts) === 2) {
                $imageData = base64_decode($parts[1]);
                $mimeType = str_replace('data:', '', str_replace(';base64', '', $parts[0])) ?: 'image/jpeg';
            }
        }

        if (!$imageData && str_starts_with($imageSource, '/storage/')) {
            $relativePath = ltrim(str_replace('/storage/', '', $imageSource), '/');
            if (Storage::disk('public')->exists($relativePath)) {
                $imageData = Storage::disk('public')->get($relativePath);
            }
        }

        if (!$imageData && filter_var($imageSource, FILTER_VALIDATE_URL)) {
            $imageData = @file_get_contents($imageSource);
        }

        if (!$imageData) {
            abort(404, 'Imagen no encontrada');
        }

        return response($imageData, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
    
    public function getImage(int $id): \Illuminate\Http\Response
    {
        $product = Product::findOrFail($id);
        $imageSource = $product->thumbnail ?: ($product->images[0] ?? null);

        if (!$imageSource) {
            abort(404, 'Imagen no encontrada');
        }

        $imageData = null;
        $mimeType = 'image/jpeg';

        if (str_starts_with($imageSource, 'data:image/')) {
            $parts = explode(',', $imageSource, 2);
            if (count($parts) === 2) {
                $imageData = base64_decode($parts[1]);
                $mimeType = str_replace('data:', '', str_replace(';base64', '', $parts[0])) ?: 'image/jpeg';
            }
        }

        if (!$imageData && str_starts_with($imageSource, '/storage/')) {
            $relativePath = ltrim(str_replace('/storage/', '', $imageSource), '/');
            if (Storage::disk('public')->exists($relativePath)) {
                $imageData = Storage::disk('public')->get($relativePath);
            }
        }

        if (!$imageData && filter_var($imageSource, FILTER_VALIDATE_URL)) {
            $imageData = @file_get_contents($imageSource);
        }

        if (!$imageData) {
            abort(404, 'Imagen no encontrada');
        }

        return response($imageData, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
