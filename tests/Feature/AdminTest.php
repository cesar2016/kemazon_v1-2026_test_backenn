<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function getHeaders(User $user)
    {
        $token = JWTAuth::fromUser($user);
        return [
            'Authorization' => "Bearer $token",
            'Accept' => 'application/json',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Seed some data if necessary, or just create on the fly
    }

    public function test_admin_can_list_users()
    {
        $admin = User::factory()->create(['is_admin' => true, 'role_id' => 1]);
        $users = User::factory()->count(5)->create();

        $response = $this->getJson('/api/admin/users', $this->getHeaders($admin));

        $response->assertStatus(200)
            ->assertJsonCount(6, 'data'); // 5 users + 1 admin
    }

    public function test_non_admin_cannot_list_users()
    {
        $user = User::factory()->create(['is_admin' => false, 'role_id' => 2]);

        $response = $this->getJson('/api/admin/users', $this->getHeaders($user));

        $response->assertStatus(403);
    }

    public function test_admin_can_toggle_user_status()
    {
        $admin = User::factory()->create(['is_admin' => true, 'role_id' => 1]);
        $targetUser = User::factory()->create(['is_blocked' => false]);

        $response = $this->postJson("/api/admin/users/{$targetUser->id}/toggle-status", [], $this->getHeaders($admin));

        $response->assertStatus(200);
        $this->assertTrue($targetUser->fresh()->is_blocked);

        // Toggle back
        $this->postJson("/api/admin/users/{$targetUser->id}/toggle-status", [], $this->getHeaders($admin));
        $this->assertFalse($targetUser->fresh()->is_blocked);
    }

    public function test_admin_can_update_user_role()
    {
        $admin = User::factory()->create(['is_admin' => true, 'role_id' => 1]);
        $targetUser = User::factory()->create(['role_id' => 2, 'is_admin' => false]);

        $response = $this->putJson("/api/admin/users/{$targetUser->id}/role", [
            'role_id' => 1
        ], $this->getHeaders($admin));

        $response->assertStatus(200);
        $this->assertEquals(1, $targetUser->fresh()->role_id);
        $this->assertTrue($targetUser->fresh()->is_admin);
    }

    public function test_admin_can_broadcast_notification()
    {
        $admin = User::factory()->create(['is_admin' => true, 'role_id' => 1]);
        User::factory()->count(3)->create();

        $response = $this->actingAs($admin, 'api')->postJson('/api/admin/notifications/broadcast', [
            'title' => 'Broadcast Title',
            'message' => 'Broadcast Message',
            'type' => 'announcement'
        ], $this->getHeaders($admin));

        $response->assertStatus(200);
        $this->assertEquals(4, Notification::where('title', 'Broadcast Title')->count());
    }
}
