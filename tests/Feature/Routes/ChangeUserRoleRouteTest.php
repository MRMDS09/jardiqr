<?php

namespace Tests\Feature\Routes;

use App\Http\Controllers\Admin\ChangeUserRoleController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ChangeUserRoleRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_user_role_route_definition(): void
    {
        $route = Route::getRoutes()->getByName('admin.users.role.update');

        if ($route === null) {
            $this->fail('Route [admin.users.role.update] is not defined.');
        }

        $this->assertSame('admin/users/{user}/role', $route->uri());
        $this->assertSame('admin.users.role.update', $route->getName());
        $this->assertSame(ChangeUserRoleController::class.'@__invoke', $route->getActionName());
        $this->assertSame(['PATCH'], $route->methods());
        $this->assertSame(['user'], $route->parameterNames());

        $middleware = $route->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertNotContains('verified', $middleware);
        $this->assertNotContains('role', $middleware);

        foreach ($middleware as $name) {
            $this->assertFalse(str_starts_with($name, 'role:'));
        }
    }

    public function test_guest_cannot_change_user_role(): void
    {
        $organization = Organization::factory()->active()->create();
        $target = $this->createOrganizationUser($organization);
        $this->createOrganizationUser($organization);
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $response = $this->patchJson("/admin/users/{$target->getKey()}/role", [
            'role' => User::ROLE_ORG_ADMIN,
        ]);

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
        $response->assertUnauthorized();
    }

    public function test_missing_bound_user_returns_not_found_without_changing_users(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $this->createOrganizationUser(Organization::factory()->active()->create());
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();
        $missingUserId = ((int) User::query()->max('id')) + 1;

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$missingUserId}/role", ['role' => User::ROLE_ORG_ADMIN])
            ->assertNotFound();

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
    }

    public function test_platform_admin_can_promote_inventory_agent_through_the_named_route(): void
    {
        $this->assertRoleChange(User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN);
    }

    public function test_platform_admin_can_demote_org_admin_through_the_named_route(): void
    {
        $this->assertRoleChange(User::ROLE_ORG_ADMIN, User::ROLE_INVENTORY_AGENT);
    }

    public function test_same_role_preserves_the_full_raw_snapshot(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $target = $this->createOrganizationUser(Organization::factory()->active()->create());
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->patchJson(route('admin.users.role.update', $target), ['role' => $target->role])
            ->assertOk()
            ->assertJsonPath('id', $target->getKey())
            ->assertJsonPath('role', $target->role);

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
    }

    public function test_org_admin_cannot_promote_inventory_agent_in_the_same_organization(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = $this->createOrganizationUser($organization, User::ROLE_ORG_ADMIN);
        $target = $this->createOrganizationUser($organization);
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $response = $this->actingAs($actor)->patchJson(
            route('admin.users.role.update', $target),
            ['role' => User::ROLE_ORG_ADMIN]
        );

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
        $response->assertForbidden();
    }

    public function test_platform_admin_role_is_rejected_without_changing_users(): void
    {
        $this->assertInvalidPayload(['role' => User::ROLE_PLATFORM_ADMIN], 'role');
    }

    public function test_organization_id_is_rejected_without_changing_users(): void
    {
        $otherOrganization = Organization::factory()->active()->create();

        $this->assertInvalidPayload([
            'role' => User::ROLE_ORG_ADMIN,
            'organization_id' => $otherOrganization->getKey(),
        ], 'organization_id');
    }

    private function assertRoleChange(string $originalRole, string $newRole): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $organization = Organization::factory()->active()->create();
        $target = $this->createOrganizationUser($organization, $originalRole);
        $this->createOrganizationUser($organization);
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->patchJson(route('admin.users.role.update', $target), ['role' => $newRole])
            ->assertOk()
            ->assertJsonPath('id', $target->getKey())
            ->assertJsonPath('role', $newRole)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $this->assertSame($newRole, User::query()->findOrFail($target->getKey())->role);
        $after = $this->persistedUsersSnapshot();
        $targetId = $target->getKey();

        unset($before[$targetId]['role'], $before[$targetId]['updated_at']);
        unset($after[$targetId]['role'], $after[$targetId]['updated_at']);

        $this->assertSame($before, $after);
        $this->assertSame($userCount, User::query()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertInvalidPayload(array $payload, string $error): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $target = $this->createOrganizationUser(Organization::factory()->active()->create());
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $response = $this->actingAs($actor)->patchJson(
            route('admin.users.role.update', $target),
            $payload
        );

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
        $response->assertUnprocessable()->assertJsonValidationErrors($error);
    }

    private function createOrganizationUser(
        Organization $organization,
        string $role = User::ROLE_INVENTORY_AGENT
    ): User {
        return User::factory()->for($organization)->create([
            'role' => $role,
            'is_active' => true,
            'phone' => '+212600000001',
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'last_login_at' => now()->subDay()->startOfSecond(),
            'remember_token' => 'known-remember-token',
            'created_at' => now()->subYears(2)->startOfSecond(),
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function persistedUsersSnapshot(): array
    {
        $snapshot = [];

        foreach (User::query()->orderBy('id')->get() as $user) {
            $attributes = $user->getRawOriginal();
            ksort($attributes);
            $snapshot[$user->getKey()] = $attributes;
        }

        ksort($snapshot);

        return $snapshot;
    }
}
