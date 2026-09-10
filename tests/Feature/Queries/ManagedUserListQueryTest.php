<?php

namespace Tests\Feature\Queries;

use App\Models\Organization;
use App\Models\User;
use App\Queries\ManagedUserListQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagedUserListQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_sees_only_organization_users_across_all_statuses(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $expected = [];

        foreach ([Organization::STATUS_ACTIVE, Organization::STATUS_TRIAL, Organization::STATUS_SUSPENDED] as $status) {
            $organization = Organization::factory()->create(['status' => $status]);
            $expected[] = User::factory()->for($organization)->organizationAdmin()->create()->id;
            $expected[] = User::factory()->for($organization)->inventoryAgent()->create()->id;
            $expected[] = User::factory()->for($organization)->inventoryAgent()->inactive()->create()->id;
            User::factory()->for($organization)->create(['role' => User::ROLE_PLATFORM_ADMIN]);
            User::factory()->for($organization)->create(['role' => 'unknown-role']);
        }

        User::factory()->organizationAdmin()->create();
        User::factory()->inventoryAgent()->create();

        $result = (new ManagedUserListQuery)->paginate($actor);

        $this->assertPage($result, $expected, 9, 1, 1);
    }

    #[DataProvider('accessibleOrganizationStatuses')]
    public function test_org_admin_sees_all_and_only_own_organization_users(string $status): void
    {
        $organization = Organization::factory()->create(['status' => $status]);
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();
        User::factory()->for($otherOrganization)->organizationAdmin()->create();
        $admin = User::factory()->for($organization)->organizationAdmin()->create();
        $inactiveAdmin = User::factory()->for($organization)->organizationAdmin()->inactive()->create();
        User::factory()->for($otherOrganization)->inventoryAgent()->inactive()->create();
        $agent = User::factory()->for($organization)->inventoryAgent()->create();
        $inactiveAgent = User::factory()->for($organization)->inventoryAgent()->inactive()->create();
        User::factory()->for($organization)->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        User::factory()->for($organization)->create(['role' => 'unknown-role']);
        User::factory()->organizationAdmin()->create();
        User::factory()->inventoryAgent()->create();

        $result = (new ManagedUserListQuery)->paginate($actor);

        $this->assertPage($result, [$actor->id, $admin->id, $inactiveAdmin->id, $agent->id, $inactiveAgent->id], 5, 1, 1);
    }

    public static function accessibleOrganizationStatuses(): array
    {
        return [
            'active' => [Organization::STATUS_ACTIVE],
            'trial' => [Organization::STATUS_TRIAL],
        ];
    }

    #[DataProvider('searchFields')]
    public function test_partial_search_excludes_invalid_users_even_when_they_match(string $field): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->platformAdmin()->create();
        $attributes = [$field => $field === 'name' ? 'Before needle After' : 'before.needle.after@example.test'];
        $matching = User::factory()->for($organization)->inventoryAgent()->inactive()->create($attributes);
        User::factory()->for($organization)->inventoryAgent()->create([
            'name' => 'Unrelated Person', 'email' => 'unrelated@example.test',
        ]);

        foreach ([User::ROLE_PLATFORM_ADMIN, 'unknown-role'] as $role) {
            User::factory()->for($organization)->create([
                'role' => $role,
                'name' => 'Before needle After',
                'email' => $role.'-needle@example.test',
            ]);
        }

        foreach ([User::ROLE_ORG_ADMIN, User::ROLE_INVENTORY_AGENT, User::ROLE_PLATFORM_ADMIN] as $role) {
            User::factory()->create([
                'role' => $role,
                'organization_id' => null,
                'name' => 'Before needle After',
                'email' => 'orphan-'.$role.'-needle@example.test',
            ]);
        }

        $result = (new ManagedUserListQuery)->paginate($actor, 'needle');

        $this->assertPage($result, [$matching->id], 1, 1, 1);
    }

    public static function searchFields(): array
    {
        return ['name' => ['name'], 'email' => ['email']];
    }

    public function test_users_must_reference_an_existing_organization(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->platformAdmin()->create();
        $member = User::factory()->for($organization)->inventoryAgent()->create();

        // Defer the FK check only within RefreshDatabase's rolled-back SQLite transaction.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        User::factory()->organizationAdmin()->create(['organization_id' => $organization->id + 1000]);
        User::factory()->inventoryAgent()->create(['organization_id' => $organization->id + 1000]);

        $result = (new ManagedUserListQuery)->paginate($actor);

        $this->assertPage($result, [$member->id], 1, 1, 1);
    }

    #[DataProvider('searchFields')]
    public function test_org_admin_partial_search_stays_within_own_organization(string $field): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create([
            'name' => 'Local Admin', 'email' => 'admin@example.test',
        ]);
        $matching = User::factory()->for($organization)->inventoryAgent()->create([
            'name' => $field === 'name' ? 'Before needle After' : 'Local Member',
            'email' => $field === 'email' ? 'before.needle.after@example.test' : 'member@example.test',
        ]);
        User::factory()->for($otherOrganization)->inventoryAgent()->create([
            'name' => 'Outside Person', 'email' => 'outside.needle@example.test',
        ]);

        $result = (new ManagedUserListQuery)->paginate($actor, 'needle');

        $this->assertPage($result, [$matching->id], 1, 1, 1);
    }

    public function test_search_matching_only_another_organization_returns_no_items_or_total(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create([
            'name' => 'Local Admin', 'email' => 'local@example.test',
        ]);
        User::factory()->for(Organization::factory()->active())->inventoryAgent()->create([
            'name' => 'Outside needle Person', 'email' => 'outside.needle@example.test',
        ]);

        $result = (new ManagedUserListQuery)->paginate($actor, 'needle');

        $this->assertPage($result, [], 0, 1, 1);
    }

    #[DataProvider('emptySearches')]
    public function test_null_and_empty_search_return_the_entire_allowed_scope(?string $search): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();
        User::factory()->for(Organization::factory()->active())->inventoryAgent()->create();
        $member = User::factory()->for($organization)->inventoryAgent()->create();

        $result = (new ManagedUserListQuery)->paginate($actor, $search);

        $this->assertPage($result, [$actor->id, $member->id], 2, 1, 1);
    }

    public static function emptySearches(): array
    {
        return ['null' => [null], 'empty' => ['']];
    }

    #[DataProvider('paginationSearches')]
    public function test_pagination_uses_scoped_totals_id_order_and_explicit_page(?string $search): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create(['name' => 'needle Zulu']);
        $expected = [$actor->id];

        for ($index = 0; $index < 18; $index++) {
            User::factory()->for($otherOrganization)->inventoryAgent()->create([
                'name' => 'needle Outside', 'email' => 'outside.needle.'.$index.'@example.test',
            ]);
            $expected[] = User::factory()->for($organization)->inventoryAgent()->create([
                'name' => 'needle Member '.(18 - $index),
            ])->id;
        }

        $nonMatching = User::factory()->for($organization)->inventoryAgent()->create([
            'name' => 'Alpha', 'email' => 'alpha@example.test',
        ]);
        if ($search === null) {
            $expected[] = $nonMatching->id;
        }

        request()->query->replace([
            'page' => 99, 'search' => 'no-match', 'organization_id' => $otherOrganization->id,
        ]);
        $query = new ManagedUserListQuery;
        $total = count($expected);

        $this->assertPage($query->paginate($actor, $search, 1), array_slice($expected, 0, 15), $total, 1, 2);
        $this->assertPage($query->paginate($actor, $search, 2), array_slice($expected, 15), $total, 2, 2);
        $this->assertPage($query->paginate($actor, $search, 3), [], $total, 3, 2);
    }

    public static function paginationSearches(): array
    {
        return ['without search' => [null], 'with search' => ['needle']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unauthorized_actor_is_rejected(string $role, bool $active, ?string $status): void
    {
        $organization = $status === null ? null : Organization::factory()->create(['status' => $status]);
        $actor = User::factory()->create([
            'role' => $role, 'is_active' => $active, 'organization_id' => $organization?->id,
        ]);
        User::factory()->for(Organization::factory()->active())->inventoryAgent()->create();
        $query = new ManagedUserListQuery;

        $this->expectException(AuthorizationException::class);

        $query->paginate($actor);
    }

    public static function unauthorizedActors(): array
    {
        return [
            'active inventory agent' => [User::ROLE_INVENTORY_AGENT, true, Organization::STATUS_ACTIVE],
            'inactive platform admin' => [User::ROLE_PLATFORM_ADMIN, false, null],
            'inactive org admin' => [User::ROLE_ORG_ADMIN, false, Organization::STATUS_ACTIVE],
            'org admin without organization' => [User::ROLE_ORG_ADMIN, true, null],
            'org admin in suspended organization' => [User::ROLE_ORG_ADMIN, true, Organization::STATUS_SUSPENDED],
            'unknown role' => ['unknown-role', true, Organization::STATUS_ACTIVE],
        ];
    }

    public function test_query_authorizes_view_any_through_the_explicit_actors_gate(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $query = new ManagedUserListQuery;
        $gate = Mockery::mock(GateContract::class);
        Gate::shouldReceive('forUser')->once()->with($actor)->andReturn($gate);
        $gate->shouldReceive('authorize')->once()->with('viewAny', User::class)
            ->andThrow(new AuthorizationException('Denied by gate'));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Denied by gate');

        $query->paginate($actor);
    }

    public function test_explicit_actor_controls_scope_despite_another_authenticated_admin(): void
    {
        $organizationA = Organization::factory()->active()->create();
        $organizationB = Organization::factory()->active()->create();
        $actor = User::factory()->for($organizationA)->organizationAdmin()->create();
        $authenticated = User::factory()->for($organizationB)->organizationAdmin()->create();
        $member = User::factory()->for($organizationA)->inventoryAgent()->create();
        User::factory()->for($organizationB)->inventoryAgent()->create();
        $this->actingAs($authenticated);

        $result = (new ManagedUserListQuery)->paginate($actor);

        $this->assertPage($result, [$actor->id, $member->id], 2, 1, 1);
    }

    public function test_reads_leave_raw_database_snapshots_and_user_count_unchanged(): void
    {
        $organization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create([
            'name' => 'Local Admin', 'email' => 'admin@example.test',
        ]);
        $member = User::factory()->for($organization)->inventoryAgent()->inactive()->create([
            'name' => 'needle Member', 'last_login_at' => '2025-01-02 03:04:05',
        ]);
        User::factory()->for(Organization::factory()->suspended())->inventoryAgent()->create();
        $usersBefore = DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $organizationsBefore = DB::table('organizations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $countBefore = DB::table('users')->count();
        $this->travel(1)->hours();
        $query = new ManagedUserListQuery;

        $this->assertPage($query->paginate($actor), [$actor->id, $member->id], 2, 1, 1);
        $this->assertPage($query->paginate($actor, 'needle'), [$member->id], 1, 1, 1);
        $this->assertPage($query->paginate($actor, null, 2), [], 2, 2, 1);

        $this->assertSame($countBefore, DB::table('users')->count());
        $this->assertSame($usersBefore, DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($organizationsBefore, DB::table('organizations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    private function assertPage(LengthAwarePaginator $result, array $ids, int $total, int $page, int $lastPage): void
    {
        $this->assertSame($ids, array_map(fn (User $user) => $user->id, $result->items()));
        $this->assertSame($total, $result->total());
        $this->assertSame(15, $result->perPage());
        $this->assertSame($page, $result->currentPage());
        $this->assertSame($lastPage, $result->lastPage());
    }
}
