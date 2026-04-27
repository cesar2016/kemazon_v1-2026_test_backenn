<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GeneratePrerenderedPages extends Command
{
    protected $signature = 'seo:generate-prerendered';
    protected $description = 'Generate pre-rendered HTML pages for SEO/Open Graph sharing';

    public function handle(): int
    {
        $this->info('Generando páginas pre-renderizadas...');

        $products = Product::with('category', 'auction')
            ->where('is_active', true)
            ->get();

        $frontendUrl = config('app.frontend_url', 'https://kemazon.ar');
        $count = 0;

        foreach ($products as $product) {
            $this->generatePage($product, $frontendUrl);
            $count++;
            $this->line("  - {$product->name}");
        }

        $this->info("Generados {$count} archivos HTML pre-renderizados.");

        return Command::SUCCESS;
    }

    private function generatePage(Product $product, string $frontendUrl): void
    {
        $slug = $product->slug;
        $name = htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars(
            substr($product->description ?? "Producto en KEMAZON.ar", 0, 160),
            ENT_QUOTES,
            'UTF-8'
        );
        $price = number_format($product->price, 0, ',', '.');
        $type = $product->type;

        $pageUrl = rtrim($frontendUrl, '/') . "/producto/{$slug}";
        $imageUrl = rtrim(config('app.url'), '/') . "/api/products/image/{$slug}";
        $badge = $type === 'auction' ? 'Subasta KEMAZON.ar' : 'Producto KEMAZON.ar';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$name} | KEMAZON.ar</title>
    <meta name="description" content="{$description}">
    
    <meta property="og:type" content="product">
    <meta property="og:title" content="{$name}">
    <meta property="og:description" content="{$description}">
    <meta property="og:image" content="{$imageUrl}">
    <meta property="og:url" content="{$pageUrl}">
    <meta property="og:site_name" content="KEMAZON.ar">
    <meta property="product:price:amount" content="{$product->price}">
    <meta property="product:price:currency" content="ARS">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$name}">
    <meta name="twitter:description" content="{$description}">
    <meta name="twitter:image" content="{$imageUrl}">
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .card img {
            width: 100%;
            height: auto;
            aspect-ratio: 1.91/1;
            object-fit: cover;
        }
        .card-content { padding: 24px; }
        h1 { font-size: 24px; color: #111; margin-bottom: 12px; line-height: 1.3; }
        .description { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 16px; }
        .price { font-size: 32px; font-weight: 800; color: #4f46e5; margin-bottom: 20px; }
        .badge {
            display: inline-block;
            padding: 6px 14px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(79, 70, 229, 0.4);
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="{$imageUrl}" alt="{$name}" onerror="this.src='https://via.placeholder.com/600x315/f3f4f6/9ca3af?text=Imagen+no+disponible'">
        <div class="card-content">
            <span class="badge">{$badge}</span>
            <h1>{$name}</h1>
            <p class="description">{$description}</p>
            <div class="price">\$ {$price}</div>
            <a href="{$pageUrl}" class="btn">Ver en KEMAZON.ar</a>
        </div>
    </div>
</body>
</html>
HTML;

        $path = "prerendered/producto-{$slug}.html";
        Storage::disk('public')->put($path, $html);
        
        // Also generate auction version if it's an auction
        if ($type === 'auction') {
            $auctionPageUrl = rtrim($frontendUrl, '/') . "/subasta/{$slug}";
            $auctionHtml = str_replace(
                ['/producto/', 'producto-'],
                ['/subasta/', 'subasta-'],
                $html
            );
            $auctionHtml = str_replace(
                'Subasta KEMAZON.ar',
                'Subasta KEMAZON.ar',
                $auctionHtml
            );
            $auctionPath = "prerendered/subasta-{$slug}.html";
            Storage::disk('public')->put($auctionPath, $auctionHtml);
        }
    }
}