<?php

namespace Tests\Feature\Routes;

use App\Http\Controllers\Admin\OrganizationUserController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationUserRoutesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('organizationUserRouteProvider')]
    public function test_organization_user_route_definition(
        string $name,
        string $uri,
        string $method,
        string $action
    ): void {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            $this->fail("Route [{$name}] is not defined.");
        }

        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        $this->assertSame($uri, $route->uri());
        $this->assertSame([$method], $methods);
        $this->assertSame($action, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function organizationUserRouteProvider(): array
    {
        return [
            'store' => [
                'admin.organizations.users.store',
                'admin/organizations/{organization}/users',
                'POST',
                OrganizationUserController::class.'@store',
            ],
            'update' => [
                'admin.users.update',
                'admin/users/{user}',
                'PATCH',
                OrganizationUserController::class.'@update',
            ],
        ];
    }

    public function test_guest_cannot_store_an_organization_user(): void
    {
        $organization = Organization::factory()->active()->create();
        $userCount = User::query()->count();

        $this->postJson(
            "/admin/organizations/{$organization->getKey()}/users",
            $this->validStorePayload()
        )->assertUnauthorized();

        $this->assertDatabaseCount('users', $userCount);
        $this->assertDatabaseMissing('users', ['email' => 'route.user@example.test']);
    }

    public function test_guest_cannot_update_an_organization_user(): void
    {
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'name' => 'Original User',
            'phone' => '+212600000001',
        ]);
        $before = $this->persistedUserSnapshot($target);

        $this->patchJson("/admin/users/{$target->getKey()}", [
            'name' => 'Unauthorized Update',
            'phone' => '+212699999999',
        ])->assertUnauthorized();

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_store_returns_not_found_for_a_missing_bound_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $missingOrganizationId = ((int) Organization::query()->max('id')) + 1;
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->postJson(
                "/admin/organizations/{$missingOrganizationId}/users",
                $this->validStorePayload()
            )
            ->assertNotFound();

        $this->assertDatabaseCount('users', $userCount);
        $this->assertDatabaseMissing('users', ['email' => 'route.user@example.test']);
    }

    public function test_update_returns_not_found_for_a_missing_bound_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $existingUser = $this->createInventoryAgent($organization);
        $before = $this->persistedUserSnapshot($existingUser);
        $userCount = User::query()->count();
        $missingUserId = ((int) User::query()->max('id')) + 1;

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$missingUserId}", [
                'name' => 'Missing User',
                'phone' => '+212611111111',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('users', $userCount);
        $this->assertSame($before, $this->persistedUserSnapshot($existingUser));
    }

    public function test_authorized_user_can_store_through_the_named_route(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $password = 'ValidPassword1!';
        $payload = $this->validStorePayload(['password' => $password]);
        $userCount = User::query()->count();

        $response = $this->actingAs($actor)
            ->postJson(route('admin.organizations.users.store', $organization), $payload);

        $response
            ->assertCreated()
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $this->assertDatabaseCount('users', $userCount + 1);

        $created = User::query()->where('email', $payload['email'])->sole();

        $this->assertSame($organization->getKey(), $created->organization_id);
        $this->assertNotSame($password, $created->password);
        $this->assertTrue(Hash::check($password, $created->password));
    }

    public function test_authorized_user_can_update_through_the_named_route(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'name' => 'Original User',
            'email' => 'existing.route.user@example.test',
            'phone' => '+212600000001',
            'is_active' => true,
        ]);
        $originalEmail = $target->email;
        $originalRole = $target->role;
        $originalOrganizationId = $target->organization_id;
        $originalIsActive = $target->is_active;

        $this->actingAs($actor)
            ->patchJson(route('admin.users.update', $target), [
                'name' => 'Updated User',
                'phone' => '+212611111111',
            ])
            ->assertOk();

        $target->refresh();

        $this->assertSame('Updated User', $target->name);
        $this->assertSame('+212611111111', $target->phone);
        $this->assertSame($originalEmail, $target->email);
        $this->assertSame($originalRole, $target->role);
        $this->assertSame($originalOrganizationId, $target->organization_id);
        $this->assertSame($originalIsActive, $target->is_active);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validStorePayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Route User',
            'email' => 'route.user@example.test',
            'phone' => '+212600000000',
            'password' => 'ValidPassword1!',
            'password_confirmation' => 'ValidPassword1!',
            'role' => User::ROLE_INVENTORY_AGENT,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createInventoryAgent(Organization $organization, array $attributes = []): User
    {
        return User::factory()
            ->for($organization)
            ->inventoryAgent()
            ->create($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedUserSnapshot(User $user): array
    {
        $freshUser = User::query()->findOrFail($user->getKey());
        $attributes = [
            'id',
            'organization_id',
            'name',
            'email',
            'phone',
            'password',
            'role',
            'is_active',
            'email_verified_at',
            'last_login_at',
            'remember_token',
            'created_at',
            'updated_at',
        ];
        $snapshot = [];

        foreach ($attributes as $attribute) {
            $snapshot[$attribute] = $freshUser->getRawOriginal($attribute);
        }

        return $snapshot;
    }
}
