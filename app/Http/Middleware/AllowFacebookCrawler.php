<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowFacebookCrawler
{
    public function handle(Request $request, Closure $next)
    {
        $userAgent = $request->header('User-Agent', '');

        if (str_contains($userAgent, 'facebookexternalhit') || 
            str_contains($userAgent, 'Facebot') ||
            str_contains($userAgent, 'WhatsApp')) {
            return $next($request);
        }

        return $next($request);
    }
}