<?php

namespace Tests\Feature\Routes;

use App\Http\Controllers\Admin\ChangeUserEmailController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ChangeUserEmailRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_user_email_route_definition(): void
    {
        $route = Route::getRoutes()->getByName('admin.users.email.update');

        if ($route === null) {
            $this->fail('Route [admin.users.email.update] is not defined.');
        }

        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        $this->assertSame('admin/users/{user}/email', $route->uri());
        $this->assertSame(['PATCH'], $methods);
        $this->assertSame(ChangeUserEmailController::class.'@__invoke', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());

        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $forbiddenMethod) {
            $this->assertNotContains($forbiddenMethod, $methods);
        }
    }

    public function test_guest_cannot_change_a_managed_users_email(): void
    {
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'email' => 'guest.protected@example.test',
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
        $before = $this->persistedUserSnapshot($target);

        $response = $this->patchJson("/admin/users/{$target->getKey()}/email", [
            'email' => 'guest.changed@example.test',
        ]);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
        $response->assertUnauthorized();
    }

    public function test_missing_bound_user_returns_not_found_without_changing_existing_users(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $existingUser = $this->createInventoryAgent($organization);
        $beforeActor = $this->persistedUserSnapshot($actor);
        $beforeExistingUser = $this->persistedUserSnapshot($existingUser);
        $userCount = User::query()->count();
        $missingUserId = ((int) User::query()->max('id')) + 1;

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$missingUserId}/email", [
                'email' => 'missing.user@example.test',
            ])
            ->assertNotFound();

        $this->assertSame($beforeActor, $this->persistedUserSnapshot($actor));
        $this->assertSame($beforeExistingUser, $this->persistedUserSnapshot($existingUser));
        $this->assertSame($userCount, User::query()->count());
    }

    public function test_authorized_platform_admin_can_change_email_through_the_named_route(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'name' => 'Managed Inventory Agent',
            'email' => 'original.route.email@example.test',
            'phone' => '+212600000001',
            'password' => 'OriginalPassword1!',
            'is_active' => true,
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'last_login_at' => now()->subDay()->startOfSecond(),
            'remember_token' => 'known-remember-token',
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
        $before = $this->persistedUserSnapshot($target);

        $response = $this->actingAs($actor)->patchJson(
            route('admin.users.email.update', $target),
            ['email' => 'changed.route.email@example.test']
        );

        $response
            ->assertOk()
            ->assertJsonPath('email', 'changed.route.email@example.test')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $after = $this->persistedUserSnapshot($target);

        $this->assertSame('changed.route.email@example.test', $after['email']);
        $this->assertNull($after['email_verified_at']);
        $this->assertNotSame($before['updated_at'], $after['updated_at']);
        $this->assertSame(
            $this->withoutEmailState($before),
            $this->withoutEmailState($after)
        );
    }

    public function test_submitting_the_same_email_through_the_named_route_does_not_save(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'email' => 'same.route.email@example.test',
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
        $before = $this->persistedUserSnapshot($target);

        Event::fake();

        $this->actingAs($actor)
            ->patchJson(route('admin.users.email.update', $target), [
                'email' => 'same.route.email@example.test',
            ])
            ->assertOk();

        $this->assertSame($before, $this->persistedUserSnapshot($target));
        Event::assertNotDispatched('eloquent.saving: '.User::class);
        Event::assertNotDispatched('eloquent.updating: '.User::class);
    }

    public function test_duplicate_email_is_rejected_through_the_real_route(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'email' => 'duplicate.target@example.test',
        ]);
        $owner = User::factory()->create([
            'email' => 'duplicate.owner@example.test',
        ]);
        $beforeTarget = $this->persistedUserSnapshot($target);
        $beforeOwner = $this->persistedUserSnapshot($owner);

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$target->getKey()}/email", [
                'email' => 'duplicate.owner@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame($beforeTarget, $this->persistedUserSnapshot($target));
        $this->assertSame($beforeOwner, $this->persistedUserSnapshot($owner));
    }

    public function test_forbidden_field_is_rejected_through_the_real_route(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'email' => 'forbidden.field.target@example.test',
        ]);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson("/admin/users/{$target->getKey()}/email", [
                'email' => 'valid.changed.email@example.test',
                'role' => User::ROLE_PLATFORM_ADMIN,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_inventory_agent_cannot_change_email_through_the_real_route(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = $this->createInventoryAgent($organization);
        $target = $this->createInventoryAgent($organization, [
            'email' => 'authorization.target@example.test',
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
        $before = $this->persistedUserSnapshot($target);

        $response = $this->actingAs($actor)
            ->patchJson("/admin/users/{$target->getKey()}/email", [
                'email' => 'authorization.changed@example.test',
            ]);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
        $response->assertForbidden();
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

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function withoutEmailState(array $snapshot): array
    {
        unset($snapshot['email'], $snapshot['email_verified_at'], $snapshot['updated_at']);

        return $snapshot;
    }
}
