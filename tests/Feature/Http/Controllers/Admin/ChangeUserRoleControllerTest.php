<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Http\Controllers\Admin\ChangeUserRoleController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChangeUserRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)->patch(
            '/_tests/admin/users/{user}/role',
            [ChangeUserRoleController::class, '__invoke']
        );
    }

    #[DataProvider('authorizedChanges')]
    public function test_authorized_changes_only_update_the_bound_users_role(
        string $currentRole,
        string $newRole,
        bool $active,
        string $status
    ): void {
        [$actor, $target] = $this->authorizedPair($currentRole, $active, $status);

        $this->assertRoleChange($actor, $target, ['role' => $newRole]);
    }

    public static function authorizedChanges(): array
    {
        return [
            'promote agent' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, true, Organization::STATUS_ACTIVE],
            'demote organization admin' => [User::ROLE_ORG_ADMIN, User::ROLE_INVENTORY_AGENT, true, Organization::STATUS_ACTIVE],
            'inactive target' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, false, Organization::STATUS_ACTIVE],
            'trial organization' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, true, Organization::STATUS_TRIAL],
            'suspended organization' => [User::ROLE_INVENTORY_AGENT, User::ROLE_ORG_ADMIN, true, Organization::STATUS_SUSPENDED],
        ];
    }

    #[DataProvider('unchangedRoles')]
    public function test_same_role_is_a_no_op_without_saving(string $role): void
    {
        [$actor, $target] = $this->authorizedPair($role);
        $before = $this->userSnapshots();
        $this->fakeUserSaveEvents();

        $this->actingAs($actor)->patchJson($this->endpoint($target->getKey()), ['role' => $role])
            ->assertOk()
            ->assertJsonPath('id', $target->getKey())
            ->assertJsonPath('role', $role)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $this->assertNoUsersChanged($before);
    }

    public static function unchangedRoles(): array
    {
        return [
            'organization admin' => [User::ROLE_ORG_ADMIN],
            'inventory agent' => [User::ROLE_INVENTORY_AGENT],
        ];
    }

    #[DataProvider('rejectedChanges')]
    public function test_rejected_requests_do_not_save_or_change_users(string $scenario, int $status): void
    {
        [$actor, $target] = $this->authorizedPair();
        if (in_array($scenario, ['organization actor', 'inventory actor'], true)) {
            $actor = User::factory()->for($target->organization)->create([
                'role' => $scenario === 'organization actor' ? User::ROLE_ORG_ADMIN : User::ROLE_INVENTORY_AGENT,
                'is_active' => true,
            ]);
        }
        if ($scenario === 'platform target') {
            $target = User::factory()->platformAdmin()->create();
        }
        $targetId = $scenario === 'missing user' ? ((int) User::query()->max('id')) + 1 : $target->getKey();
        $role = match ($scenario) {
            'platform role' => User::ROLE_PLATFORM_ADMIN,
            'unknown role' => 'unknown-role',
            default => User::ROLE_ORG_ADMIN,
        };
        $before = $this->userSnapshots();
        $this->fakeUserSaveEvents();

        $response = $this->actingAs($actor)->patchJson($this->endpoint($targetId), ['role' => $role]);

        $this->assertNoUsersChanged($before);
        $response->assertStatus($status);
        if ($status === 422) {
            $response->assertJsonValidationErrors('role');
        }
    }

    public static function rejectedChanges(): array
    {
        return [
            'organization admin promotes own agent' => ['organization actor', 403],
            'inventory agent targets another user' => ['inventory actor', 403],
            'platform admin targets platform admin' => ['platform target', 403],
            'platform admin role' => ['platform role', 422],
            'unknown role' => ['unknown role', 422],
            'missing bound user' => ['missing user', 404],
        ];
    }

    #[DataProvider('injectedFields')]
    public function test_injected_fields_are_rejected_without_saving(string $field): void
    {
        [$actor, $target] = $this->authorizedPair();
        $value = match ($field) {
            'organization_id' => Organization::factory()->active()->create()->getKey(),
            'is_active' => false,
            'id' => $actor->getKey(),
            'email' => 'injected@example.test',
        };
        $before = $this->userSnapshots();
        $this->fakeUserSaveEvents();

        $response = $this->actingAs($actor)->patchJson($this->endpoint($target->getKey()), [
            'role' => User::ROLE_ORG_ADMIN,
            $field => $value,
        ]);

        $this->assertNoUsersChanged($before);
        $response->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function injectedFields(): array
    {
        return [
            'another organization' => ['organization_id'],
            'active status' => ['is_active'],
            'another user id' => ['id'],
            'email' => ['email'],
        ];
    }

    public function test_unrelated_field_is_filtered_without_being_saved_or_returned(): void
    {
        [$actor, $target] = $this->authorizedPair();

        $this->assertRoleChange($actor, $target, [
            'role' => User::ROLE_ORG_ADMIN,
            'unrelated_field' => 'ignored',
        ]);
    }

    public function test_controller_source_uses_validated_data_and_explicit_role_assignment(): void
    {
        $path = app_path('Http/Controllers/Admin/ChangeUserRoleController.php');
        $this->assertFileExists($path);
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $compacted = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $compacted .= $token[1];
            } else {
                $compacted .= $token;
            }
        }

        $this->assertMatchesRegularExpression('~\$[a-zA-Z_][a-zA-Z0-9_]*->validated\(\)~', $compacted);
        $this->assertMatchesRegularExpression('~\$[a-zA-Z_][a-zA-Z0-9_]*->role=(?!=)~', $compacted);
        $this->assertDoesNotMatchRegularExpression(
            '~(?:->|::)(?:all|only|except|input|fill|forceFill|update|create|forceCreate|insert|upsert|updateOrCreate|firstOrCreate)\(|\brequest\(~i',
            $compacted
        );
    }

    private function assertRoleChange(User $actor, User $target, array $payload): void
    {
        $targetId = $target->getKey();
        $before = $this->userSnapshots();
        $this->fakeUserSaveEvents();

        $this->actingAs($actor)->patchJson($this->endpoint($targetId), $payload)
            ->assertOk()
            ->assertJsonPath('id', $targetId)
            ->assertJsonPath('role', $payload['role'])
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token')
            ->assertJsonMissingPath('unrelated_field');

        $after = $this->userSnapshots();
        $this->assertDatabaseHas('users', ['id' => $targetId, 'role' => $payload['role']]);
        $this->assertSame($payload['role'], $after[$targetId]['role']);
        $this->assertNotSame($before[$targetId]['role'], $after[$targetId]['role']);
        $this->assertSame(count($before), User::query()->count());
        unset($before[$targetId]['role'], $before[$targetId]['updated_at']);
        unset($after[$targetId]['role'], $after[$targetId]['updated_at']);
        $this->assertSame($before, $after);

        Event::assertDispatchedTimes('eloquent.saving: '.User::class, 1);
        Event::assertDispatchedTimes('eloquent.updating: '.User::class, 1);
    }

    private function authorizedPair(
        string $role = User::ROLE_INVENTORY_AGENT,
        bool $active = true,
        string $status = Organization::STATUS_ACTIVE
    ): array {
        $organization = Organization::factory()->create(['status' => $status]);
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);
        $target = User::factory()->for($organization)->create([
            'role' => $role,
            'is_active' => $active,
            'phone' => '+212600000001',
            'email_verified_at' => '2026-01-01 12:00:00',
            'last_login_at' => '2026-01-02 12:00:00',
            'remember_token' => 'original-token',
            'created_at' => '2025-01-01 12:00:00',
            'updated_at' => '2026-01-03 12:00:00',
        ]);
        User::factory()->for($organization)->inventoryAgent()->create();

        return [$actor, $target];
    }

    private function fakeUserSaveEvents(): void
    {
        Event::fake([
            'eloquent.saving: '.User::class,
            'eloquent.updating: '.User::class,
        ]);
    }

    private function userSnapshots(): array
    {
        $snapshots = [];
        foreach (User::query()->orderBy('id')->get() as $user) {
            $raw = $user->getRawOriginal();
            ksort($raw);
            $snapshots[$user->getKey()] = $raw;
        }
        ksort($snapshots);

        return $snapshots;
    }

    private function assertNoUsersChanged(array $before): void
    {
        $this->assertSame($before, $this->userSnapshots());
        $this->assertSame(count($before), User::query()->count());
        Event::assertNotDispatched('eloquent.saving: '.User::class);
        Event::assertNotDispatched('eloquent.updating: '.User::class);
    }

    private function endpoint(int $userId): string
    {
        return "/_tests/admin/users/{$userId}/role";
    }
}
