<?php

namespace Tests\Feature\Routes;

use App\Http\Controllers\Admin\ChangeUserStatusController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ChangeUserStatusRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_user_status_route_definition(): void
    {
        $route = Route::getRoutes()->getByName('admin.users.status.update');

        if ($route === null) {
            $this->fail('Route [admin.users.status.update] is not defined.');
        }

        $this->assertSame('admin/users/{user}/status', $route->uri());
        $this->assertSame(['PATCH'], array_values(array_diff($route->methods(), ['HEAD'])));
        $this->assertSame(ChangeUserStatusController::class.'@__invoke', $route->getActionName());
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

    public function test_guest_cannot_change_user_status(): void
    {
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUserSnapshot($target);
        $userCount = User::query()->count();

        $response = $this->patchJson("/admin/users/{$target->getKey()}/status", [
            'is_active' => false,
        ]);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
        $this->assertSame($userCount, User::query()->count());
        $response->assertUnauthorized();
    }

    public function test_missing_bound_user_returns_not_found_without_changing_users(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();
        $missingUserId = ((int) User::query()->max('id')) + 1;

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$missingUserId}/status", ['is_active' => false])
            ->assertNotFound();

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
    }

    public function test_platform_admin_can_deactivate_user_through_the_named_route(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization);
        $this->createInventoryAgent($organization);

        $this->assertStatusChange($actor, $target, false);
    }

    public function test_platform_admin_can_reactivate_user_through_the_named_route(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $target = $this->createInventoryAgent(
            Organization::factory()->active()->create(),
            ['is_active' => false]
        );

        $this->assertStatusChange($actor, $target, true);
    }

    public function test_org_admin_can_change_status_in_the_same_organization(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create([
            'is_active' => true,
        ]);
        $target = $this->createInventoryAgent($organization);

        $this->assertStatusChange($actor, $target, false);
    }

    public function test_inventory_agent_cannot_change_another_users_status(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = $this->createInventoryAgent($organization);
        $target = $this->createInventoryAgent($organization);
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $response = $this->actingAs($actor)->patchJson(
            route('admin.users.status.update', $target),
            ['is_active' => false]
        );

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
        $response->assertForbidden();
    }

    public function test_invalid_status_is_rejected_without_changing_users(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $response = $this->actingAs($actor)->patchJson(
            route('admin.users.status.update', $target),
            ['is_active' => 'not-a-boolean']
        );

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
        $response->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_same_status_through_the_named_route_preserves_the_full_raw_snapshot(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->patchJson(route('admin.users.status.update', $target), ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('id', $target->getKey())
            ->assertJsonPath('is_active', true);

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($userCount, User::query()->count());
    }

    private function assertStatusChange(User $actor, User $target, bool $isActive): void
    {
        $before = $this->persistedUsersSnapshot();
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->patchJson(route('admin.users.status.update', $target), ['is_active' => $isActive])
            ->assertOk()
            ->assertJsonPath('id', $target->getKey())
            ->assertJsonPath('is_active', $isActive);

        $this->assertSame($isActive, User::query()->findOrFail($target->getKey())->is_active);
        $after = $this->persistedUsersSnapshot();
        $targetId = $target->getKey();

        unset($before[$targetId]['is_active'], $before[$targetId]['updated_at']);
        unset($after[$targetId]['is_active'], $after[$targetId]['updated_at']);

        $this->assertSame($before, $after);
        $this->assertSame($userCount, User::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createInventoryAgent(Organization $organization, array $attributes = []): User
    {
        return User::factory()->for($organization)->inventoryAgent()->create(array_replace([
            'name' => 'Managed Inventory Agent',
            'phone' => '+212600000001',
            'is_active' => true,
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'last_login_at' => now()->subDay()->startOfSecond(),
            'remember_token' => 'known-remember-token',
            'created_at' => now()->subYears(2)->startOfSecond(),
            'updated_at' => now()->subYear()->startOfSecond(),
        ], $attributes));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function persistedUsersSnapshot(): array
    {
        $snapshot = [];

        foreach (User::query()->orderBy('id')->get() as $user) {
            $snapshot[$user->getKey()] = $this->persistedUserSnapshot($user);
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedUserSnapshot(User $user): array
    {
        return User::query()->findOrFail($user->getKey())->getRawOriginal();
    }
}
