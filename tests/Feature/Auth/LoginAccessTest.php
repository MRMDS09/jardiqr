<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_cannot_login_even_when_platform_admin(): void
    {
        $user = User::factory()->platformAdmin()->inactive()->create();

        $response = $this->loginAs($user);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_active_platform_admin_can_login_without_an_organization(): void
    {
        $user = User::factory()->platformAdmin()->create();

        $response = $this->loginAs($user);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_active_user_in_trial_organization_can_login(): void
    {
        $organization = Organization::factory()->create([
            'status' => Organization::STATUS_TRIAL,
        ]);
        $user = User::factory()->for($organization)->create();

        $response = $this->loginAs($user);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_active_user_in_active_organization_can_login(): void
    {
        $organization = Organization::factory()->active()->create();
        $user = User::factory()->for($organization)->create();

        $response = $this->loginAs($user);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_in_suspended_organization_cannot_login(): void
    {
        $organization = Organization::factory()->suspended()->create();
        $user = User::factory()->for($organization)->create();

        $response = $this->loginAs($user);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_non_platform_admin_without_an_organization_cannot_login(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
            'role' => User::ROLE_INVENTORY_AGENT,
        ]);

        $response = $this->loginAs($user);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_successful_login_updates_last_login_at(): void
    {
        $user = User::factory()->platformAdmin()->create([
            'last_login_at' => null,
        ]);

        $this->loginAs($user);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_incorrect_password_does_not_update_last_login_at(): void
    {
        $user = User::factory()->platformAdmin()->create([
            'last_login_at' => null,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ]);

        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertNull($user->refresh()->last_login_at);
        $this->assertGuest();
    }

    public function test_denied_accounts_receive_the_same_generic_authentication_error(): void
    {
        $suspendedOrganization = Organization::factory()->suspended()->create();

        $users = [
            User::factory()->platformAdmin()->inactive()->create(),
            User::factory()->create([
                'organization_id' => null,
                'role' => User::ROLE_INVENTORY_AGENT,
            ]),
            User::factory()->for($suspendedOrganization)->create(),
        ];

        foreach ($users as $user) {
            $response = $this->loginAs($user);

            $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
            $this->assertGuest();
        }
    }

    private function loginAs(User $user)
    {
        return $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
    }
}
