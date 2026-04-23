<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthenticateApi
{
    public function handle(Request $request, Closure $next, ...$guards): Response
    {
        Log::info('[AuthMiddleware] Incoming request: ' . $request->method() . ' ' . $request->path());
        
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                Log::warning("Auth Middleware: User not found for token");
                return response()->json([
                    'message' => 'Usuario no encontrado'
                ], 401);
            }
        } catch (TokenExpiredException $e) {
            Log::warning("Auth Middleware: Token expired");
            return response()->json([
                'message' => 'Token expirado'
            ], 401);
        } catch (TokenInvalidException $e) {
            Log::warning("Auth Middleware: Token invalid");
            return response()->json([
                'message' => 'Token inválido'
            ], 401);
        } catch (JWTException $e) {
            Log::warning("Auth Middleware: Token not provided or malformed", ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Token no proporcionado o mal formado'
            ], 401);
        }

        Log::debug("Auth Middleware: Authentication successful", ['user_id' => $user->id]);
        return $next($request);
    }
}
