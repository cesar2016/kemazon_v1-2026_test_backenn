<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'avatar' => $request->avatar,
            'is_seller' => false,
            'is_admin' => false,
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => $user,
            'token' => $token,
            'token_type' => 'bearer',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $credentials = $request->only('email', 'password');

        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        $user = auth()->user();

        return response()->json([
            'message' => 'Login exitoso',
            'user' => $user,
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }

    public function logout(): JsonResponse
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json(['message' => 'Sesión cerrada exitosamente']);
    }

    public function refresh(): JsonResponse
    {
        $token = JWTAuth::refresh(JWTAuth::getToken());

        return response()->json([
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }

    public function me(): JsonResponse
    {
        return response()->json(['user' => auth()->user()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'document_type' => 'nullable|string|max:20',
            'document_number' => 'nullable|string|max:20',
            'avatar' => 'nullable|string',
            'mercadopago_access_token' => 'nullable|string',
            'mercadopago_public_key' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'email', 'phone', 'document_type', 'document_number', 'avatar', 'mercadopago_access_token', 'mercadopago_public_key']);

        // Remove MP token if it's empty or placeholder-like string (to prevent clearing it)
        if (empty($data['mercadopago_access_token']) || $data['mercadopago_access_token'] === '••••••••••••') {
            unset($data['mercadopago_access_token']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Perfil actualizado',
            'user' => $user->fresh(),
        ]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        Log::info('[UpdateAvatar] Request received');

        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $avatar = $request->input('avatar');

        // Check if it's a base64 image
        if (str_starts_with($avatar, 'data:image')) {
            // Validate base64 image size (approx 4MB after decode)
            $base64 = substr($avatar, strpos($avatar, ',') + 1);
            $decoded = base64_decode($base64, true);
            if ($decoded === false || strlen($decoded) > 4096000) {
                return response()->json(['message' => 'Imagen demasiado grande o inválida'], 422);
            }
            $user->update(['avatar' => $avatar]);
        } else {
            // If it's not base64, just store as is (URL or path)
            $user->update(['avatar' => $avatar]);
        }

        return response()->json([
            'message' => 'Avatar actualizado',
            'user' => $user->fresh(),
        ]);
    }

    public function becomeSeller(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->is_seller) {
            return response()->json(['message' => 'Ya eres vendedor'], 400);
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->update([
            'is_seller' => true,
            'phone' => $request->phone,
        ]);

        return response()->json([
            'message' => 'Ahora eres vendedor',
            'user' => $user->fresh(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Contraseña actual incorrecta'], 400);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Contraseña actualizada']);
    }
}
