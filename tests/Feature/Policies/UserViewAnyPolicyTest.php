<?php

namespace Tests\Feature\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserViewAnyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_platform_admin_can_view_any_users(): void
    {
        $actor = User::factory()->platformAdmin()->create(['is_active' => true]);

        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_active_org_admin_in_active_organization_can_view_any_users(): void
    {
        $actor = User::factory()
            ->for(Organization::factory()->active())
            ->organizationAdmin()
            ->create(['is_active' => true]);

        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_active_org_admin_in_trial_organization_can_view_any_users(): void
    {
        $actor = User::factory()
            ->for(Organization::factory()->state(['status' => Organization::STATUS_TRIAL]))
            ->organizationAdmin()
            ->create(['is_active' => true]);

        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_inactive_platform_admin_cannot_view_any_users(): void
    {
        $actor = User::factory()->platformAdmin()->inactive()->create();

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_inactive_org_admin_in_active_organization_cannot_view_any_users(): void
    {
        $actor = User::factory()
            ->for(Organization::factory()->active())
            ->organizationAdmin()
            ->inactive()
            ->create();

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_org_admin_in_suspended_organization_cannot_view_any_users(): void
    {
        $actor = User::factory()
            ->for(Organization::factory()->suspended())
            ->organizationAdmin()
            ->create(['is_active' => true]);

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_org_admin_without_organization_cannot_view_any_users(): void
    {
        $actor = User::factory()->organizationAdmin()->create([
            'is_active' => true,
            'organization_id' => null,
        ]);

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_active_inventory_agent_in_active_organization_cannot_view_any_users(): void
    {
        $actor = User::factory()
            ->for(Organization::factory()->active())
            ->inventoryAgent()
            ->create(['is_active' => true]);

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_active_inventory_agent_in_trial_organization_cannot_view_any_users(): void
    {
        $actor = User::factory()
            ->for(Organization::factory()->state(['status' => Organization::STATUS_TRIAL]))
            ->inventoryAgent()
            ->create(['is_active' => true]);

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_active_user_with_unknown_role_cannot_view_any_users(): void
    {
        $actor = User::factory()
            ->for(Organization::factory()->active())
            ->create(['is_active' => true, 'role' => 'unknown-role']);

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
    }

    public function test_guest_cannot_view_any_users(): void
    {
        $this->assertGuest();

        $this->assertFalse(Gate::allows('viewAny', User::class));
    }
}
