<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_the_user_role(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::User, $user->refresh()->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->update(['role' => UserRole::Admin->value]);

        $this->assertSame(UserRole::User, $user->refresh()->role);
    }

    public function test_guests_are_redirected_away_from_admin_pages(): void
    {
        $this->get('/admin/system')->assertRedirect('/login');
    }

    public function test_plain_users_receive_403_on_admin_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/system')->assertForbidden();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/admin/processing')->assertForbidden();
    }

    public function test_admins_can_access_admin_pages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/system')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->get('/admin/processing')->assertOk();
    }

    public function test_authenticated_users_can_access_application_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/library')->assertOk();
        $this->actingAs($user)->get('/search')->assertOk();
        $this->actingAs($user)->get('/analyses')->assertOk();
    }
}
