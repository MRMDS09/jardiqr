<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_view_any_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();

        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_platform_admin_can_view_user_from_any_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->assertTrue(Gate::forUser($actor)->allows('view', $target));
    }

    public function test_platform_admin_can_create_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();

        $this->assertTrue(Gate::forUser($actor)->allows('create', User::class));
    }

    public function test_platform_admin_can_update_user_from_any_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->assertTrue(Gate::forUser($actor)->allows('update', $target));
    }

    public function test_platform_admin_can_delete_regular_user_from_any_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->assertTrue(Gate::forUser($actor)->allows('delete', $target));
    }

    public function test_platform_admin_can_create_organization_roles_for_an_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->allows(
            'createForOrganization',
            [User::class, $organization, User::ROLE_ORG_ADMIN]
        ));
        $this->assertTrue($gate->allows(
            'createForOrganization',
            [User::class, $organization, User::ROLE_INVENTORY_AGENT]
        ));
    }

    public function test_platform_admin_cannot_create_platform_or_unknown_role_for_an_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies(
            'createForOrganization',
            [User::class, $organization, User::ROLE_PLATFORM_ADMIN]
        ));
        $this->assertTrue($gate->denies(
            'createForOrganization',
            [User::class, $organization, 'unknown-role']
        ));
    }

    public function test_platform_admin_can_change_user_between_organization_roles(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->allows('changeRole', [$target, User::ROLE_ORG_ADMIN]));
        $this->assertTrue($gate->allows('changeRole', [$target, User::ROLE_INVENTORY_AGENT]));
    }

    public function test_platform_admin_cannot_assign_unknown_or_platform_role_to_organization_user(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies('changeRole', [$target, 'unknown-role']));
        $this->assertTrue($gate->denies('changeRole', [$target, User::ROLE_PLATFORM_ADMIN]));
    }

    public function test_platform_admin_can_change_regular_user_status(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = $this->createInventoryAgent(Organization::factory()->active()->create());

        $this->assertTrue(Gate::forUser($actor)->allows('changeStatus', $target));
    }

    public function test_platform_admin_can_reactivate_inactive_inventory_agent(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $target = User::factory()
            ->for($organization)
            ->inventoryAgent()
            ->inactive()
            ->create();

        $this->assertTrue(Gate::forUser($actor)->allows('changeStatus', $target));
    }

    public function test_platform_admin_can_move_organization_user_to_another_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $newOrganization = Organization::factory()->active()->create();
        $target = $this->createInventoryAgent($organization);

        $this->assertTrue(
            Gate::forUser($actor)->allows('changeOrganization', [$target, $newOrganization])
        );
    }

    public function test_platform_admin_can_move_organization_admin_to_another_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $newOrganization = Organization::factory()->active()->create();
        $target = User::factory()
            ->for($organization)
            ->organizationAdmin()
            ->create();

        $this->assertTrue(
            Gate::forUser($actor)->allows('changeOrganization', [$target, $newOrganization])
        );
    }

    public function test_platform_admin_cannot_attach_platform_admin_to_an_organization(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $target = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();

        $this->assertTrue(
            Gate::forUser($actor)->denies('changeOrganization', [$target, $organization])
        );
    }

    public function test_platform_admin_cannot_manage_platform_admin_accounts_through_general_user_abilities(): void
    {
        $actor = User::factory()->platformAdmin()->create();
        $otherPlatformAdmin = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();
        $gate = Gate::forUser($actor);

        foreach ([$actor, $otherPlatformAdmin] as $target) {
            $this->assertTrue($gate->denies('update', $target));
            $this->assertTrue($gate->denies('delete', $target));
            $this->assertTrue($gate->denies('changeStatus', $target));
            $this->assertTrue($gate->denies(
                'changeRole',
                [$target, User::ROLE_INVENTORY_AGENT]
            ));
            $this->assertTrue($gate->denies(
                'changeOrganization',
                [$target, $organization]
            ));
        }
    }

    public function test_organization_admin_cannot_view_any_user(): void
    {
        [$actor] = $this->createOrganizationAdmin();

        $this->assertTrue(Gate::forUser($actor)->denies('viewAny', User::class));
    }

    public function test_organization_admin_can_view_self(): void
    {
        [$actor] = $this->createOrganizationAdmin();

        $this->assertTrue(Gate::forUser($actor)->allows('view', $actor));
    }

    public function test_organization_admin_can_view_inventory_agent_in_own_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);

        $this->assertTrue(Gate::forUser($actor)->allows('view', $target));
    }

    public function test_organization_admin_can_only_view_another_organization_admin_in_own_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = User::factory()
            ->for($organization)
            ->organizationAdmin()
            ->create();
        $otherOrganization = Organization::factory()->active()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->allows('view', $target));
        $this->assertTrue($gate->denies('update', $target));
        $this->assertTrue($gate->denies('delete', $target));
        $this->assertTrue($gate->denies('changeStatus', $target));
        $this->assertTrue($gate->denies(
            'changeRole',
            [$target, User::ROLE_INVENTORY_AGENT]
        ));
        $this->assertTrue($gate->denies(
            'changeOrganization',
            [$target, $otherOrganization]
        ));
    }

    public function test_organization_admin_cannot_view_other_organization_user_or_platform_admin(): void
    {
        [$actor] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();
        $otherTarget = $this->createInventoryAgent($otherOrganization);
        $platformAdmin = User::factory()->platformAdmin()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies('view', $otherTarget));
        $this->assertTrue($gate->denies('view', $platformAdmin));
    }

    public function test_organization_admin_cannot_create_user_globally(): void
    {
        [$actor] = $this->createOrganizationAdmin();

        $this->assertTrue(Gate::forUser($actor)->denies('create', User::class));
    }

    public function test_organization_admin_can_create_inventory_agent_in_own_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();

        $this->assertTrue(Gate::forUser($actor)->allows(
            'createForOrganization',
            [User::class, $organization, User::ROLE_INVENTORY_AGENT]
        ));
    }

    public function test_organization_admin_cannot_create_user_outside_scope_or_with_elevated_role(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies(
            'createForOrganization',
            [User::class, $otherOrganization, User::ROLE_INVENTORY_AGENT]
        ));
        $this->assertTrue($gate->denies(
            'createForOrganization',
            [User::class, $organization, User::ROLE_ORG_ADMIN]
        ));
        $this->assertTrue($gate->denies(
            'createForOrganization',
            [User::class, $organization, User::ROLE_PLATFORM_ADMIN]
        ));
        $this->assertTrue($gate->denies(
            'createForOrganization',
            [User::class, $organization, 'unknown-role']
        ));
    }

    public function test_organization_admin_can_update_inventory_agent_in_own_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);

        $this->assertTrue(Gate::forUser($actor)->allows('update', $target));
    }

    public function test_organization_admin_cannot_update_outside_user_platform_admin_or_other_org_admin(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();
        $otherTarget = $this->createInventoryAgent($otherOrganization);
        $platformAdmin = User::factory()->platformAdmin()->create();
        $otherAdmin = User::factory()->for($organization)->organizationAdmin()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies('update', $otherTarget));
        $this->assertTrue($gate->denies('update', $platformAdmin));
        $this->assertTrue($gate->denies('update', $otherAdmin));
    }

    public function test_organization_admin_can_delete_inventory_agent_in_own_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);

        $this->assertTrue(Gate::forUser($actor)->allows('delete', $target));
    }

    public function test_organization_admin_cannot_delete_outside_user_platform_admin_or_org_admin(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();
        $otherTarget = $this->createInventoryAgent($otherOrganization);
        $platformAdmin = User::factory()->platformAdmin()->create();
        $otherAdmin = User::factory()->for($organization)->organizationAdmin()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies('delete', $otherTarget));
        $this->assertTrue($gate->denies('delete', $platformAdmin));
        $this->assertTrue($gate->denies('delete', $otherAdmin));
    }

    public function test_organization_admin_can_change_status_of_inventory_agent_in_own_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);

        $this->assertTrue(Gate::forUser($actor)->allows('changeStatus', $target));
    }

    public function test_organization_admin_can_reactivate_inactive_inventory_agent_in_own_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = User::factory()
            ->for($organization)
            ->inventoryAgent()
            ->inactive()
            ->create();

        $this->assertTrue(Gate::forUser($actor)->allows('changeStatus', $target));
    }

    public function test_organization_admin_cannot_change_status_outside_scope_or_for_privileged_user(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();
        $otherTarget = $this->createInventoryAgent($otherOrganization);
        $otherAdmin = User::factory()->for($organization)->organizationAdmin()->create();
        $platformAdmin = User::factory()->platformAdmin()->create();
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies('changeStatus', $otherTarget));
        $this->assertTrue($gate->denies('changeStatus', $otherAdmin));
        $this->assertTrue($gate->denies('changeStatus', $platformAdmin));
    }

    public function test_organization_admin_cannot_change_any_user_role(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $target = $this->createInventoryAgent($organization);
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies('changeRole', [$target, User::ROLE_INVENTORY_AGENT]));
        $this->assertTrue($gate->denies('changeRole', [$target, User::ROLE_ORG_ADMIN]));
        $this->assertTrue($gate->denies('changeRole', [$target, User::ROLE_PLATFORM_ADMIN]));
        $this->assertTrue($gate->denies('changeRole', [$target, 'unknown-role']));
    }

    public function test_organization_admin_cannot_change_any_user_organization(): void
    {
        [$actor, $organization] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();
        $ownTarget = $this->createInventoryAgent($organization);
        $outsideTarget = $this->createInventoryAgent($otherOrganization);
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->denies('changeOrganization', [$ownTarget, $otherOrganization]));
        $this->assertTrue($gate->denies('changeOrganization', [$outsideTarget, $organization]));
    }

    public function test_inventory_agent_cannot_manage_users(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = $this->createInventoryAgent($organization);
        $target = $this->createInventoryAgent($organization);

        $this->assertDeniedForAllUserAbilities(
            Gate::forUser($actor),
            $target,
            $organization,
            $otherOrganization
        );
    }

    public function test_unknown_role_cannot_manage_users(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->create(['role' => 'unknown-role']);
        $target = $this->createInventoryAgent($organization);

        $this->assertDeniedForAllUserAbilities(
            Gate::forUser($actor),
            $target,
            $organization,
            $otherOrganization
        );
    }

    public function test_inactive_user_cannot_manage_users(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->platformAdmin()->inactive()->create();
        $target = $this->createInventoryAgent($organization);

        $this->assertDeniedForAllUserAbilities(
            Gate::forUser($actor),
            $target,
            $organization,
            $otherOrganization
        );
    }

    public function test_organization_user_without_organization_cannot_manage_users(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->organizationAdmin()->create(['organization_id' => null]);
        $target = $this->createInventoryAgent($organization);

        $this->assertDeniedForAllUserAbilities(
            Gate::forUser($actor),
            $target,
            $organization,
            $otherOrganization
        );
    }

    public function test_organization_admin_in_suspended_organization_cannot_manage_users(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();
        $target = $this->createInventoryAgent($organization);

        $this->assertDeniedForAllUserAbilities(
            Gate::forUser($actor),
            $target,
            $organization,
            $otherOrganization
        );
    }

    public function test_organization_admin_in_trial_organization_has_scoped_user_management(): void
    {
        $organization = Organization::factory()->create([
            'status' => Organization::STATUS_TRIAL,
        ]);
        $otherOrganization = Organization::factory()->active()->create();
        $actor = User::factory()->for($organization)->organizationAdmin()->create();
        $target = $this->createInventoryAgent($organization);
        $outsideTarget = $this->createInventoryAgent($otherOrganization);
        $gate = Gate::forUser($actor);

        $this->assertTrue($gate->allows('view', $target));
        $this->assertTrue($gate->allows('update', $target));
        $this->assertTrue($gate->allows('changeStatus', $target));
        $this->assertTrue($gate->denies('view', $outsideTarget));
        $this->assertTrue($gate->denies('changeRole', [$target, User::ROLE_ORG_ADMIN]));
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

    private function createInventoryAgent(Organization $organization): User
    {
        return User::factory()->for($organization)->inventoryAgent()->create();
    }

    private function assertDeniedForAllUserAbilities(
        GateContract $gate,
        User $target,
        Organization $organization,
        Organization $otherOrganization
    ): void {
        $this->assertTrue($gate->denies('viewAny', User::class));
        $this->assertTrue($gate->denies('view', $target));
        $this->assertTrue($gate->denies('create', User::class));
        $this->assertTrue($gate->denies(
            'createForOrganization',
            [User::class, $organization, User::ROLE_INVENTORY_AGENT]
        ));
        $this->assertTrue($gate->denies('update', $target));
        $this->assertTrue($gate->denies('delete', $target));
        $this->assertTrue($gate->denies('changeRole', [$target, User::ROLE_ORG_ADMIN]));
        $this->assertTrue($gate->denies('changeRole', [$target, 'unknown-role']));
        $this->assertTrue($gate->denies('changeStatus', $target));
        $this->assertTrue($gate->denies(
            'changeOrganization',
            [$target, $otherOrganization]
        ));
    }
}
