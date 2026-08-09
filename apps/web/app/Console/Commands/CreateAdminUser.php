<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    protected $signature = 'mnemosyne:user:create-admin';

    protected $description = 'Interactively create an administrator account (or promote an existing user)';

    public function handle(): int
    {
        $name = (string) $this->ask('Name');
        $email = (string) $this->ask('Email');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $this->warn("A user with email [{$email}] already exists: {$existing->name}");

            if (! $this->confirm('Promote this existing user to admin?')) {
                $this->info('Aborted. No changes made.');

                return self::FAILURE;
            }

            $existing->role = UserRole::Admin;
            $existing->save();

            $this->info("User [{$existing->email}] promoted to admin.");

            return self::SUCCESS;
        }

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $passwordValidator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(12)->letters()->numbers()]],
        );

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! $this->confirm("Create admin account for [{$name} <{$email}>]?")) {
            $this->info('Aborted. No changes made.');

            return self::FAILURE;
        }

        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->role = UserRole::Admin;
        $user->email_verified_at = now();
        $user->save();

        $this->info("Admin account [{$user->email}] created.");

        return self::SUCCESS;
    }
}
