<?php

namespace Tests\Feature\Profile;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_platform_admin_cannot_delete_own_account(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $response = $this
            ->withSession(['session-marker' => 'must-remain'])
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertSessionHas('session-marker', 'must-remain');
        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_platform_admin_does_not_allow_last_active_admin_to_delete_own_account(): void
    {
        $user = User::factory()->platformAdmin()->create();
        $inactiveAdmin = User::factory()->platformAdmin()->inactive()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($inactiveAdmin->fresh());
    }

    public function test_active_platform_admin_can_delete_own_account_when_another_active_admin_exists(): void
    {
        $user = User::factory()->platformAdmin()->create();
        $otherAdmin = User::factory()->platformAdmin()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response->assertRedirect('/');
        $this->assertNull($user->fresh());
        $this->assertNotNull($otherAdmin->fresh());
        $this->assertGuest();
    }

    public function test_incorrect_password_prevents_platform_admin_deletion_before_business_rules(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }

    public function test_authorized_organization_user_can_delete_own_account(): void
    {
        $organization = Organization::factory()->active()->create();
        $user = User::factory()
            ->for($organization)
            ->inventoryAgent()
            ->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response->assertRedirect('/');
        $this->assertNull($user->fresh());
        $this->assertGuest();
    }
}
