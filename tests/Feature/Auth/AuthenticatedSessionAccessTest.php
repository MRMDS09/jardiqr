<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedSessionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_access_public_and_login_routes(): void
    {
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->assertGuest();
    }

    public function test_active_platform_admin_session_remains_authorized_without_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_active_organization_user_session_remains_authorized(): void
    {
        $organization = Organization::factory()->active()->create();
        $user = User::factory()->for($organization)->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_active_user_in_trial_organization_session_remains_authorized(): void
    {
        $organization = Organization::factory()->create([
            'status' => Organization::STATUS_TRIAL,
        ]);
        $user = User::factory()->for($organization)->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_authenticated_user_is_logged_out(): void
    {
        $user = User::factory()->platformAdmin()->inactive()->create();

        $this->assertAccessIsRevoked($user);
    }

    public function test_inactive_authenticated_user_is_logged_out_from_profile(): void
    {
        $user = User::factory()->platformAdmin()->inactive()->create();

        $this->assertAccessIsRevoked($user, '/profile');
    }

    public function test_organization_user_without_organization_is_logged_out(): void
    {
        $user = User::factory()->inventoryAgent()->create([
            'organization_id' => null,
        ]);

        $this->assertAccessIsRevoked($user);
    }

    public function test_user_with_unknown_role_is_logged_out(): void
    {
        $user = User::factory()->create([
            'role' => 'unknown-role',
        ]);

        $this->assertAccessIsRevoked($user);
    }

    public function test_user_is_logged_out_when_loaded_organization_becomes_suspended(): void
    {
        $organization = Organization::factory()->active()->create();
        $user = User::factory()->for($organization)->create();

        $user->load('organization');
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->assertAccessIsRevoked($user);
    }

    private function assertAccessIsRevoked(User $user, string $uri = '/dashboard'): void
    {
        $response = $this
            ->withSession([
                'session-marker' => 'must-be-removed',
                '_token' => 'old-csrf-token',
            ])
            ->actingAs($user)
            ->get($uri);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')])
            ->assertSessionMissing('session-marker')
            ->assertSessionHas(
                '_token',
                fn (string $token): bool => $token !== 'old-csrf-token'
            );
        $this->assertGuest();
    }
}
