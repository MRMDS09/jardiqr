<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('jardi.bootstrap_admin.enabled')) {
            throw new RuntimeException(
                'Platform admin bootstrap is disabled.'
            );
        }

        $name = trim((string) config('jardi.bootstrap_admin.name'));
        $email = strtolower(trim(
            (string) config('jardi.bootstrap_admin.email')
        ));
        $password = (string) config('jardi.bootstrap_admin.password');

        $this->validateSettings($name, $email, $password);

        DB::transaction(function () use ($name, $email, $password): void {
            $existingUser = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($existingUser !== null) {
                $this->validateExistingUser($existingUser);

                $this->command?->warn(
                    'Platform administrator already exists. No changes were made.'
                );

                return;
            }

            $anotherPlatformAdminExists = User::query()
                ->where('role', User::ROLE_PLATFORM_ADMIN)
                ->exists();

            if ($anotherPlatformAdminExists) {
                throw new RuntimeException(
                    'Another platform administrator already exists.'
                );
            }

            $user = new User;
            $user->name = $name;
            $user->email = $email;
            $user->email_verified_at = now();
            $user->password = Hash::make($password);
            $user->organization_id = null;
            $user->phone = null;
            $user->role = User::ROLE_PLATFORM_ADMIN;
            $user->is_active = true;
            $user->last_login_at = null;
            $user->save();

            $this->command?->info(
                'Platform administrator created successfully.'
            );
        });
    }

    private function validateSettings(
        string $name,
        string $email,
        string $password
    ): void {
        if ($name === '' || mb_strlen($name) > 150) {
            throw new RuntimeException(
                'Platform administrator name is missing or too long.'
            );
        }

        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($email) > 190
        ) {
            throw new RuntimeException(
                'Platform administrator email is invalid.'
            );
        }

        if (strlen($password) < 16) {
            throw new RuntimeException(
                'Platform administrator password must contain at least 16 characters.'
            );
        }

        if (
            preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1
            || preg_match('/[^a-zA-Z0-9]/', $password) !== 1
        ) {
            throw new RuntimeException(
                'Platform administrator password does not meet complexity requirements.'
            );
        }
    }

    private function validateExistingUser(User $user): void
    {
        if (
            $user->role !== User::ROLE_PLATFORM_ADMIN
            || $user->organization_id !== null
        ) {
            throw new RuntimeException(
                'The configured email already belongs to another account.'
            );
        }
    }
}
