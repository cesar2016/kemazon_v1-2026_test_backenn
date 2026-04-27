<?php

use Illuminate\Support\Facades\Route;

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
