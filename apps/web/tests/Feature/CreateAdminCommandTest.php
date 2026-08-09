<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_administrator(): void
    {
        $this->artisan('mnemosyne:user:create-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password', 'super-secret-password-1')
            ->expectsQuestion('Confirm password', 'super-secret-password-1')
            ->expectsConfirmation('Create admin account for [Alice Admin <alice@example.com>]?', 'yes')
            ->assertSuccessful();

        $user = User::where('email', 'alice@example.com')->firstOrFail();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->isAdmin());
    }

    public function test_it_aborts_without_confirmation(): void
    {
        $this->artisan('mnemosyne:user:create-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password', 'super-secret-password-1')
            ->expectsQuestion('Confirm password', 'super-secret-password-1')
            ->expectsConfirmation('Create admin account for [Alice Admin <alice@example.com>]?', 'no')
            ->assertFailed();

        $this->assertFalse(User::where('email', 'alice@example.com')->exists());
    }

    public function test_it_rejects_mismatched_password_confirmation(): void
    {
        $this->artisan('mnemosyne:user:create-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'alice@example.com')
            ->expectsQuestion('Password', 'super-secret-password-1')
            ->expectsQuestion('Confirm password', 'different-password')
            ->assertFailed();

        $this->assertFalse(User::where('email', 'alice@example.com')->exists());
    }

    public function test_it_promotes_an_existing_user_only_with_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'bob@example.com']);

        $this->artisan('mnemosyne:user:create-admin')
            ->expectsQuestion('Name', 'Bob')
            ->expectsQuestion('Email', 'bob@example.com')
            ->expectsConfirmation('Promote this existing user to admin?', 'no')
            ->assertFailed();

        $this->assertSame(UserRole::User, $user->refresh()->role);

        $this->artisan('mnemosyne:user:create-admin')
            ->expectsQuestion('Name', 'Bob')
            ->expectsQuestion('Email', 'bob@example.com')
            ->expectsConfirmation('Promote this existing user to admin?', 'yes')
            ->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->refresh()->role);
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('mnemosyne:user:create-admin')
            ->expectsQuestion('Name', 'Alice Admin')
            ->expectsQuestion('Email', 'not-an-email')
            ->assertFailed();
    }
}
