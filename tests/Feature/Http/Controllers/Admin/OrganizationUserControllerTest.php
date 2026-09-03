<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Http\Controllers\Admin\OrganizationUserController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class OrganizationUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)
            ->post(
                '/__tests/admin/organizations/{organization}/users',
                [OrganizationUserController::class, 'store']
            );

        Route::middleware(SubstituteBindings::class)
            ->patch(
                '/__tests/admin/users/{user}',
                [OrganizationUserController::class, 'update']
            );
    }

    public function test_authorized_user_can_store_exactly_one_user_for_the_route_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $password = 'ValidPassword1!';
        $userCount = User::query()->count();
        $payload = $this->validStorePayload();

        $response = $this->actingAs($actor)
            ->postJson($this->storeEndpoint($organization), $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('organization_id', $organization->getKey())
            ->assertJsonPath('name', $payload['name'])
            ->assertJsonPath('email', $payload['email'])
            ->assertJsonPath('phone', $payload['phone'])
            ->assertJsonPath('role', $payload['role'])
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $this->assertDatabaseCount('users', $userCount + 1);

        $created = User::query()->where('email', $payload['email'])->sole();

        $this->assertSame($organization->getKey(), $created->organization_id);
        $this->assertSame($payload['name'], $created->name);
        $this->assertSame($payload['email'], $created->email);
        $this->assertSame($payload['phone'], $created->phone);
        $this->assertSame($payload['role'], $created->role);
        $this->assertNotSame($password, $created->password);
        $this->assertTrue(Hash::check($password, $created->password));
        $this->assertTrue($created->is_active);
        $this->assertNull($created->email_verified_at);
        $this->assertNull($created->last_login_at);
        $this->assertNull($created->remember_token);
    }

    public function test_store_rejects_forbidden_sensitive_fields_without_creating_a_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->postJson($this->storeEndpoint($organization), $this->validStorePayload([
                'is_active' => false,
                'email_verified_at' => now()->toDateTimeString(),
                'last_login_at' => now()->toDateTimeString(),
                'remember_token' => 'attacker-controlled-token',
            ]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('users', $userCount);
        $this->assertDatabaseMissing('users', ['email' => 'new.user@example.test']);
    }

    public function test_unauthorized_user_cannot_store_a_user(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->inventoryAgent()->create();
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->postJson($this->storeEndpoint($organization), $this->validStorePayload())
            ->assertForbidden();

        $this->assertDatabaseCount('users', $userCount);
        $this->assertDatabaseMissing('users', ['email' => 'new.user@example.test']);
    }

    public function test_organization_that_does_not_accept_users_cannot_receive_a_new_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->suspended()->create();
        $userCount = User::query()->count();

        $this->actingAs($actor)
            ->postJson($this->storeEndpoint($organization), $this->validStorePayload())
            ->assertForbidden();

        $this->assertDatabaseCount('users', $userCount);
        $this->assertDatabaseMissing('users', ['email' => 'new.user@example.test']);
    }

    public function test_store_validation_failure_does_not_create_a_partial_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $payload = $this->validStorePayload();
        $userCount = User::query()->count();
        unset($payload['email']);

        $this->actingAs($actor)
            ->postJson($this->storeEndpoint($organization), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', $userCount);
    }

    public function test_authorized_user_can_update_only_the_bound_user_name_and_phone(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'name' => 'Original Target',
            'phone' => '+212600000001',
        ]);
        $otherUser = $this->createInventoryAgent($organization, [
            'name' => 'Untouched User',
            'phone' => '+212600000002',
        ]);
        $otherUserBefore = $this->persistedUserSnapshot($otherUser);

        $response = $this->actingAs($actor)
            ->patchJson($this->updateEndpoint($target), [
                'name' => 'Updated Target',
                'phone' => '+212611111111',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('id', $target->getKey())
            ->assertJsonPath('name', 'Updated Target')
            ->assertJsonPath('phone', '+212611111111')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $target->refresh();
        $this->assertSame('Updated Target', $target->name);
        $this->assertSame('+212611111111', $target->phone);
        $this->assertSame($otherUserBefore, $this->persistedUserSnapshot($otherUser));
    }

    public function test_omitting_phone_keeps_the_existing_phone(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(
            Organization::factory()->active()->create(),
            ['phone' => '+212600000001']
        );

        $this->actingAs($actor)
            ->patchJson($this->updateEndpoint($target), ['name' => 'Updated Name'])
            ->assertOk();

        $this->assertSame('+212600000001', $target->refresh()->phone);
    }

    public function test_sending_null_phone_clears_the_existing_phone(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(
            Organization::factory()->active()->create(),
            ['phone' => '+212600000001']
        );

        $this->actingAs($actor)
            ->patchJson($this->updateEndpoint($target), [
                'name' => 'Updated Name',
                'phone' => null,
            ])
            ->assertOk();

        $this->assertNull($target->refresh()->phone);
    }

    #[DataProvider('forbiddenUpdateFieldProvider')]
    public function test_update_rejects_forbidden_fields_without_changing_the_user(
        string $field,
        mixed $value
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization, [
            'name' => 'Original Name',
            'email' => 'original@example.test',
            'phone' => '+212600000001',
        ]);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->updateEndpoint($target), [
                'name' => 'Attacker Update',
                'phone' => '+212699999999',
                $field => $value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function forbiddenUpdateFieldProvider(): array
    {
        return [
            'email' => ['email', 'changed@example.test'],
            'role' => ['role', User::ROLE_ORG_ADMIN],
            'organization id' => ['organization_id', 999],
            'active status' => ['is_active', false],
            'password' => ['password', 'ChangedPassword1!'],
            'password confirmation' => ['password_confirmation', 'ChangedPassword1!'],
            'email verification timestamp' => ['email_verified_at', '2026-08-26 12:00:00'],
            'last login timestamp' => ['last_login_at', '2026-08-26 12:00:00'],
            'remember token' => ['remember_token', 'attacker-controlled-token'],
            'id' => ['id', 999],
        ];
    }

    public function test_unauthorized_user_cannot_update_a_user(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = $this->createInventoryAgent($organization);
        $target = $this->createInventoryAgent($organization, ['name' => 'Original Name']);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->updateEndpoint($target), ['name' => 'Unauthorized Update'])
            ->assertForbidden();

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_organization_admin_cannot_update_a_user_from_another_organization(): void
    {
        $actorOrganization = Organization::factory()->active()->create();
        $targetOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($actorOrganization)->organizationAdmin()->create();
        $target = $this->createInventoryAgent($targetOrganization, ['name' => 'Original Name']);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->updateEndpoint($target), ['name' => 'Cross Organization Update'])
            ->assertForbidden();

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_updating_a_missing_user_returns_not_found_without_changing_other_users(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $otherUser = $this->createInventoryAgent(Organization::factory()->active()->create());
        $before = $this->persistedUserSnapshot($otherUser);

        $this->actingAs($actor)
            ->patchJson('/__tests/admin/users/999999', ['name' => 'Updated Name'])
            ->assertNotFound();

        $this->assertSame($before, $this->persistedUserSnapshot($otherUser));
    }

    public function test_controller_actions_use_validated_data_and_no_unfiltered_request_data(): void
    {
        $path = app_path('Http/Controllers/Admin/OrganizationUserController.php');

        $this->assertFileExists($path);

        if (! is_file($path)) {
            return;
        }

        $source = file_get_contents($path);
        $this->assertIsString($source);

        $storeSource = $this->compactPhpSource($this->methodSource($path, 'store'));
        $updateSource = $this->compactPhpSource($this->methodSource($path, 'update'));
        $controllerSource = $this->compactPhpSource($source);

        $this->assertStringContainsString('$request->validated()', $storeSource);
        $this->assertStringContainsString('$request->validated()', $updateSource);

        foreach ([
            '$request->hasAny(',
            '$request->has(',
            '$request->all()',
            'request()->all()',
            '$request->only(',
            '$request->except(',
            '$request->input(',
            'request(',
            '$this->all()',
        ] as $unsafeExpression) {
            $this->assertStringNotContainsString($unsafeExpression, $controllerSource);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validStorePayload(array $overrides = []): array
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

    private function storeEndpoint(Organization $organization): string
    {
        return "/__tests/admin/organizations/{$organization->getKey()}/users";
    }

    private function updateEndpoint(User $user): string
    {
        return "/__tests/admin/users/{$user->getKey()}";
    }

    private function methodSource(string $path, string $method): string
    {
        $reflection = new ReflectionMethod(OrganizationUserController::class, $method);
        $lines = file($path);

        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    private function compactPhpSource(string $source): string
    {
        if (! str_starts_with(ltrim($source), '<?php')) {
            $source = "<?php\n".$source;
        }

        $compact = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $compact .= is_array($token) ? $token[1] : $token;
        }

        return $compact;
    }
}
