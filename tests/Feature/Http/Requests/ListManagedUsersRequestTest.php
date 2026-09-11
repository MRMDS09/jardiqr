<?php

namespace Tests\Feature\Http\Requests;

use App\Http\Requests\Admin\ListManagedUsersRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListManagedUsersRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep expected HTTP errors, but expose missing classes and setup failures.
        $this->withoutExceptionHandling([
            AuthorizationException::class,
            ValidationException::class,
        ]);

        Route::get('/__tests/admin/managed-users', function (ListManagedUsersRequest $request): JsonResponse {
            return response()->json($request->validated());
        });
    }

    #[DataProvider('authorizedActors')]
    public function test_authorized_actor_can_submit_without_optional_fields(string $role, ?string $status): void
    {
        $organization = $status === null ? null : Organization::factory()->create(['status' => $status]);
        $actor = User::factory()->create([
            'role' => $role,
            'organization_id' => $organization?->id,
            'is_active' => true,
        ]);

        $this->getWithoutWrites($actor, [])->assertOk()->assertExactJson([]);
    }

    public static function authorizedActors(): array
    {
        return [
            'active platform admin' => [User::ROLE_PLATFORM_ADMIN, null],
            'active organization admin' => [User::ROLE_ORG_ADMIN, Organization::STATUS_ACTIVE],
            'trial organization admin' => [User::ROLE_ORG_ADMIN, Organization::STATUS_TRIAL],
        ];
    }

    #[DataProvider('validQueries')]
    public function test_validated_data_preserves_valid_values_and_omits_absent_or_unrelated_fields(
        array $query,
        array $expected
    ): void {
        $actor = User::factory()->platformAdmin()->create();

        $response = $this->getWithoutWrites($actor, $query)->assertOk()->assertExactJson($expected);

        $this->assertSame($expected, $response->json());
    }

    public static function validQueries(): array
    {
        return [
            'ordinary search without page' => [['search' => 'Ada Lovelace'], ['search' => 'Ada Lovelace']],
            '255 character search' => [['search' => str_repeat('a', 255)], ['search' => str_repeat('a', 255)]],
            'zero remains a string' => [['search' => '0'], ['search' => '0']],
            'empty search becomes null' => [['search' => ''], ['search' => null]],
            'outer whitespace is trimmed' => [['search' => '  Ada Lovelace  '], ['search' => 'Ada Lovelace']],
            'page one without search' => [['page' => '1'], ['page' => '1']],
            'page two without search' => [['page' => '2'], ['page' => '2']],
            'search and page' => [['search' => 'Ada', 'page' => '2'], ['search' => 'Ada', 'page' => '2']],
            'unrelated field alone' => [['unrelated_field' => 'ignored'], []],
            'unrelated field with filters' => [
                ['search' => 'Ada', 'page' => '1', 'unrelated_field' => 'ignored'],
                ['search' => 'Ada', 'page' => '1'],
            ],
        ];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unauthorized_actor_receives_403(?string $role, bool $active, ?string $status): void
    {
        $organization = $status === null ? null : Organization::factory()->create(['status' => $status]);
        $actor = $role === null ? null : User::factory()->create([
            'role' => $role,
            'is_active' => $active,
            'organization_id' => $organization?->id,
        ]);

        $this->getWithoutWrites($actor, ['search' => 'Ada', 'page' => '1'])->assertForbidden();
    }

    public static function unauthorizedActors(): array
    {
        return [
            'guest' => [null, true, null],
            'active inventory agent' => [User::ROLE_INVENTORY_AGENT, true, Organization::STATUS_ACTIVE],
            'inactive platform admin' => [User::ROLE_PLATFORM_ADMIN, false, null],
            'inactive org admin' => [User::ROLE_ORG_ADMIN, false, Organization::STATUS_ACTIVE],
            'org admin without organization' => [User::ROLE_ORG_ADMIN, true, null],
            'suspended org admin' => [User::ROLE_ORG_ADMIN, true, Organization::STATUS_SUSPENDED],
            'unknown role' => ['unknown-role', true, Organization::STATUS_ACTIVE],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_receives_422(string $field, mixed $value): void
    {
        $actor = User::factory()->platformAdmin()->create();

        $this->getWithoutWrites($actor, [$field => $value])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field]);
    }

    public static function invalidQueries(): array
    {
        return [
            'search array' => ['search', ['Ada']],
            'search exceeds 255 characters' => ['search', str_repeat('a', 256)],
            'page zero' => ['page', '0'],
            'page negative' => ['page', '-1'],
            'page fractional' => ['page', '1.5'],
            'page nonnumeric' => ['page', 'abc'],
            'page empty' => ['page', ''],
            'page array' => ['page', ['1']],
        ];
    }

    #[DataProvider('forbiddenFields')]
    public function test_forbidden_field_must_be_missing_even_when_empty(string $field, string $value): void
    {
        $actor = User::factory()->platformAdmin()->create();

        $this->getWithoutWrites($actor, ['search' => 'Ada', 'page' => '1', $field => $value])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field]);
    }

    public static function forbiddenFields(): array
    {
        $cases = [];

        foreach ([
            'organization_id' => '123',
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => '1',
            'per_page' => '100',
            'sort' => 'name',
            'direction' => 'desc',
        ] as $field => $value) {
            $cases[$field.' with value'] = [$field, $value];
            $cases[$field.' empty'] = [$field, ''];
        }

        return $cases;
    }

    #[DataProvider('injectedAuthorizationFields')]
    public function test_org_admin_authorization_is_independent_of_forbidden_query_fields(string $field): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();
        $value = $field === 'organization_id' ? (string) $otherOrganization->id : User::ROLE_PLATFORM_ADMIN;

        $this->getWithoutWrites($actor, [$field => $value])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field]);
    }

    #[DataProvider('injectedAuthorizationFields')]
    public function test_inventory_agent_cannot_gain_authorization_from_query_fields(string $field): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->inventoryAgent()->create();
        $value = $field === 'organization_id' ? (string) $otherOrganization->id : User::ROLE_PLATFORM_ADMIN;

        $this->getWithoutWrites($actor, [$field => $value])->assertForbidden();
    }

    public static function injectedAuthorizationFields(): array
    {
        return ['other organization' => ['organization_id'], 'platform role' => ['role']];
    }

    private function getWithoutWrites(?User $actor, array $query): TestResponse
    {
        if ($actor !== null) {
            $this->actingAs($actor);
        }

        // All fixtures exist before these raw snapshots; no models hide or cast columns.
        $usersBefore = DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $organizationsBefore = DB::table('organizations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $countBefore = DB::table('users')->count();
        $this->travel(1)->hours();

        $response = $this->getJson('/__tests/admin/managed-users'.($query === [] ? '' : '?'.http_build_query($query)));

        $this->assertSame($countBefore, DB::table('users')->count());
        $this->assertSame($usersBefore, DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($organizationsBefore, DB::table('organizations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());

        return $response;
    }
}
