<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class PlatformAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_blocked_when_bootstrap_is_disabled(): void
    {
        $this->configureAdmin([
            'enabled' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Platform admin bootstrap is disabled.'
        );

        $this->seed(PlatformAdminSeeder::class);
    }

    public function test_seeder_rejects_an_invalid_email(): void
    {
        $this->configureAdmin([
            'email' => 'invalid-email',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Platform administrator email is invalid.'
        );

        $this->seed(PlatformAdminSeeder::class);
    }

    public function test_seeder_rejects_a_short_password(): void
    {
        $this->configureAdmin([
            'password' => 'Short1!',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Platform administrator password must contain at least 16 characters.'
        );

        $this->seed(PlatformAdminSeeder::class);
    }

    public function test_seeder_rejects_a_weak_password(): void
    {
        $this->configureAdmin([
            'password' => 'abcdefghijklmnop',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Platform administrator password does not meet complexity requirements.'
        );

        $this->seed(PlatformAdminSeeder::class);
    }

    public function test_seeder_creates_a_secure_platform_admin(): void
    {
        $password = 'Secure_Admin_2026!';

        $this->configureAdmin([
            'password' => $password,
        ]);

        $this->seed(PlatformAdminSeeder::class);

        $user = User::query()
            ->where('email', 'admin@example.test')
            ->firstOrFail();

        $this->assertSame('Platform Administrator', $user->name);
        $this->assertSame(User::ROLE_PLATFORM_ADMIN, $user->role);
        $this->assertNull($user->organization_id);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);

        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_rerunning_seeder_does_not_duplicate_or_reset_account(): void
    {
        $originalPassword = 'Original_Admin_2026!';
        $newPassword = 'Different_Admin_2026!';

        $this->configureAdmin([
            'password' => $originalPassword,
        ]);

        $this->seed(PlatformAdminSeeder::class);

        $originalHash = User::query()
            ->where('email', 'admin@example.test')
            ->value('password');

        $this->configureAdmin([
            'password' => $newPassword,
        ]);

        $this->seed(PlatformAdminSeeder::class);

        $user = User::query()
            ->where('email', 'admin@example.test')
            ->firstOrFail();

        $this->assertSame(1, User::query()->count());
        $this->assertSame($originalHash, $user->password);
        $this->assertTrue(
            Hash::check($originalPassword, $user->password)
        );
        $this->assertFalse(
            Hash::check($newPassword, $user->password)
        );
    }

    public function test_existing_regular_user_is_not_promoted(): void
    {
        $this->configureAdmin();

        $user = User::factory()->inventoryAgent()->create([
            'email' => 'admin@example.test',
        ]);

        try {
            $this->seed(PlatformAdminSeeder::class);

            $this->fail(
                'Seeder should not promote an existing regular user.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The configured email already belongs to another account.',
                $exception->getMessage()
            );
        }

        $user->refresh();

        $this->assertSame(User::ROLE_INVENTORY_AGENT, $user->role);
        $this->assertNull($user->organization_id);
    }

    public function test_seeder_prevents_a_second_platform_admin(): void
    {
        User::factory()->platformAdmin()->create([
            'email' => 'existing-admin@example.test',
        ]);

        $this->configureAdmin([
            'email' => 'second-admin@example.test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Another platform administrator already exists.'
        );

        $this->seed(PlatformAdminSeeder::class);
    }

    private function configureAdmin(array $overrides = []): void
    {
        config()->set('jardi.bootstrap_admin', array_merge([
            'enabled' => true,
            'name' => 'Platform Administrator',
            'email' => 'admin@example.test',
            'password' => 'Secure_Admin_2026!',
        ], $overrides));
    }
}
