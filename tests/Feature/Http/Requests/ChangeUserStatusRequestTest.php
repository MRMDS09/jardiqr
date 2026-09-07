<?php

namespace Tests\Feature\Http\Requests;

use App\Http\Requests\Admin\ChangeUserStatusRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class ChangeUserStatusRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)
            ->patch(
                '/__tests/admin/users/{user}/status',
                function (ChangeUserStatusRequest $request, User $user): JsonResponse {
                    return response()->json($request->validated());
                }
            );
    }

    #[DataProvider('authorizedChangeProvider')]
    public function test_authorized_status_changes_are_accepted_without_changing_the_target(
        string $scenario,
        bool $isActive
    ): void {
        [$actor, $target] = $this->authorizedChange($scenario);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), ['is_active' => $isActive])
            ->assertOk()
            ->assertExactJson(['is_active' => $isActive]);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function authorizedChangeProvider(): array
    {
        return [
            'platform admin targets organization admin in active organization' => [
                'platform-to-org-admin',
                false,
            ],
            'platform admin targets inventory agent' => ['platform-to-agent', false],
            'platform admin reactivates inactive organization user' => [
                'platform-reactivates-user',
                true,
            ],
            'platform admin targets user in suspended organization' => [
                'platform-to-suspended-user',
                false,
            ],
            'active organization admin targets own inventory agent' => [
                'active-org-admin-to-agent',
                false,
            ],
            'trial organization admin targets own inventory agent' => [
                'trial-org-admin-to-agent',
                false,
            ],
            'organization admin reactivates own inactive inventory agent' => [
                'org-admin-reactivates-agent',
                true,
            ],
        ];
    }

    #[DataProvider('acceptedActiveValueProvider')]
    public function test_boolean_compatible_active_values_are_accepted_and_preserved(mixed $isActive): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), ['is_active' => $isActive])
            ->assertOk()
            ->assertExactJson(['is_active' => $isActive]);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array<string, array{bool|int|string}>
     */
    public static function acceptedActiveValueProvider(): array
    {
        return [
            'boolean true' => [true],
            'boolean false' => [false],
            'integer one' => [1],
            'integer zero' => [0],
            'string one' => ['1'],
            'string zero' => ['0'],
        ];
    }

    public function test_validated_data_contains_only_active_status_and_route_does_not_persist_changes(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(
            Organization::factory()->active()->create(),
            ['is_active' => true]
        );
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'is_active' => false,
                'unrelated_field' => 'ignored value',
            ])
            ->assertOk()
            ->assertExactJson(['is_active' => false]);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    #[DataProvider('unauthorizedChangeProvider')]
    public function test_unauthorized_status_changes_are_forbidden_without_changing_the_target(
        string $scenario
    ): void {
        [$actor, $target] = $this->unauthorizedChange($scenario);
        $before = $this->persistedUserSnapshot($target);

        $this->patchJsonAs($actor, $target, ['is_active' => false])
            ->assertForbidden();

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unauthorizedChangeProvider(): array
    {
        return [
            'guest' => ['guest'],
            'inventory agent actor' => ['inventory-actor'],
            'unknown actor role' => ['unknown-actor'],
            'inactive actor' => ['inactive-actor'],
            'organization admin without organization' => ['orphan-actor'],
            'organization admin in suspended organization' => ['suspended-actor'],
            'organization admin targets agent in another organization' => ['other-organization'],
            'organization admin targets organization admin in same organization' => ['org-admin-target'],
            'organization admin targets own account' => ['org-admin-self'],
            'platform admin targets another platform admin' => ['platform-admin-target'],
            'platform admin targets own account' => ['platform-admin-self'],
            'target has unknown role' => ['unknown-target'],
            'organization target has no organization' => ['orphan-target'],
        ];
    }

    #[DataProvider('invalidActiveValueProvider')]
    public function test_invalid_active_values_are_rejected_without_changing_the_target(
        string $scenario,
        mixed $isActive
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUserSnapshot($target);
        $payload = ['is_active' => $isActive];

        if ($scenario === 'missing-active-status') {
            $payload = [];
        }

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidActiveValueProvider(): array
    {
        return [
            'active status is missing' => ['missing-active-status', null],
            'active status is null' => ['null-active-status', null],
            'active status is empty' => ['empty-active-status', ''],
            'string true is invalid' => ['string-true', 'true'],
            'string false is invalid' => ['string-false', 'false'],
            'string yes is invalid' => ['string-yes', 'yes'],
            'string no is invalid' => ['string-no', 'no'],
            'integer two is invalid' => ['integer-two', 2],
            'negative integer is invalid' => ['negative-integer', -1],
            'array is invalid' => ['array', [true]],
            'object is invalid' => ['object', new stdClass],
        ];
    }

    #[DataProvider('forbiddenFieldProvider')]
    public function test_forbidden_fields_are_rejected_without_changing_the_target(
        string $field,
        mixed $value
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'is_active' => false,
                $field => $value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function forbiddenFieldProvider(): array
    {
        return [
            'name' => ['name', null],
            'email' => ['email', false],
            'phone' => ['phone', ''],
            'password' => ['password', 'attacker-password'],
            'password confirmation' => ['password_confirmation', null],
            'role' => ['role', User::ROLE_PLATFORM_ADMIN],
            'organization id' => ['organization_id', 0],
            'email verification timestamp' => ['email_verified_at', null],
            'last login timestamp' => ['last_login_at', '2026-09-07 12:00:00'],
            'remember token' => ['remember_token', false],
            'id' => ['id', 0],
            'created timestamp' => ['created_at', ''],
            'updated timestamp' => ['updated_at', '2026-09-07 12:00:00'],
        ];
    }

    public function test_authorization_uses_bound_target_before_malicious_payload_role_is_rejected(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'is_active' => false,
                'role' => User::ROLE_PLATFORM_ADMIN,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_authorization_uses_bound_target_before_malicious_organization_is_rejected(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);
        $otherOrganization = Organization::factory()->active()->create();
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'is_active' => false,
                'organization_id' => $otherOrganization->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array{User, User}
     */
    private function authorizedChange(string $scenario): array
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent($organization);

        switch ($scenario) {
            case 'platform-to-org-admin':
                $target = User::factory()->for($organization)->organizationAdmin()->create();
                break;
            case 'platform-reactivates-user':
                $target = User::factory()->for($organization)->organizationAdmin()->inactive()->create();
                break;
            case 'platform-to-suspended-user':
                $suspendedOrganization = Organization::factory()->suspended()->create();
                $target = $this->createInventoryAgent($suspendedOrganization);
                break;
            case 'active-org-admin-to-agent':
                $actor = User::factory()->for($organization)->organizationAdmin()->create();
                break;
            case 'trial-org-admin-to-agent':
                $trialOrganization = Organization::factory()->create();
                $actor = User::factory()->for($trialOrganization)->organizationAdmin()->create();
                $target = $this->createInventoryAgent($trialOrganization);
                break;
            case 'org-admin-reactivates-agent':
                $actor = User::factory()->for($organization)->organizationAdmin()->create();
                $target = User::factory()->for($organization)->inventoryAgent()->inactive()->create();
                break;
        }

        return [$actor, $target];
    }

    /**
     * @return array{User|null, User}
     */
    private function unauthorizedChange(string $scenario): array
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
            case 'org-admin-self':
                $actor = User::factory()->for($organization)->organizationAdmin()->create();
                $target = $actor;
                break;
            case 'platform-admin-target':
                $target = User::factory()->platformAdmin()->create();
                break;
            case 'platform-admin-self':
                $target = $actor;
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

    private function endpoint(User $target): string
    {
        return "/__tests/admin/users/{$target->getKey()}/status";
    }
}
