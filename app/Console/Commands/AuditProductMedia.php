<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

class AuditProductMedia extends Command
{
    protected $signature = 'products:audit-media
        {--limit=200 : Cantidad maxima de productos a auditar}
        {--check-remote : Hace un HEAD/GET para verificar que la URL responda}
        {--timeout=8 : Timeout en segundos para checks remotos}';

    protected $description = 'Audita thumbnails/imagenes de productos y reporta posibles problemas';

    public function handle(): int
    {
        $limit = max((int) $this->option('limit'), 1);
        $checkRemote = (bool) $this->option('check-remote');
        $timeout = max((int) $this->option('timeout'), 1);

        try {
            $products = Product::query()
                ->select(['id', 'name', 'thumbnail', 'images'])
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();
        } catch (QueryException $exception) {
            $this->error('No se pudo consultar la base de datos. Verifica conexion y credenciales.');
            $this->line($exception->getMessage());
            return self::FAILURE;
        }

        if ($products->isEmpty()) {
            $this->info('No hay productos para auditar.');
            return self::SUCCESS;
        }

        $issues = [];

        foreach ($products as $product) {
            $thumbnail = is_string($product->thumbnail) ? trim($product->thumbnail) : null;
            $images = is_array($product->images) ? $product->images : [];
            $firstImage = isset($images[0]) && is_string($images[0]) ? trim($images[0]) : null;

            $productIssues = [];
            $candidateUrl = $thumbnail ?: $firstImage;

            if (!$thumbnail && !$firstImage) {
                $productIssues[] = 'sin_media';
            }

            if ($thumbnail && str_starts_with($thumbnail, 'http://')) {
                $productIssues[] = 'thumbnail_http';
            }

            if ($thumbnail && str_starts_with($thumbnail, '/')) {
                $productIssues[] = 'thumbnail_relativo';
            }

            if ($thumbnail && !$this->isUrlLike($thumbnail) && !str_starts_with($thumbnail, '/')) {
                $productIssues[] = 'thumbnail_formato_invalido';
            }

            if ($firstImage && str_starts_with($firstImage, 'http://')) {
                $productIssues[] = 'first_image_http';
            }

            if ($firstImage && str_starts_with($firstImage, '/')) {
                $productIssues[] = 'first_image_relativa';
            }

            if ($checkRemote && $candidateUrl && $this->isAbsoluteHttpUrl($candidateUrl)) {
                $remoteOk = $this->checkRemoteUrl($candidateUrl, $timeout);
                if (!$remoteOk) {
                    $productIssues[] = 'media_no_accesible';
                }
            }

            if (!empty($productIssues)) {
                $issues[] = [
                    'id' => $product->id,
                    'name' => mb_strimwidth($product->name, 0, 42, '...'),
                    'thumbnail' => $thumbnail ?: '-',
                    'image_1' => $firstImage ?: '-',
                    'issues' => implode(', ', array_unique($productIssues)),
                ];
            }
        }

        $this->newLine();
        $this->info("Productos auditados: {$products->count()}");
        $this->info('Productos con problemas: ' . count($issues));

        if (empty($issues)) {
            $this->newLine();
            $this->info('No se detectaron problemas de media en la muestra auditada.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['ID', 'Nombre', 'Thumbnail', 'Primera Imagen', 'Problemas'],
            array_map(static fn (array $row) => [
                $row['id'],
                $row['name'],
                $row['thumbnail'],
                $row['image_1'],
                $row['issues'],
            ], $issues)
        );

        $this->newLine();
        $this->warn('Leyenda: sin_media, thumbnail_http, thumbnail_relativo, first_image_http, first_image_relativa, media_no_accesible');
        $this->line('Tip: ejecuta con --check-remote para validar URLs contra red.');

        return self::SUCCESS;
    }

    private function isAbsoluteHttpUrl(string $value): bool
    {
        return preg_match('/^https?:\/\//i', $value) === 1;
    }

    private function isUrlLike(string $value): bool
    {
        return $this->isAbsoluteHttpUrl($value) || str_starts_with($value, '/');
    }

    private function checkRemoteUrl(string $url, int $timeout): bool
    {
        try {
            $headResponse = Http::timeout($timeout)->head($url);
            if ($headResponse->successful()) {
                return true;
            }

            // Algunos storage/CDN no aceptan HEAD correctamente.
            $getResponse = Http::timeout($timeout)->get($url);
            return $getResponse->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}

