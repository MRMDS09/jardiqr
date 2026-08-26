<?php

namespace Tests\Feature\Http\Requests;

use App\Http\Requests\Admin\UpdateManagedUserRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateManagedUserRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)
            ->patch(
                '/testing/managed-users/{user}',
                function (UpdateManagedUserRequest $request, User $user): JsonResponse {
                    return response()->json($request->validated());
                }
            );
    }

    public function test_platform_admin_can_update_name_and_phone_for_an_organization_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk()
            ->assertExactJson($this->validPayload());
    }

    public function test_platform_admin_can_update_an_inactive_inventory_agent(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = User::factory()
            ->for($organization)
            ->inventoryAgent()
            ->inactive()
            ->create();

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk();
    }

    public function test_organization_admin_can_update_inventory_agent_in_own_active_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk();
    }

    public function test_authorized_organization_admin_receives_validation_error_for_payload_role(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload([
                'role' => User::ROLE_PLATFORM_ADMIN,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_authorized_organization_admin_receives_validation_error_for_payload_organization_id(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);
        $otherOrganization = Organization::factory()->active()->create();

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload([
                'organization_id' => $otherOrganization->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');
    }

    public function test_organization_admin_in_trial_organization_can_update_own_inventory_agent(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();
        $target = $this->createInventoryAgent($organization);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk();
    }

    public function test_phone_may_be_null(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload(['phone' => null]))
            ->assertOk()
            ->assertExactJson([
                'name' => 'Updated User Name',
                'phone' => null,
            ]);
    }

    public function test_phone_may_be_omitted(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $payload = $this->validPayload();
        unset($payload['phone']);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $payload)
            ->assertOk()
            ->assertExactJson(['name' => 'Updated User Name']);
    }

    public function test_validated_data_contains_only_name_and_phone(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk()
            ->assertExactJson([
                'name' => 'Updated User Name',
                'phone' => '+212611111111',
            ]);
    }

    public function test_test_route_does_not_persist_changes_to_the_target_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(
            Organization::factory()->active()->create(),
            ['name' => 'Original Name', 'phone' => '+212600000000']
        );

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk();

        $target->refresh();

        $this->assertSame('Original Name', $target->name);
        $this->assertSame('+212600000000', $target->phone);
    }

    #[DataProvider('unauthorizedUpdateProvider')]
    public function test_unauthorized_updates_are_forbidden(string $scenario): void
    {
        [$actor, $target] = $this->unauthorizedUpdate($scenario);

        $this->patchJsonAs($actor, $target, $this->validPayload())
            ->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unauthorizedUpdateProvider(): array
    {
        return [
            'guest' => ['guest'],
            'inventory agent actor' => ['inventory-actor'],
            'unknown actor role' => ['unknown-actor'],
            'inactive actor' => ['inactive-actor'],
            'orphan organization admin' => ['orphan-actor'],
            'suspended organization admin' => ['suspended-actor'],
            'organization admin targets another organization' => ['other-organization'],
            'organization admin targets organization admin in same organization' => ['org-admin-target'],
            'platform admin targets platform admin' => ['platform-admin-target'],
            'target has unknown role' => ['unknown-target'],
            'organization target has no organization' => ['orphan-target'],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected(
        string $scenario,
        array $overrides,
        string $field
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $payload = array_replace($this->validPayload(), $overrides);

        if ($scenario === 'missing-name') {
            unset($payload['name']);
        }

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'name is required' => ['missing-name', [], 'name'],
            'name cannot be empty' => ['empty-name', ['name' => ''], 'name'],
            'name cannot contain only whitespace' => ['whitespace-name', ['name' => '   '], 'name'],
            'name must be a string' => ['array-name', ['name' => ['Updated User Name']], 'name'],
            'name maximum is 255' => ['long-name', ['name' => str_repeat('a', 256)], 'name'],
            'phone must be a string' => ['array-phone', ['phone' => ['+212611111111']], 'phone'],
            'phone maximum is 30' => ['long-phone', ['phone' => str_repeat('1', 31)], 'phone'],
            'email is prohibited' => ['email', ['email' => 'attacker@example.test'], 'email'],
            'role is prohibited' => ['role', ['role' => User::ROLE_ORG_ADMIN], 'role'],
            'organization id is prohibited' => ['organization-id', ['organization_id' => 999], 'organization_id'],
            'active status is prohibited' => ['is-active', ['is_active' => true], 'is_active'],
            'password is prohibited' => ['password', ['password' => 'ValidPassword1!'], 'password'],
            'password confirmation is prohibited' => [
                'password-confirmation',
                ['password_confirmation' => 'ValidPassword1!'],
                'password_confirmation',
            ],
            'email verification timestamp is prohibited' => [
                'email-verified-at',
                ['email_verified_at' => '2026-08-26 12:00:00'],
                'email_verified_at',
            ],
            'last login timestamp is prohibited' => [
                'last-login-at',
                ['last_login_at' => '2026-08-26 12:00:00'],
                'last_login_at',
            ],
            'remember token is prohibited' => [
                'remember-token',
                ['remember_token' => 'attacker-controlled-token'],
                'remember_token',
            ],
            'id is prohibited' => ['id', ['id' => 999], 'id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Updated User Name',
            'phone' => '+212611111111',
        ], $overrides);
    }

    /**
     * @return array{User|null, User}
     */
    private function unauthorizedUpdate(string $scenario): array
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent($organization);

        switch ($scenario) {
            case 'guest':
                $actor = null;
                break;
            case 'inventory-actor':
                $actor = $this->createInventoryAgent($organization);
                break;
            case 'unknown-actor':
                $actor = User::factory()->for($organization)->create(['role' => 'unknown-role']);
                break;
            case 'inactive-actor':
                $actor = User::factory()->platformAdmin()->inactive()->create();
                break;
            case 'orphan-actor':
                $actor = User::factory()->organizationAdmin()->create(['organization_id' => null]);
                break;
            case 'suspended-actor':
                $suspendedOrganization = Organization::factory()->suspended()->create();
                $actor = User::factory()
                    ->for($suspendedOrganization)
                    ->organizationAdmin()
                    ->create();
                break;
            case 'other-organization':
                $actorOrganization = Organization::factory()->active()->create();
                $actor = User::factory()->for($actorOrganization)->organizationAdmin()->create();
                break;
            case 'org-admin-target':
                $actor = User::factory()->for($organization)->organizationAdmin()->create();
                $target = User::factory()->for($organization)->organizationAdmin()->create();
                break;
            case 'platform-admin-target':
                $target = User::factory()->platformAdmin()->create();
                break;
            case 'unknown-target':
                $target = User::factory()->for($organization)->create(['role' => 'unknown-role']);
                break;
            case 'orphan-target':
                $target = User::factory()->inventoryAgent()->create(['organization_id' => null]);
                break;
        }

        return [$actor, $target];
    }

    private function patchJsonAs(?User $actor, User $target, array $payload): TestResponse
    {
        if ($actor !== null) {
            $this->actingAs($actor);
        }

        return $this->patchJson($this->endpoint($target), $payload);
    }

    /**
     * @return array{User, Organization}
     */
    private function createOrganizationAdmin(): array
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();

        return [$actor, $organization];
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

    private function endpoint(User $target): string
    {
        return "/testing/managed-users/{$target->getKey()}";
    }
}
