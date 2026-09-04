<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Http\Controllers\Admin\ChangeUserEmailController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

class ChangeUserEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SubstituteBindings::class)
            ->patch(
                '/__tests/admin/users/{user}/email',
                ChangeUserEmailController::class
            );
    }

    public function test_authorized_actor_can_change_email_without_changing_other_user_state(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = User::factory()->for($organization)->inventoryAgent()->create([
            'name' => 'Managed Inventory Agent',
            'email' => 'original.email@example.test',
            'phone' => '+212600000001',
            'password' => 'OriginalPassword1!',
            'is_active' => true,
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'last_login_at' => now()->subDay()->startOfSecond(),
            'remember_token' => 'known-remember-token',
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
        $otherUser = User::factory()->for($organization)->inventoryAgent()->create();
        $beforeTarget = $this->persistedUserSnapshot($target);
        $beforeOtherUser = $this->persistedUserSnapshot($otherUser);
        $userCount = User::query()->count();

        Event::fake();

        $response = $this->actingAs($actor)->patchJson($this->endpoint($target), [
            'email' => 'changed.email@example.test',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('email', 'changed.email@example.test')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $afterTarget = $this->persistedUserSnapshot($target);

        $this->assertSame('changed.email@example.test', $afterTarget['email']);
        $this->assertNull($afterTarget['email_verified_at']);
        $this->assertNotSame($beforeTarget['updated_at'], $afterTarget['updated_at']);
        $this->assertSame(
            $this->withoutEmailState($beforeTarget),
            $this->withoutEmailState($afterTarget)
        );
        $this->assertSame($beforeOtherUser, $this->persistedUserSnapshot($otherUser));
        $this->assertSame($userCount, User::query()->count());
        Event::assertDispatchedTimes('eloquent.saving: '.User::class, 1);
        Event::assertDispatchedTimes('eloquent.updating: '.User::class, 1);
    }

    public function test_submitting_the_exact_current_email_does_not_save_the_target(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = User::factory()->for($organization)->inventoryAgent()->create([
            'email' => 'current.email@example.test',
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
        $before = $this->persistedUserSnapshot($target);

        Event::fake();

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'email' => 'current.email@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('email', 'current.email@example.test')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
        Event::assertNotDispatched('eloquent.saving: '.User::class);
        Event::assertNotDispatched('eloquent.updating: '.User::class);
    }

    public function test_changing_an_unverified_users_email_keeps_it_unverified(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = User::factory()->for($organization)->inventoryAgent()->unverified()->create([
            'email' => 'unverified.old@example.test',
        ]);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'email' => 'unverified.new@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('email', 'unverified.new@example.test');

        $after = $this->persistedUserSnapshot($target);

        $this->assertSame('unverified.new@example.test', $after['email']);
        $this->assertNull($after['email_verified_at']);
    }

    public function test_duplicate_email_is_rejected_without_changing_target_or_owner(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = User::factory()->for($organization)->inventoryAgent()->create([
            'email' => 'target@example.test',
        ]);
        $owner = User::factory()->create([
            'email' => 'existing@example.test',
        ]);
        $beforeTarget = $this->persistedUserSnapshot($target);
        $beforeOwner = $this->persistedUserSnapshot($owner);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'email' => 'existing@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame($beforeTarget, $this->persistedUserSnapshot($target));
        $this->assertSame($beforeOwner, $this->persistedUserSnapshot($owner));
    }

    public function test_forbidden_extra_field_is_rejected_without_changing_target(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = User::factory()->for($organization)->inventoryAgent()->create();
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'email' => 'changed.email@example.test',
                'name' => 'Forbidden Name',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_inventory_agent_cannot_change_email_and_target_remains_unchanged(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->inventoryAgent()->create();
        $target = User::factory()->for($organization)->inventoryAgent()->create([
            'email' => 'protected@example.test',
            'email_verified_at' => now()->subMonth()->startOfSecond(),
            'updated_at' => now()->subYear()->startOfSecond(),
        ]);
        $before = $this->persistedUserSnapshot($target);

        $this->actingAs($actor)
            ->patchJson($this->endpoint($target), [
                'email' => 'unauthorized@example.test',
            ])
            ->assertForbidden();

        $this->assertSame($before, $this->persistedUserSnapshot($target));
    }

    public function test_missing_bound_user_returns_not_found_without_changing_existing_users(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $existingUser = User::factory()->create();
        $beforeActor = $this->persistedUserSnapshot($actor);
        $beforeExistingUser = $this->persistedUserSnapshot($existingUser);
        $userCount = User::query()->count();
        $missingUserId = User::query()->max('id') + 1;

        $this->actingAs($actor)
            ->patchJson("/__tests/admin/users/{$missingUserId}/email", [
                'email' => 'missing@example.test',
            ])
            ->assertNotFound();

        $this->assertSame($beforeActor, $this->persistedUserSnapshot($actor));
        $this->assertSame($beforeExistingUser, $this->persistedUserSnapshot($existingUser));
        $this->assertSame($userCount, User::query()->count());
    }

    public function test_controller_source_obeys_the_single_action_validated_data_contract(): void
    {
        $path = app_path('Http/Controllers/Admin/ChangeUserEmailController.php');

        $this->assertFileExists($path);

        if (! is_file($path)) {
            return;
        }

        $method = $this->compactPhpSource($this->methodSource($path, '__invoke'));

        $this->assertStringContainsString('function__invoke(', $method);
        $this->assertStringContainsString('ChangeUserEmailRequest$request', $method);
        $this->assertStringContainsString('$request->validated()', $method);

        foreach ([
            '->all(',
            '->only(',
            '->except(',
            '->input(',
            '->has(',
            '->hasAny(',
            'request(',
            '->fill(',
            '->update(',
            '::create(',
            'Hash::',
            'Gate::',
            'Policy',
            'Notification',
            'Audit',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $method);
        }
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

    private function endpoint(User $target): string
    {
        return "/__tests/admin/users/{$target->getKey()}/email";
    }

    private function methodSource(string $path, string $methodName): string
    {
        require_once $path;

        $method = new ReflectionMethod(ChangeUserEmailController::class, $methodName);
        $lines = file($path);

        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    private function compactPhpSource(string $source): string
    {
        $tokens = token_get_all("<?php\n".$source);
        $compacted = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $compacted .= $token[1];

                continue;
            }

            $compacted .= $token;
        }

        return $compacted;
    }
}
