<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/producto/{slug}', function ($slug) {
    $filePath = storage_path("app/public/prerendered/producto-{$slug}.html");
    
    if (file_exists($filePath)) {
        return response(file_get_contents($filePath))
            ->header('Content-Type', 'text/html');
    }
    
    abort(404);
});

Route::get('/subasta/{slug}', function ($slug) {
    $filePath = storage_path("app/public/prerendered/subasta-{$slug}.html");
    
    if (file_exists($filePath)) {
        return response(file_get_contents($filePath))
            ->header('Content-Type', 'text/html');
    }
    
    abort(404);
});

Route::get('/download/prerendered', function () {
    $prerenderedDir = storage_path('app/public/prerendered');
    
    if (!file_exists($prerenderedDir)) {
        return response()->json(['error' => 'No hay archivos pre-renderizados'], 404);
    }
    
    $files = glob("{$prerenderedDir}/*.html");
    
    if (empty($files)) {
        return response()->json(['error' => 'No hay archivos pre-renderizados'], 404);
    }
    
    $zipFile = storage_path('app/public/prerendered.zip');
    
    if (file_exists($zipFile)) {
        unlink($zipFile);
    }
    
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
        return response()->json(['error' => 'No se pudo crear el ZIP'], 500);
    }
    
    foreach ($files as $file) {
        $filename = basename($file);
        $zip->addFile($file, $filename);
    }
    
    $zip->close();
    
    return response()->download($zipFile, 'prerendered-pages.zip')->deleteFileAfterSend(true);
});
