<?php

namespace Tests\Feature\Http\Requests;

use App\Http\Requests\Admin\StoreOrganizationUserRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreOrganizationUserRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)
            ->post(
                '/testing/organizations/{organization}/users',
                function (
                    StoreOrganizationUserRequest $request,
                    Organization $organization
                ): JsonResponse {
                    return response()->json(array_keys($request->validated()));
                }
            );
    }

    public function test_platform_admin_can_submit_an_organization_admin_for_an_active_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();

        $this->actingAs($actor)
            ->postJson($this->endpoint($organization), $this->validPayload([
                'role' => User::ROLE_ORG_ADMIN,
            ]))
            ->assertOk();
    }

    public function test_platform_admin_can_submit_an_inventory_agent_for_a_trial_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->create();

        $this->actingAs($actor)
            ->postJson($this->endpoint($organization), $this->validPayload())
            ->assertOk();
    }

    public function test_organization_admin_can_submit_an_inventory_agent_for_own_active_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();

        $this->actingAs($actor)
            ->postJson($this->endpoint($organization), $this->validPayload())
            ->assertOk();
    }

    public function test_organization_admin_can_submit_an_inventory_agent_for_own_trial_organization(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();

        $this->actingAs($actor)
            ->postJson($this->endpoint($organization), $this->validPayload())
            ->assertOk();
    }

    public function test_validated_data_contains_only_allowed_keys_and_does_not_create_a_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $userCount = User::query()->count();
        $payload = $this->validPayload() + [
            'organization_id' => $otherOrganization->getKey(),
            'is_active' => false,
            'last_login_at' => now()->toDateTimeString(),
            'email_verified_at' => now()->toDateTimeString(),
            'id' => 999,
            'remember_token' => 'attacker-controlled-token',
        ];

        $this->actingAs($actor)
            ->postJson($this->endpoint($organization), $payload)
            ->assertOk()
            ->assertExactJson([
                'name',
                'email',
                'phone',
                'password',
                'role',
            ]);

        $this->assertDatabaseCount('users', $userCount);
    }

    public function test_route_organization_remains_authoritative_when_body_contains_another_organization_id(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();

        $this->actingAs($actor)
            ->postJson($this->endpoint($organization), $this->validPayload() + [
                'organization_id' => $otherOrganization->getKey(),
            ])
            ->assertOk()
            ->assertJsonMissing(['organization_id']);
    }

    public function test_phone_is_optional(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $payload = $this->validPayload();
        unset($payload['phone']);

        $this->actingAs($actor)
            ->postJson($this->endpoint($organization), $payload)
            ->assertOk();
    }

    #[DataProvider('unauthorizedSubmissionProvider')]
    public function test_unauthorized_submissions_are_forbidden(string $scenario): void
    {
        [$actor, $organization, $payload] = $this->unauthorizedSubmission($scenario);
        $request = $this->postJsonAs($actor, $organization, $payload);

        $request->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unauthorizedSubmissionProvider(): array
    {
        return [
            'platform admin role requested' => ['platform-role'],
            'unknown role requested' => ['unknown-role'],
            'suspended target organization' => ['suspended-target'],
            'organization admin targets another organization' => ['other-organization'],
            'organization admin requests organization admin' => ['org-admin-role'],
            'organization admin requests platform admin' => ['org-admin-platform-role'],
            'inventory agent actor' => ['inventory-agent'],
            'unknown actor role' => ['unknown-actor'],
            'inactive actor' => ['inactive-actor'],
            'orphan organization actor' => ['orphan-actor'],
            'suspended organization actor' => ['suspended-actor'],
        ];
    }

    public function test_guest_submission_is_forbidden(): void
    {
        $organization = Organization::factory()->active()->create();

        $this->postJson($this->endpoint($organization), $this->validPayload())
            ->assertForbidden();
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_is_rejected(
        string $scenario,
        array $overrides,
        string $field
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();

        if ($scenario === 'duplicate-email') {
            User::factory()->create(['email' => 'existing@example.test']);
        }

        $this->actingAs($actor)
            ->postJson(
                $this->endpoint($organization),
                array_replace($this->validPayload(), $overrides)
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'name is required' => ['missing-name', ['name' => null], 'name'],
            'name maximum is 255' => ['long-name', ['name' => str_repeat('a', 256)], 'name'],
            'email is required' => ['missing-email', ['email' => null], 'email'],
            'email must be valid' => ['invalid-email', ['email' => 'not-an-email'], 'email'],
            'email must be lowercase' => ['uppercase-email', ['email' => 'NEW.USER@example.test'], 'email'],
            'email maximum is 255' => ['long-email', ['email' => str_repeat('a', 256).'@example.test'], 'email'],
            'email must be unique' => ['duplicate-email', ['email' => 'existing@example.test'], 'email'],
            'password is required' => ['missing-password', ['password' => null], 'password'],
            'password minimum is 12' => [
                'short-password',
                ['password' => 'Va1!short', 'password_confirmation' => 'Va1!short'],
                'password',
            ],
            'password requires uppercase' => [
                'no-uppercase',
                ['password' => 'validpassword1!', 'password_confirmation' => 'validpassword1!'],
                'password',
            ],
            'password requires lowercase' => [
                'no-lowercase',
                ['password' => 'VALIDPASSWORD1!', 'password_confirmation' => 'VALIDPASSWORD1!'],
                'password',
            ],
            'password requires number' => [
                'no-number',
                ['password' => 'ValidPassword!!', 'password_confirmation' => 'ValidPassword!!'],
                'password',
            ],
            'password requires symbol' => [
                'no-symbol',
                ['password' => 'ValidPassword12', 'password_confirmation' => 'ValidPassword12'],
                'password',
            ],
            'password confirmation must match' => [
                'mismatched-confirmation',
                ['password_confirmation' => 'DifferentPassword1!'],
                'password',
            ],
            'role is required' => ['missing-role', ['role' => null], 'role'],
            'role cannot be empty' => ['empty-role', ['role' => ''], 'role'],
            'role cannot contain only whitespace' => ['whitespace-role', ['role' => '   '], 'role'],
            'role must be a string' => [
                'array-role',
                ['role' => [User::ROLE_INVENTORY_AGENT]],
                'role',
            ],
            'phone maximum is 30' => ['long-phone', ['phone' => str_repeat('1', 31)], 'phone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'New Organization User',
            'email' => 'new.user@example.test',
            'phone' => '+212600000000',
            'password' => 'ValidPassword1!',
            'password_confirmation' => 'ValidPassword1!',
            'role' => User::ROLE_INVENTORY_AGENT,
        ], $overrides);
    }

    /**
     * @return array{User|null, Organization, array<string, mixed>}
     */
    private function unauthorizedSubmission(string $scenario): array
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->platformAdmin()->create();
        $payload = $this->validPayload();

        switch ($scenario) {
            case 'platform-role':
                $payload['role'] = User::ROLE_PLATFORM_ADMIN;
                break;
            case 'unknown-role':
                $payload['role'] = 'unknown-role';
                break;
            case 'suspended-target':
                $organization = Organization::factory()->suspended()->create();
                break;
            case 'other-organization':
                $ownOrganization = Organization::factory()->active()->create();
                $actor = User::factory()->for($ownOrganization)->organizationAdmin()->create();
                break;
            case 'org-admin-role':
                $actor = User::factory()->for($organization)->organizationAdmin()->create();
                $payload['role'] = User::ROLE_ORG_ADMIN;
                break;
            case 'org-admin-platform-role':
                $actor = User::factory()->for($organization)->organizationAdmin()->create();
                $payload['role'] = User::ROLE_PLATFORM_ADMIN;
                break;
            case 'inventory-agent':
                $actor = User::factory()->for($organization)->inventoryAgent()->create();
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
        }

        return [$actor, $organization, $payload];
    }

    private function postJsonAs(
        ?User $actor,
        Organization $organization,
        array $payload
    ): TestResponse {
        if ($actor !== null) {
            $this->actingAs($actor);
        }

        return $this->postJson($this->endpoint($organization), $payload);
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

    private function endpoint(Organization $organization): string
    {
        return "/testing/organizations/{$organization->getKey()}/users";
    }
}
