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
        $origin = $this->currentRequestOrigin();

        if ($origin) {
            return rtrim($origin, '/') . $relativePath;
        }

        return url($relativePath);
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
        return is_string($thumbnail) && str_contains($thumbnail, '/storage/product-thumbnails/');
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

        $binaryContents = $this->getBinaryImageContents($source);

        if (!$binaryContents) {
            Log::warning('Product thumbnail source could not be read', [
                'product_id' => $existingProduct?->id,
                'source_preview' => Str::limit($source, 80),
            ]);

            return $source;
        }

        $thumbnailBinary = $this->generateThumbnailImage($binaryContents);

        if (!$thumbnailBinary) {
            Log::warning('Product thumbnail could not be generated, keeping original source', [
                'product_id' => $existingProduct?->id,
            ]);

            return $source;
        }

        if ($existingProduct) {
            $this->removeGeneratedThumbnail($existingProduct->thumbnail);
        }

        $filename = 'product-thumbnails/' . ($existingProduct?->id ?? 'new') . '-' . Str::uuid() . '.jpg';
        Storage::disk('public')->put($filename, $thumbnailBinary);

        return $this->buildAbsoluteStorageUrl('/storage/' . $filename);
    }

    private function prepareProductData(array $data): array
    {
        // Save base64 thumbnail to storage and replace with URL
        if (!empty($data['thumbnail']) && str_starts_with($data['thumbnail'], 'data:image/')) {
            $savedUrl = $this->saveBase64Image($data['thumbnail']);
            if ($savedUrl) {
                $data['thumbnail'] = $savedUrl;
            }
            return $data;
        }
        
        // Only get thumbnail from images if no thumbnail is set at all
        if (empty($data['thumbnail']) && !empty($data['images']) && is_array($data['images'])) {
            $data['thumbnail'] = $data['images'][0] ?? null;
        }
        
        return $data;
    }
    
    private function saveBase64Image(string $base64Image): ?string
    {
        try {
            $parts = explode(',', $base64Image, 2);
            if (count($parts) !== 2) {
                return null;
            }
            
            $binary = base64_decode($parts[1], true);
            if (!$binary) {
                return null;
            }
            
            // Create image resource from binary data
            $image = imagecreatefromstring($binary);
            if (!$image) {
                return null;
            }
            
            // Get original dimensions
            $width = imagesx($image);
            $height = imagesy($image);
            
            // Calculate new dimensions (max 600px, maintain aspect ratio)
            $maxSize = 600;
            if ($width > $maxSize || $height > $maxSize) {
                if ($width > $height) {
                    $newWidth = $maxSize;
                    $newHeight = (int) ($height * ($maxSize / $width));
                } else {
                    $newHeight = $maxSize;
                    $newWidth = (int) ($width * ($maxSize / $height));
                }
                
                // Create resized image
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                
                // Fill white background for transparent images
                $white = imagecolorallocate($resized, 255, 255, 255);
                imagefill($resized, 0, 0, $white);
                
                // Resize with high quality
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resized;
            }
            
            // Compress and save as JPEG
            $filename = 'product-thumbnails/' . Str::uuid() . '.jpg';
            ob_start();
            imagejpeg($image, null, 85);
            $compressed = ob_get_clean();
            imagedestroy($image);
            
            if ($compressed) {
                Storage::disk('public')->put($filename, $compressed);
                return '/storage/' . $filename;
            }
            
            return null;
        } catch (\Exception $e) {
            Log::warning('Failed to save base64 image', ['error' => $e->getMessage()]);
            return null;
        }
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

        $generatedThumbnail = $this->storeGeneratedThumbnail($productData, $product);
        if ($generatedThumbnail !== $product->thumbnail) {
            $product->update(['thumbnail' => $generatedThumbnail]);
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
                'thumbnail' => 'nullable|string',
                'specifications' => 'nullable|array',
                'is_active' => 'sometimes|boolean',
            ];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->except(['user_id', 'slug', 'sku', 'type']);
        
        \Illuminate\Support\Facades\Log::info('[UPDATE] Request all keys: ' . implode(',', array_keys($request->all())));
        \Illuminate\Support\Facades\Log::info('[UPDATE] Data before prepare: ' . json_encode($data));
        
        $data = $this->prepareProductData($data);

        if ($request->has('name') && $request->name !== $product->name) {
            $data['slug'] = Str::slug($request->name) . '-' . uniqid();
        }

        $product->update($data);
        
        \Illuminate\Support\Facades\Log::info('[UPDATE] After update, product: ' . json_encode($product->fresh()->toArray()));
        
        // Only generate thumbnail if not explicitly provided
        $refreshedProduct = $product->fresh();
        if (!empty($data['thumbnail'])) {
            // Use explicitly provided thumbnail, no need to generate
            $generatedThumbnail = $data['thumbnail'];
        } else {
            $generatedThumbnail = $this->storeGeneratedThumbnail($refreshedProduct->toArray(), $refreshedProduct);
        }
        
        if ($generatedThumbnail !== $refreshedProduct->thumbnail) {
            $refreshedProduct->update(['thumbnail' => $generatedThumbnail]);
        }

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
}
