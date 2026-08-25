<?php

namespace Tests\Feature\Authorization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OrganizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_view_any_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->assertTrue(
            Gate::forUser($user)->allows('viewAny', Organization::class)
        );
    }

    public function test_platform_admin_can_view_any_specific_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();

        $this->assertTrue(
            Gate::forUser($user)->allows('view', $organization)
        );
    }

    public function test_platform_admin_can_create_an_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->assertTrue(
            Gate::forUser($user)->allows('create', Organization::class)
        );
    }

    public function test_platform_admin_can_update_any_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();

        $this->assertTrue(
            Gate::forUser($user)->allows('update', $organization)
        );
    }

    public function test_platform_admin_can_delete_an_empty_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->active()->create();

        $this->assertTrue(
            Gate::forUser($user)->allows('delete', $organization)
        );
    }

    public function test_platform_admin_can_view_and_update_a_suspended_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();
        $organization = Organization::factory()->suspended()->create();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('view', $organization));
        $this->assertTrue($gate->allows('update', $organization));
    }

    public function test_organization_admin_cannot_view_any_organization(): void
    {
        [$user] = $this->createOrganizationAdmin();

        $this->assertTrue(
            Gate::forUser($user)->denies('viewAny', Organization::class)
        );
    }

    public function test_organization_admin_can_view_own_organization(): void
    {
        [$user, $organization] = $this->createOrganizationAdmin();

        $this->assertTrue(
            Gate::forUser($user)->allows('view', $organization)
        );
    }

    public function test_organization_admin_can_update_own_organization(): void
    {
        [$user, $organization] = $this->createOrganizationAdmin();

        $this->assertTrue(
            Gate::forUser($user)->allows('update', $organization)
        );
    }

    public function test_organization_admin_in_trial_organization_can_only_view_and_update_own_organization(): void
    {
        $organization = Organization::factory()->create([
            'status' => Organization::STATUS_TRIAL,
        ]);
        $user = User::factory()
            ->for($organization)
            ->organizationAdmin()
            ->create();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('view', $organization));
        $this->assertTrue($gate->allows('update', $organization));
        $this->assertTrue($gate->denies('viewAny', Organization::class));
        $this->assertTrue($gate->denies('create', Organization::class));
        $this->assertTrue($gate->denies('delete', $organization));
    }

    public function test_organization_admin_cannot_view_another_organization(): void
    {
        [$user] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();

        $this->assertTrue(
            Gate::forUser($user)->denies('view', $otherOrganization)
        );
    }

    public function test_organization_admin_cannot_update_another_organization(): void
    {
        [$user] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();

        $this->assertTrue(
            Gate::forUser($user)->denies('update', $otherOrganization)
        );
    }

    public function test_organization_admin_cannot_create_an_organization(): void
    {
        [$user] = $this->createOrganizationAdmin();

        $this->assertTrue(
            Gate::forUser($user)->denies('create', Organization::class)
        );
    }

    public function test_organization_admin_cannot_delete_own_or_another_organization(): void
    {
        [$user, $organization] = $this->createOrganizationAdmin();
        $otherOrganization = Organization::factory()->active()->create();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->denies('delete', $organization));
        $this->assertTrue($gate->denies('delete', $otherOrganization));
    }

    public function test_inventory_agent_cannot_manage_any_organization(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $user = User::factory()
            ->for($organization)
            ->inventoryAgent()
            ->create();

        $this->assertDeniedForAllOrganizationAbilities(
            Gate::forUser($user),
            $organization,
            $otherOrganization
        );
    }

    public function test_unknown_role_cannot_manage_any_organization(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $user = User::factory()->for($organization)->create([
            'role' => 'unknown-role',
        ]);

        $this->assertDeniedForAllOrganizationAbilities(
            Gate::forUser($user),
            $organization,
            $otherOrganization
        );
    }

    public function test_organization_user_without_organization_cannot_manage_any_organization(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $user = User::factory()->organizationAdmin()->create([
            'organization_id' => null,
        ]);

        $this->assertDeniedForAllOrganizationAbilities(
            Gate::forUser($user),
            $organization,
            $otherOrganization
        );
    }

    public function test_inactive_user_cannot_manage_any_organization(): void
    {
        $organization = Organization::factory()->active()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $user = User::factory()->platformAdmin()->inactive()->create();

        $this->assertDeniedForAllOrganizationAbilities(
            Gate::forUser($user),
            $organization,
            $otherOrganization
        );
    }

    public function test_organization_admin_in_suspended_organization_cannot_manage_any_organization(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $otherOrganization = Organization::factory()->active()->create();
        $user = User::factory()
            ->for($organization)
            ->organizationAdmin()
            ->create();

        $this->assertDeniedForAllOrganizationAbilities(
            Gate::forUser($user),
            $organization,
            $otherOrganization
        );
    }

    /**
     * @return array{User, Organization}
     */
    private function createOrganizationAdmin(): array
    {
        $organization = Organization::factory()->active()->create();
        $user = User::factory()
            ->for($organization)
            ->organizationAdmin()
            ->create();

        return [$user, $organization];
    }

    private function assertDeniedForAllOrganizationAbilities(
        GateContract $gate,
        Organization $organization,
        Organization $otherOrganization
    ): void {
        $this->assertTrue($gate->denies('viewAny', Organization::class));
        $this->assertTrue($gate->denies('create', Organization::class));

        foreach ([$organization, $otherOrganization] as $target) {
            $this->assertTrue($gate->denies('view', $target));
            $this->assertTrue($gate->denies('update', $target));
            $this->assertTrue($gate->denies('delete', $target));
        }
    }
}
