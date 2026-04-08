<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::with('children')
            ->whereNull('parent_id')
            ->active()
            ->orderBy('sort_order')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function show(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->with(['parent', 'children', 'products' => function ($query) {
                $query->active()->limit(10);
            }])
            ->firstOrFail();

        return response()->json(['category' => $category]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_admin) {
            return response()->json(['message' => 'Solo administradores pueden crear categorías'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'image' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer',
        ]);

        $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        $validated['is_active'] = true;

        $category = Category::create($validated);

        return response()->json([
            'message' => 'Categoría creada',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_admin) {
            return response()->json(['message' => 'Solo administradores pueden editar categorías'], 403);
        }

        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
            'image' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $category->update($validated);

        return response()->json([
            'message' => 'Categoría actualizada',
            'category' => $category->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();

        if (!$user->is_admin) {
            return response()->json(['message' => 'Solo administradores pueden eliminar categorías'], 403);
        }

        $category = Category::findOrFail($id);

        if ($category->products()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar una categoría con productos'], 400);
        }

        $category->delete();

        return response()->json(['message' => 'Categoría eliminada']);
    }
}
