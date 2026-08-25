<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware([
            'web',
            'auth',
            'role:'.User::ROLE_PLATFORM_ADMIN,
        ])->get('/testing/platform-management', fn () => response('OK'));

        Route::middleware([
            'web',
            'auth',
            'role:'.User::ROLE_PLATFORM_ADMIN.','.User::ROLE_ORG_ADMIN,
        ])->get('/testing/organization-management', fn () => response('OK'));

        Route::middleware([
            'web',
            'auth',
            'role:'.User::ROLE_PLATFORM_ADMIN.','.User::ROLE_ORG_ADMIN.','.User::ROLE_INVENTORY_AGENT,
        ])->get('/testing/inventory-management', fn () => response('OK'));

        Route::middleware([
            'web',
            'auth',
            'role',
        ])->get('/testing/no-configured-role', fn () => response('OK'));

        Route::middleware([
            'web',
            'auth',
            'role:unknown-role',
        ])->get('/testing/unknown-configured-role', fn () => response('OK'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/testing/platform-management')
            ->assertRedirect(route('login'));
    }

    public function test_platform_admin_can_access_platform_management(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)
            ->get('/testing/platform-management')
            ->assertOk();
    }

    public function test_organization_admin_cannot_access_platform_management(): void
    {
        $user = $this->createOrganizationUser(User::ROLE_ORG_ADMIN);

        $this->actingAs($user)
            ->get('/testing/platform-management')
            ->assertForbidden();
    }

    public function test_inventory_agent_cannot_access_platform_management(): void
    {
        $user = $this->createOrganizationUser(User::ROLE_INVENTORY_AGENT);

        $this->actingAs($user)
            ->get('/testing/platform-management')
            ->assertForbidden();
    }

    public function test_platform_admin_can_access_organization_management(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)
            ->get('/testing/organization-management')
            ->assertOk();
    }

    public function test_organization_admin_can_access_organization_management(): void
    {
        $user = $this->createOrganizationUser(User::ROLE_ORG_ADMIN);

        $this->actingAs($user)
            ->get('/testing/organization-management')
            ->assertOk();
    }

    public function test_inventory_agent_cannot_access_organization_management(): void
    {
        $user = $this->createOrganizationUser(User::ROLE_INVENTORY_AGENT);

        $this->actingAs($user)
            ->get('/testing/organization-management')
            ->assertForbidden();
    }

    public function test_all_known_roles_can_access_inventory_management(): void
    {
        $users = [
            User::factory()->platformAdmin()->create(),
            $this->createOrganizationUser(User::ROLE_ORG_ADMIN),
            $this->createOrganizationUser(User::ROLE_INVENTORY_AGENT),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->get('/testing/inventory-management')
                ->assertOk();
        }
    }

    public function test_unknown_role_cannot_access_any_management_zone(): void
    {
        $organization = Organization::factory()->active()->create();
        $user = User::factory()->for($organization)->create([
            'role' => 'unknown-role',
        ]);

        foreach ([
            '/testing/platform-management',
            '/testing/organization-management',
            '/testing/inventory-management',
        ] as $uri) {
            $this->actingAs($user)
                ->get($uri)
                ->assertRedirect(route('login'));

            $this->assertGuest();
        }
    }

    public function test_role_middleware_without_configured_roles_denies_access_by_default(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)
            ->get('/testing/no-configured-role')
            ->assertForbidden();
    }

    public function test_platform_admin_is_denied_when_only_unknown_role_is_configured(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)
            ->get('/testing/unknown-configured-role')
            ->assertForbidden();
    }

    private function createOrganizationUser(string $role): User
    {
        $organization = Organization::factory()->active()->create();
        $factory = User::factory()->for($organization);

        $factory = match ($role) {
            User::ROLE_ORG_ADMIN => $factory->organizationAdmin(),
            User::ROLE_INVENTORY_AGENT => $factory->inventoryAgent(),
        };

        return $factory->create();
    }
}
