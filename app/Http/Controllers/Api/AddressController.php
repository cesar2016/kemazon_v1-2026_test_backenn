<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();

        $addresses = Address::where('user_id', $user->id)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['addresses' => $addresses]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'label' => 'sometimes|string|max:50',
            'recipient_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'number' => 'required|string|max:20',
            'floor' => 'nullable|string|max:10',
            'apartment' => 'nullable|string|max:20',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postal_code' => 'required|string|max:10',
            'observations' => 'nullable|string|max:500',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            $user->addresses()->update(['is_default' => false]);
        }

        $validated['user_id'] = $user->id;

        $address = Address::create($validated);

        return response()->json([
            'message' => 'Dirección creada',
            'address' => $address,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $user = auth()->user();

        $address = Address::where('user_id', $user->id)->findOrFail($id);

        return response()->json(['address' => $address]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();

        $address = Address::where('user_id', $user->id)->findOrFail($id);

        $validated = $request->validate([
            'label' => 'sometimes|string|max:50',
            'recipient_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string|max:255',
            'number' => 'sometimes|string|max:20',
            'floor' => 'nullable|string|max:10',
            'apartment' => 'nullable|string|max:20',
            'city' => 'sometimes|string|max:100',
            'state' => 'sometimes|string|max:100',
            'postal_code' => 'sometimes|string|max:10',
            'observations' => 'nullable|string|max:500',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            $user->addresses()->where('id', '!=', $id)->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json([
            'message' => 'Dirección actualizada',
            'address' => $address->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();

        $address = Address::where('user_id', $user->id)->findOrFail($id);

        $address->delete();

        return response()->json(['message' => 'Dirección eliminada']);
    }

    public function setDefault(int $id): JsonResponse
    {
        $user = auth()->user();

        $address = Address::where('user_id', $user->id)->findOrFail($id);

        $user->addresses()->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        return response()->json([
            'message' => 'Dirección设为默认',
            'address' => $address,
        ]);
    }
}
