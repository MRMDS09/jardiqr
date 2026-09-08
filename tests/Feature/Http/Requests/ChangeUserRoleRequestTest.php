<?php

namespace Tests\Feature\Http\Requests;

use App\Http\Requests\Admin\ChangeUserRoleRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class ChangeUserRoleRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)->patch(
            '/_tests/admin/users/{user}/role',
            function (ChangeUserRoleRequest $request, User $user): JsonResponse {
                return response()->json($request->validated());
            }
        );
    }

    #[DataProvider('authorizedChangeProvider')]
    public function test_authorized_changes_validate_only_role_without_persisting(
        string $currentRole,
        string $newRole,
        bool $active,
        string $status
    ): void {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $organization = Organization::factory()->create(['status' => $status]);
        $target = $this->createTarget($organization, ['role' => $currentRole, 'is_active' => $active]);

        $this->patchWithoutChanges($actor, $target, ['role' => $newRole])
            ->assertOk()->assertExactJson(['role' => $newRole]);
    }

    public static function authorizedChangeProvider(): array
    {
        return [
            'promote agent' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, true, Organization::STATUS_ACTIVE],
            'demote organization admin' => [User::ROLE_ORG_ADMIN, User::ROLE_INVENTORY_AGENT, true, Organization::STATUS_ACTIVE],
            'same role' => [User::ROLE_INVENTORY_AGENT, User::ROLE_INVENTORY_AGENT, true, Organization::STATUS_ACTIVE],
            'inactive target' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, false, Organization::STATUS_ACTIVE],
            'trial organization' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, true, Organization::STATUS_TRIAL],
            'suspended organization' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, true, Organization::STATUS_SUSPENDED],
        ];
    }

    public function test_global_middleware_trims_role_before_validation(): void
    {
        [$actor, $target] = $this->authorizedPair();

        $this->patchWithoutChanges($actor, $target, [
            'role' => 'org_admin ',
        ])
            ->assertOk()
            ->assertExactJson([
                'role' => User::ROLE_ORG_ADMIN,
            ]);
    }

    public function test_validated_data_excludes_unrelated_fields(): void
    {
        [$actor, $target] = $this->authorizedPair();

        $this->patchWithoutChanges($actor, $target, [
            'role' => User::ROLE_ORG_ADMIN,
            'unrelated_field' => 'ignored',
        ])->assertOk()->assertExactJson(['role' => User::ROLE_ORG_ADMIN]);
    }

    #[DataProvider('unauthorizedChangeProvider')]
    public function test_unauthorized_changes_are_forbidden(string $scenario, string $newRole): void
    {
        [$actor, $target] = $this->unauthorizedPair($scenario);

        $this->patchWithoutChanges($actor, $target, ['role' => $newRole])->assertForbidden();
    }

    public static function unauthorizedChangeProvider(): array
    {
        return [
            'guest' => ['guest', User::ROLE_ORG_ADMIN],
            'inventory agent actor' => ['inventory-actor', User::ROLE_ORG_ADMIN],
            'organization admin promotes own agent' => ['same-organization', User::ROLE_ORG_ADMIN],
            'organization admin targets another organization' => ['other-organization', User::ROLE_INVENTORY_AGENT],
            'unknown actor role' => ['unknown-actor', User::ROLE_ORG_ADMIN],
            'inactive platform admin' => ['inactive-platform', User::ROLE_ORG_ADMIN],
            'inactive organization admin' => ['inactive-org', User::ROLE_ORG_ADMIN],
            'organization admin without organization' => ['orphan-actor', User::ROLE_ORG_ADMIN],
            'suspended organization admin' => ['suspended-actor', User::ROLE_ORG_ADMIN],
            'platform admin target' => ['platform-target', User::ROLE_ORG_ADMIN],
            'platform admin targets self' => ['self', User::ROLE_ORG_ADMIN],
            'unknown target role' => ['unknown-target', User::ROLE_ORG_ADMIN],
            'target without organization' => ['orphan-target', User::ROLE_ORG_ADMIN],
        ];
    }

    #[DataProvider('invalidRoleProvider')]
    public function test_organization_admin_is_forbidden_before_role_validation(array $payload): void
    {
        [$actor, $target] = $this->unauthorizedPair('same-organization');

        $this->patchWithoutChanges($actor, $target, $payload)->assertForbidden();
    }

    #[DataProvider('invalidRoleProvider')]
    public function test_authorized_actor_receives_validation_errors_for_original_invalid_role(array $payload): void
    {
        [$actor, $target] = $this->authorizedPair();

        $this->patchWithoutChanges($actor, $target, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public static function invalidRoleProvider(): array
    {
        return [
            'missing' => [[]],
            'null' => [['role' => null]],
            'empty' => [['role' => '']],
            'whitespace' => [['role' => '   ']],
            'integer' => [['role' => 1]],
            'boolean' => [['role' => true]],
            'array' => [['role' => [User::ROLE_ORG_ADMIN]]],
            'object' => [['role' => (object) ['value' => User::ROLE_ORG_ADMIN]]],
            'platform admin' => [['role' => User::ROLE_PLATFORM_ADMIN]],
            'unknown' => [['role' => 'unknown-role']],
            'uppercase' => [['role' => 'ORG_ADMIN']],
            'near match' => [['role' => 'admin']],
        ];
    }

    #[DataProvider('forbiddenFieldProvider')]
    public function test_forbidden_fields_must_be_missing_even_when_empty(string $field, mixed $value): void
    {
        [$actor, $target] = $this->authorizedPair();

        $this->patchWithoutChanges($actor, $target, ['role' => User::ROLE_ORG_ADMIN, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function forbiddenFieldProvider(): array
    {
        $maliciousValues = [
            'name' => 'Attacker name',
            'email' => 'attacker@example.test',
            'phone' => '+212600000099',
            'organization_id' => 999,
            'is_active' => true,
            'password' => 'attacker-password',
            'password_confirmation' => 'attacker-password',
            'email_verified_at' => '2026-09-08 12:00:00',
            'last_login_at' => '2026-09-08 12:00:00',
            'remember_token' => 'attacker-token',
            'id' => 999,
            'created_at' => '2026-09-08 12:00:00',
            'updated_at' => '2026-09-08 12:00:00',
        ];
        $cases = [];

        foreach ($maliciousValues as $field => $maliciousValue) {
            foreach (['null' => null, 'false' => false, 'zero' => 0, 'empty' => '', 'malicious' => $maliciousValue] as $label => $value) {
                $cases[$field.' '.$label] = [$field, $value];
            }
        }

        return $cases;
    }

    public function test_payload_organization_does_not_replace_bound_targets_organization(): void
    {
        [$actor, $target] = $this->authorizedPair();
        $otherOrganization = Organization::factory()->active()->create();
        $before = Organization::query()->orderBy('id')->get()
            ->map(fn (Organization $organization) => $organization->getRawOriginal())->all();

        $this->patchWithoutChanges($actor, $target, [
            'role' => User::ROLE_ORG_ADMIN,
            'organization_id' => $otherOrganization->getKey(),
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->assertSame($before, Organization::query()->orderBy('id')->get()
            ->map(fn (Organization $organization) => $organization->getRawOriginal())->all());
    }

    public function test_payload_id_does_not_replace_bound_target(): void
    {
        [$actor, $target] = $this->authorizedPair();
        // A platform admin would be forbidden as a target if the payload ID were used.
        $otherUser = User::factory()->platformAdmin()->create();

        $this->patchWithoutChanges($actor, $target, [
            'role' => User::ROLE_ORG_ADMIN,
            'id' => $otherUser->getKey(),
        ])->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_platform_admin_role_is_a_validation_error_not_an_authorization_error(): void
    {
        [$actor, $target] = $this->authorizedPair();

        $this->patchWithoutChanges($actor, $target, ['role' => User::ROLE_PLATFORM_ADMIN])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_missing_bound_user_returns_not_found_without_changing_users(): void
    {
        [$actor] = $this->authorizedPair();
        $missingUserId = ((int) User::query()->max('id')) + 1;
        $before = $this->persistedUsersSnapshot();
        $count = User::query()->count();

        $response = $this->actingAs($actor)
            ->patchJson("/_tests/admin/users/{$missingUserId}/role", ['role' => User::ROLE_ORG_ADMIN]);

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($count, User::query()->count());
        $response->assertNotFound();
    }

    #[DataProvider('invalidIdentityProvider')]
    public function test_authorization_requires_user_instances_without_payload_fallback(string $scenario): void
    {
        [$actor, $target] = $this->authorizedPair();
        $before = $this->persistedUsersSnapshot();
        $request = ChangeUserRoleRequest::create('/', 'PATCH', [
            'role' => User::ROLE_ORG_ADMIN,
            'id' => $target->getKey(),
            'user' => $target->getKey(),
            'organization_id' => $target->organization_id,
        ]);
        $resolvedActor = match ($scenario) {
            'null actor' => null,
            'non user actor' => new stdClass,
            default => $actor,
        };
        $resolvedTarget = match ($scenario) {
            'missing target' => null,
            'string target' => (string) $target->getKey(),
            'integer target' => $target->getKey(),
            'non user target' => new stdClass,
            default => $target,
        };
        $request->setUserResolver(fn () => $resolvedActor);
        $request->setRouteResolver(fn () => $this->boundRoute($resolvedTarget));
        Gate::shouldReceive('forUser')->never();

        $this->assertFalse($request->authorize());
        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame(count($before), User::query()->count());
    }

    public static function invalidIdentityProvider(): array
    {
        return array_combine(
            $scenarios = ['null actor', 'non user actor', 'missing target', 'string target', 'integer target', 'non user target'],
            array_map(fn (string $scenario) => [$scenario], $scenarios)
        );
    }

    #[DataProvider('gateRoleProvider')]
    public function test_authorization_delegates_to_gate_with_safe_role_without_rewriting_payload(
        mixed $role,
        string $safeRole,
        bool $allowed
    ): void {
        [$actor, $target] = $this->authorizedPair();
        $before = $this->persistedUsersSnapshot();
        $payload = ['role' => $role, 'id' => $actor->getKey(), 'organization_id' => 999];
        $request = ChangeUserRoleRequest::create('/', 'PATCH', $payload);
        $request->setUserResolver(fn () => $actor);
        $request->setRouteResolver(fn () => $this->boundRoute($target));
        $gate = Mockery::mock(GateContract::class);
        Gate::shouldReceive('forUser')->once()->with($actor)->andReturn($gate);
        $gate->shouldReceive('allows')->once()->with('changeRole', [$target, $safeRole])->andReturn($allowed);

        $this->assertSame($allowed, $request->authorize());
        $this->assertSame($payload, $request->all());
        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame(count($before), User::query()->count());
    }

    public static function gateRoleProvider(): array
    {
        $cases = [];
        foreach ([
            'organization admin' => [User::ROLE_ORG_ADMIN, User::ROLE_ORG_ADMIN],
            'inventory agent' => [User::ROLE_INVENTORY_AGENT, User::ROLE_INVENTORY_AGENT],
            'platform admin' => [User::ROLE_PLATFORM_ADMIN, User::ROLE_INVENTORY_AGENT],
            'unknown' => ['unknown-role', User::ROLE_INVENTORY_AGENT],
            'array' => [[User::ROLE_ORG_ADMIN], User::ROLE_INVENTORY_AGENT],
        ] as $label => [$role, $safeRole]) {
            foreach ([true, false] as $allowed) {
                $cases[$label.($allowed ? ' allowed' : ' denied')] = [$role, $safeRole, $allowed];
            }
        }

        return $cases;
    }

    private function boundRoute(mixed $target): \Illuminate\Routing\Route
    {
        $route = new \Illuminate\Routing\Route('PATCH', '/_tests/admin/users/{user}/role', fn () => null);
        $route->bind(Request::create('/_tests/admin/users/1/role', 'PATCH'));
        $route->setParameter('user', $target);

        return $route;
    }

    private function authorizedPair(): array
    {
        return [
            User::factory()->platformAdmin()->create(['is_active' => true]),
            $this->createTarget(Organization::factory()->active()->create()),
        ];
    }

    private function unauthorizedPair(string $scenario): array
    {
        $organization = Organization::factory()->active()->create();
        $actorAttributes = ['role' => User::ROLE_PLATFORM_ADMIN, 'organization_id' => null, 'is_active' => true];
        $targetAttributes = [];

        switch ($scenario) {
            case 'inventory-actor':
            case 'same-organization':
            case 'other-organization':
            case 'unknown-actor':
            case 'inactive-org':
            case 'orphan-actor':
            case 'suspended-actor':
                $actorAttributes['role'] = match ($scenario) {
                    'inventory-actor' => User::ROLE_INVENTORY_AGENT,
                    'unknown-actor' => 'unknown-role',
                    default => User::ROLE_ORG_ADMIN,
                };
                $actorAttributes['organization_id'] = match ($scenario) {
                    'other-organization' => Organization::factory()->active()->create()->getKey(),
                    'orphan-actor' => null,
                    'suspended-actor' => Organization::factory()->suspended()->create()->getKey(),
                    default => $organization->getKey(),
                };
                $actorAttributes['is_active'] = $scenario !== 'inactive-org';
                break;
            case 'inactive-platform':
                $actorAttributes['is_active'] = false;
                break;
            case 'platform-target':
                $targetAttributes = ['role' => User::ROLE_PLATFORM_ADMIN, 'organization_id' => null];
                break;
            case 'unknown-target':
                $targetAttributes = ['role' => 'unknown-role'];
                break;
            case 'orphan-target':
                $targetAttributes = ['organization_id' => null];
                break;
        }

        $actor = $scenario === 'guest' ? null : User::factory()->create($actorAttributes);
        $target = $scenario === 'self' ? $actor : $this->createTarget($organization, $targetAttributes);

        return [$actor, $target];
    }

    private function createTarget(Organization $organization, array $attributes = []): User
    {
        return User::factory()->inventoryAgent()->create(array_replace([
            'organization_id' => $organization->getKey(),
            'is_active' => true,
            'phone' => '+212600000001',
            'email_verified_at' => '2026-01-01 12:00:00',
            'last_login_at' => '2026-01-02 12:00:00',
            'remember_token' => 'original-token',
            'created_at' => '2025-01-01 12:00:00',
            'updated_at' => '2026-01-03 12:00:00',
        ], $attributes));
    }

    private function patchWithoutChanges(?User $actor, User $target, array $payload): TestResponse
    {
        $before = $this->persistedUsersSnapshot();
        $count = User::query()->count();
        if ($actor !== null) {
            $this->actingAs($actor);
        }

        $response = $this->patchJson("/_tests/admin/users/{$target->getKey()}/role", $payload);

        $this->assertSame($before, $this->persistedUsersSnapshot());
        $this->assertSame($count, User::query()->count());

        return $response;
    }

    private function persistedUsersSnapshot(): array
    {
        $snapshot = [];
        foreach (User::query()->orderBy('id')->get() as $user) {
            $raw = User::query()->findOrFail($user->getKey())->getRawOriginal();
            ksort($raw);
            $snapshot[$user->getKey()] = $raw;
        }
        ksort($snapshot);

        return $snapshot;
    }
}
