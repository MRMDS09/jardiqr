<?php

namespace Tests\Feature\Http\Requests;

use App\Http\Requests\Admin\ChangeUserEmailRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChangeUserEmailRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)
            ->patch(
                '/__tests/admin/users/{user}/email',
                function (ChangeUserEmailRequest $request, User $user): JsonResponse {
                    return response()->json($request->validated());
                }
            );
    }

    public function test_platform_admin_can_submit_a_new_email_for_an_inventory_agent(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk()
            ->assertExactJson($this->validPayload());
    }

    public function test_platform_admin_can_submit_a_new_email_for_an_inactive_organization_user(): void
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

    public function test_organization_admin_can_submit_a_new_email_for_own_inventory_agent(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk();
    }

    public function test_trial_organization_admin_can_submit_a_new_email_for_own_inventory_agent(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();
        $target = $this->createInventoryAgent($organization);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload())
            ->assertOk();
    }

    public function test_target_current_email_is_allowed_and_ignored_by_unique_rule(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(
            Organization::factory()->active()->create(),
            ['email' => 'current.email@example.test']
        );

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'email' => 'current.email@example.test',
            ])
            ->assertOk()
            ->assertExactJson(['email' => 'current.email@example.test']);
    }

    public function test_validated_data_contains_only_email_and_route_does_not_persist_changes(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(
            Organization::factory()->active()->create(),
            [
                'email' => 'original.email@example.test',
                'email_verified_at' => now(),
            ]
        );
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'email' => 'changed.email@example.test',
                'unrelated_field' => 'ignored value',
            ])
            ->assertOk()
            ->assertExactJson(['email' => 'changed.email@example.test']);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    #[DataProvider('unauthorizedChangeProvider')]
    public function test_unauthorized_email_changes_are_forbidden(string $scenario): void
    {
        [$actor, $target] = $this->unauthorizedChange($scenario);
        $before = $this->persistedUserSnapshot($target);

        $this->patchJsonAs($actor, $target, $this->validPayload())
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
            'orphan organization admin' => ['orphan-actor'],
            'suspended organization admin' => ['suspended-actor'],
            'organization admin targets another organization' => ['other-organization'],
            'organization admin targets organization admin in same organization' => ['org-admin-target'],
            'platform admin targets platform admin' => ['platform-admin-target'],
            'target has unknown role' => ['unknown-target'],
            'organization target has no organization' => ['orphan-target'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function test_invalid_email_is_rejected_without_changing_the_target(
        string $scenario,
        mixed $email
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'email' => 'original.email@example.test',
        ]);
        $before = $this->persistedUserSnapshot($target);
        $payload = ['email' => $email];

        if ($scenario === 'missing-email') {
            $payload = [];
        }

        if ($scenario === 'duplicate-email') {
            User::factory()->create(['email' => 'existing.email@example.test']);
        }

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'email is missing' => ['missing-email', null],
            'email is null' => ['null-email', null],
            'email is empty' => ['empty-email', ''],
            'email is whitespace' => ['whitespace-email', '   '],
            'email must be a string' => ['array-email', ['invalid@example.test']],
            'email must be valid' => ['invalid-email', 'not-an-email'],
            'email must be lowercase' => ['uppercase-email', 'Changed.Email@example.test'],
            'email maximum is 255' => ['long-email', str_repeat('a', 256).'@example.test'],
            'email must be unique' => ['duplicate-email', 'existing.email@example.test'],
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
            ->patchJson($this->endpoint($target), $this->validPayload([
                $field => $value,
            ]))
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
            'phone' => ['phone', ''],
            'role' => ['role', User::ROLE_PLATFORM_ADMIN],
            'organization id' => ['organization_id', 0],
            'active status' => ['is_active', false],
            'password' => ['password', ''],
            'password confirmation' => ['password_confirmation', null],
            'email verification timestamp' => ['email_verified_at', null],
            'last login timestamp' => ['last_login_at', ''],
            'remember token' => ['remember_token', null],
            'id' => ['id', 0],
            'created timestamp' => ['created_at', null],
            'updated timestamp' => ['updated_at', ''],
        ];
    }

    public function test_authorization_uses_bound_target_before_payload_role_is_rejected(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload([
                'role' => User::ROLE_PLATFORM_ADMIN,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_authorization_uses_bound_target_before_payload_organization_is_rejected(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);
        $otherOrganization = Organization::factory()->active()->create();
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), $this->validPayload([
                'organization_id' => $otherOrganization->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'email' => 'changed.email@example.test',
        ], $overrides);
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
        return "/__tests/admin/users/{$target->getKey()}/email";
    }
}
