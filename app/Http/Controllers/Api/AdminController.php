<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function broadcastNotification(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'nullable|string|max:50',
        ]);

        $users = User::all();
        $type = $request->type ?? 'admin_announcement';

        foreach ($users as $user) {
            Notification::send(
                $user->id,
                $type,
                $request->title,
                $request->message,
                ['is_broadcast' => true]
            );
        }

        return response()->json([
            'message' => 'Notificación enviada a todos los usuarios',
            'count' => $users->count()
        ]);
    }

    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->paginate(20);
        return response()->json($users);
    }

    public function toggleStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'No puedes bloquearte a ti mismo'], 400);
        }

        $user->is_blocked = !$user->is_blocked;
        $user->save();

        return response()->json([
            'message' => $user->is_blocked ? 'Usuario bloqueado' : 'Usuario desbloqueado',
            'user' => $user
        ]);
    }

    public function updateRole(Request $request, $id)
    {
        $request->validate([
            'role_id' => 'required|integer|in:1,2',
        ]);

        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'No puedes cambiar tu propio rol'], 400);
        }

        $user->role_id = $request->role_id;
        $user->is_admin = ($request->role_id === 1);
        $user->save();

        return response()->json([
            'message' => 'Rol actualizado correctamente',
            'user' => $user
        ]);
    }
}
