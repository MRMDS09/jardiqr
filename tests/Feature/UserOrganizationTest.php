<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_organization_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'organization_id',
            'phone',
            'role',
            'is_active',
            'last_login_at',
        ]));
    }

    public function test_platform_admin_can_exist_without_an_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->assertNull($user->organization_id);
        $this->assertSame(User::ROLE_PLATFORM_ADMIN, $user->role);
    }

    public function test_organization_roles_can_belong_to_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->organizationAdmin()->for($organization)->create();
        $agent = User::factory()->inventoryAgent()->for($organization)->create();

        $this->assertTrue($admin->organization->is($organization));
        $this->assertTrue($agent->organization->is($organization));
        $this->assertTrue($organization->users->contains($admin));
        $this->assertTrue($organization->users->contains($agent));
    }

    public function test_user_status_and_last_login_are_cast(): void
    {
        $user = User::factory()->inactive()->create([
            'last_login_at' => '2026-08-20 12:30:00',
        ]);

        $this->assertFalse($user->is_active);
        $this->assertInstanceOf(Carbon::class, $user->last_login_at);
    }

    public function test_user_cannot_reference_a_missing_organization(): void
    {
        $this->expectException(QueryException::class);

        User::factory()->create(['organization_id' => 999999]);
    }

    public function test_organization_with_users_cannot_be_deleted(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->for($organization)->create();

        $this->expectException(QueryException::class);

        $organization->delete();
    }

    public function test_database_defaults_to_inventory_agent_and_active(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Default Role User',
            'email' => 'default-role@example.test',
            'password' => 'not-used-in-this-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::findOrFail($id);

        $this->assertSame(User::ROLE_INVENTORY_AGENT, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->organization_id);
    }
}
