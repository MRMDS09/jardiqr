<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Http\Controllers\Admin\ChangeUserStatusController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChangeUserStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Explicit __invoke defers resolving the absent controller until dispatch in RED.
        Route::middleware(SubstituteBindings::class)->patch(
            '/_tests/admin/users/{user}/status',
            [ChangeUserStatusController::class, '__invoke']
        );
    }

    #[DataProvider('authorizedChanges')]
    public function test_authorized_status_changes_only_update_the_bound_users_status(
        string $actorRole,
        string $targetRole,
        string $organizationStatus,
        bool $desiredStatus
    ): void {
        $organization = Organization::factory()->create(['status' => $organizationStatus]);
        $actor = $actorRole === User::ROLE_PLATFORM_ADMIN
            ? User::factory()->platformAdmin()->create()
            : User::factory()->for($organization)->organizationAdmin()->create();
        $target = $this->createTarget($organization, ! $desiredStatus, $targetRole);

        $this->assertStatusChange($actor, $target, $desiredStatus, $desiredStatus);
    }

    public static function authorizedChanges(): array
    {
        return [
            'platform disables agent' => [User::ROLE_PLATFORM_ADMIN, User::ROLE_INVENTORY_AGENT, Organization::STATUS_ACTIVE, false],
            'platform reactivates agent' => [User::ROLE_PLATFORM_ADMIN, User::ROLE_INVENTORY_AGENT, Organization::STATUS_ACTIVE, true],
            'organization admin disables own agent' => [User::ROLE_ORG_ADMIN, User::ROLE_INVENTORY_AGENT, Organization::STATUS_ACTIVE, false],
            'organization admin reactivates own agent' => [User::ROLE_ORG_ADMIN, User::ROLE_INVENTORY_AGENT, Organization::STATUS_ACTIVE, true],
            'platform disables organization admin' => [User::ROLE_PLATFORM_ADMIN, User::ROLE_ORG_ADMIN, Organization::STATUS_ACTIVE, false],
            'platform disables user in suspended organization' => [User::ROLE_PLATFORM_ADMIN, User::ROLE_INVENTORY_AGENT, Organization::STATUS_SUSPENDED, false],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_accepted_values_are_assigned_as_booleans_and_saved_once(
        bool|int|string $input,
        bool $expected
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createTarget(Organization::factory()->active()->create(), ! $expected);

        $this->assertStatusChange($actor, $target, $input, $expected);
    }

    public static function acceptedValues(): array
    {
        return [
            'boolean true' => [true, true],
            'boolean false' => [false, false],
            'integer one' => [1, true],
            'integer zero' => [0, false],
            'string one' => ['1', true],
            'string zero' => ['0', false],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_equivalent_status_is_a_no_op_without_saving(
        bool|int|string $input,
        bool $currentStatus
    ): void {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createTarget(Organization::factory()->active()->create(), $currentStatus);
        $before = $this->userSnapshots();
        $userCount = User::query()->count();

        Event::fake();

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target->getKey()), ['is_active' => $input])
            ->assertOk()
            ->assertJsonPath('id', $target->getKey())
            ->assertJsonPath('is_active', $currentStatus);

        $this->assertNoUsersChanged($before, $userCount);
    }

    #[DataProvider('rejectedChanges')]
    public function test_failed_requests_do_not_save_or_change_any_users(string $scenario, int $status): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = $scenario === 'unauthorized'
            ? User::factory()->for($organization)->inventoryAgent()->create()
            : User::factory()->platformAdmin()->create();
        $target = $this->createTarget($organization, true);
        $targetId = $scenario === 'missing user' ? User::query()->max('id') + 1 : $target->getKey();
        $input = $scenario === 'invalid value' ? 'not-a-boolean' : false;
        $before = $this->userSnapshots();
        $userCount = User::query()->count();

        Event::fake();

        $response = $this->actingAs($actor)
            ->patchJson($this->endpoint($targetId), ['is_active' => $input])
            ->assertStatus($status);

        if ($status === 422) {
            $response->assertJsonValidationErrors('is_active');
        }

        $this->assertNoUsersChanged($before, $userCount);
    }

    public static function rejectedChanges(): array
    {
        return [
            'unauthorized actor' => ['unauthorized', 403],
            'invalid is_active' => ['invalid value', 422],
            'missing bound user' => ['missing user', 404],
        ];
    }

    public function test_controller_source_requires_validated_boolean_assignment_and_a_no_op_guard(): void
    {
        $path = app_path('Http/Controllers/Admin/ChangeUserStatusController.php');
        $this->assertFileExists($path);
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $source = $this->compactPhpSource($source);

        $this->assertStringContainsString('function__invoke(', $source);
        $this->assertStringContainsString('ChangeUserStatusRequest$request', $source);
        $this->assertStringContainsString('User$user', $source);
        $this->assertStringContainsString('$data=$request->validated();', $source);
        $this->assertMatchesRegularExpression(
            '~\$isActive=\(bool\)\$data\[([\'\"])is_active\1\];~',
            $source
        );
        $this->assertStringContainsString('$user->is_active=$isActive;', $source);
        $this->assertMatchesRegularExpression(
            '~if\(\$user->is_active===\$isActive\)\{returnresponse\(\)->json\(\$user\);\}'
            .'\$user->is_active=\$isActive;\$user->save\(\);returnresponse\(\)->json\(\$user\);~',
            $source
        );

        foreach ([
            '$request->all(',
            '$request->only(',
            '$request->except(',
            '$request->input(',
            '$request->boolean(',
            '$request->has(',
            '$request->hasAny(',
            'request(',
            '->fill(',
            '->forceFill(',
            '->update(',
            'User::create(',
            'User::query()->create(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    private function assertStatusChange(User $actor, User $target, bool|int|string $input, bool $expected): void
    {
        User::factory()->for($target->organization)->inventoryAgent()->create();
        $before = $this->userSnapshots();
        $userCount = User::query()->count();
        $targetId = $target->getKey();

        Event::fake();

        $this->actingAs($actor)
            ->patchJson($this->endpoint($targetId), ['is_active' => $input])
            ->assertOk()
            ->assertJsonPath('id', $targetId)
            ->assertJsonPath('is_active', $expected);

        $after = $this->userSnapshots();
        $this->assertDatabaseHas('users', ['id' => $targetId, 'is_active' => $expected]);
        $this->assertSame((int) $expected, (int) $after[$targetId]['is_active']);
        $this->assertNotSame($before[$targetId]['is_active'], $after[$targetId]['is_active']);
        $this->assertSame($userCount, User::query()->count());

        // Compare every persisted field of every user, except the target's allowed changes.
        unset($before[$targetId]['is_active'], $before[$targetId]['updated_at']);
        unset($after[$targetId]['is_active'], $after[$targetId]['updated_at']);
        $this->assertSame($before, $after);

        Event::assertDispatchedTimes('eloquent.saving: '.User::class, 1);
        Event::assertDispatchedTimes('eloquent.updating: '.User::class, 1);
        Event::assertDispatched(
            'eloquent.saving: '.User::class,
            function (string $event, User $savedUser) use ($targetId, $expected): bool {
                // getAttributes bypasses the boolean accessor cast and retains the assigned type.
                $this->assertSame($targetId, $savedUser->getKey());
                $this->assertSame($expected, $savedUser->getAttributes()['is_active']);

                return true;
            }
        );
    }

    private function createTarget(
        Organization $organization,
        bool $isActive,
        string $role = User::ROLE_INVENTORY_AGENT
    ): User {
        return User::factory()->for($organization)->create([
            'name' => 'Managed User',
            'email' => 'managed@example.test',
            'phone' => '+212600000001',
            'role' => $role,
            'is_active' => $isActive,
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'last_login_at' => now()->subDay()->startOfSecond(),
            'remember_token' => 'known-remember-token',
            'created_at' => now()->subYear()->startOfSecond(),
            'updated_at' => now()->subMonth()->startOfSecond(),
        ]);
    }

    private function userSnapshots(): array
    {
        return User::query()->orderBy('id')->get()->mapWithKeys(function (User $user): array {
            $snapshot = $user->getRawOriginal();
            ksort($snapshot);

            return [$user->getKey() => $snapshot];
        })->all();
    }

    private function assertNoUsersChanged(array $before, int $userCount): void
    {
        $this->assertSame($before, $this->userSnapshots());
        $this->assertSame($userCount, User::query()->count());
        Event::assertNotDispatched('eloquent.saving: '.User::class);
        Event::assertNotDispatched('eloquent.updating: '.User::class);
    }

    private function endpoint(int $userId): string
    {
        return "/_tests/admin/users/{$userId}/status";
    }

    private function compactPhpSource(string $source): string
    {
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

        return $compacted;
    }
}
