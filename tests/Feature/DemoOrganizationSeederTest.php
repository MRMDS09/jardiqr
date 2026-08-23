<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DemoOrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DemoOrganizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_blocked_outside_local_environment(): void
    {
        $this->configureValidDemo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Demo data can only be seeded in the local environment.'
        );

        $this->seed(DemoOrganizationSeeder::class);
    }

    public function test_seeder_is_blocked_when_demo_is_disabled(): void
    {
        $this->useLocalEnvironment();

        $this->configureValidDemo([
            'enabled' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Demo organization seeding is disabled.'
        );

        $this->seed(DemoOrganizationSeeder::class);
    }

    public function test_seeder_rejects_an_invalid_organization_slug(): void
    {
        $this->useLocalEnvironment();

        $this->configureValidDemo([
            'organization' => [
                'slug' => 'Invalid Slug!',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Demo organization slug is invalid.'
        );

        $this->seed(DemoOrganizationSeeder::class);
    }

    public function test_demo_users_must_have_different_emails(): void
    {
        $this->useLocalEnvironment();

        $this->configureValidDemo([
            'inventory_agent' => [
                'email' => 'admin@demo.test',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Demo users must have different email addresses.'
        );

        $this->seed(DemoOrganizationSeeder::class);
    }

    public function test_demo_users_must_have_different_passwords(): void
    {
        $this->useLocalEnvironment();

        $this->configureValidDemo([
            'inventory_agent' => [
                'password' => 'Demo_Admin_2026!Safe',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Demo users must have different passwords.'
        );

        $this->seed(DemoOrganizationSeeder::class);
    }

    public function test_seeder_creates_organization_and_secure_users(): void
    {
        $this->useLocalEnvironment();
        $this->configureValidDemo();

        $this->seed(DemoOrganizationSeeder::class);

        $organization = Organization::query()
            ->where('slug', 'oujda-demo')
            ->firstOrFail();

        $admin = User::query()
            ->where('email', 'admin@demo.test')
            ->firstOrFail();

        $agent = User::query()
            ->where('email', 'inventory@demo.test')
            ->firstOrFail();

        $this->assertSame(
            Organization::STATUS_TRIAL,
            $organization->status
        );
        $this->assertNotNull($organization->trial_ends_at);

        $this->assertSame(User::ROLE_ORG_ADMIN, $admin->role);
        $this->assertSame(
            User::ROLE_INVENTORY_AGENT,
            $agent->role
        );

        $this->assertSame(
            $organization->id,
            $admin->organization_id
        );
        $this->assertSame(
            $organization->id,
            $agent->organization_id
        );

        $this->assertTrue($admin->is_active);
        $this->assertTrue($agent->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($agent->email_verified_at);

        $this->assertTrue(Hash::check(
            'Demo_Admin_2026!Safe',
            $admin->password
        ));

        $this->assertTrue(Hash::check(
            'Demo_Agent_2026!Safe',
            $agent->password
        ));

        $this->assertNotSame(
            'Demo_Admin_2026!Safe',
            $admin->password
        );

        $this->assertNotSame(
            'Demo_Agent_2026!Safe',
            $agent->password
        );

        $this->assertTrue(
            $organization->users->contains($admin)
        );
        $this->assertTrue(
            $organization->users->contains($agent)
        );
    }

    public function test_rerunning_seeder_does_not_duplicate_or_reset_data(): void
    {
        $this->useLocalEnvironment();
        $this->configureValidDemo();

        $this->seed(DemoOrganizationSeeder::class);

        $organization = Organization::query()->firstOrFail();
        $admin = User::query()
            ->where('role', User::ROLE_ORG_ADMIN)
            ->firstOrFail();
        $agent = User::query()
            ->where('role', User::ROLE_INVENTORY_AGENT)
            ->firstOrFail();

        $originalTrialEnd = $organization->trial_ends_at?->toDateTimeString();
        $originalAdminHash = $admin->password;
        $originalAgentHash = $agent->password;

        $this->configureValidDemo([
            'org_admin' => [
                'password' => 'Changed_Admin_2026!Safe',
            ],
            'inventory_agent' => [
                'password' => 'Changed_Agent_2026!Safe',
            ],
        ]);

        $this->seed(DemoOrganizationSeeder::class);

        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(2, User::query()->count());

        $organization->refresh();
        $admin->refresh();
        $agent->refresh();

        $this->assertSame(
            $originalTrialEnd,
            $organization->trial_ends_at?->toDateTimeString()
        );
        $this->assertSame($originalAdminHash, $admin->password);
        $this->assertSame($originalAgentHash, $agent->password);
    }

    public function test_conflicting_organization_slug_does_not_create_users(): void
    {
        $this->useLocalEnvironment();
        $this->configureValidDemo();

        Organization::factory()->create([
            'name' => 'Different Organization',
            'slug' => 'oujda-demo',
            'email' => 'different@demo.test',
        ]);

        try {
            $this->seed(DemoOrganizationSeeder::class);

            $this->fail(
                'Seeder should reject a conflicting organization.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The demo organization slug belongs to different data.',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_conflicting_user_email_rolls_back_new_organization(): void
    {
        $this->useLocalEnvironment();
        $this->configureValidDemo();

        User::factory()->inventoryAgent()->create([
            'email' => 'admin@demo.test',
        ]);

        try {
            $this->seed(DemoOrganizationSeeder::class);

            $this->fail(
                'Seeder should reject a conflicting user email.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'A demo email already belongs to another account.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(1, User::query()->count());
    }

    private function useLocalEnvironment(): void
    {
        $this->app->detectEnvironment(
            fn (): string => 'local'
        );
    }

    private function configureValidDemo(
        array $overrides = []
    ): void {
        $defaults = [
            'enabled' => true,

            'organization' => [
                'name' => 'Société de démonstration"',
                'slug' => 'oujda-demo',
                'email' => 'contact@demo.test',
                'phone' => null,
                'trial_days' => 30,
            ],

            'org_admin' => [
                'name' => 'admin',
                'email' => 'admin@demo.test',
                'password' => 'Demo_Admin_2026!Safe',
            ],

            'inventory_agent' => [
                'name' => 'inventory',
                'email' => 'inventory@demo.test',
                'password' => 'Demo_Agent_2026!Safe',
            ],
        ];

        config()->set(
            'jardi.demo',
            array_replace_recursive($defaults, $overrides)
        );
    }
}
