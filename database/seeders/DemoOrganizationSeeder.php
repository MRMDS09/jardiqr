<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'Demo data can only be seeded in the local environment.'
            );
        }

        if (! config('jardi.demo.enabled')) {
            throw new RuntimeException(
                'Demo organization seeding is disabled.'
            );
        }

        $organization = $this->organizationSettings();
        $orgAdmin = $this->userSettings('org_admin');
        $inventoryAgent = $this->userSettings('inventory_agent');

        $this->validateOrganization($organization);
        $this->validateUser($orgAdmin, 'organization administrator');
        $this->validateUser($inventoryAgent, 'inventory agent');

        if ($orgAdmin['email'] === $inventoryAgent['email']) {
            throw new RuntimeException(
                'Demo users must have different email addresses.'
            );
        }

        if (hash_equals($orgAdmin['password'], $inventoryAgent['password'])) {
            throw new RuntimeException(
                'Demo users must have different passwords.'
            );
        }

        DB::transaction(function () use (
            $organization,
            $orgAdmin,
            $inventoryAgent
        ): void {
            $organizationModel = $this->findOrCreateOrganization(
                $organization
            );

            $this->findOrCreateUser(
                $orgAdmin,
                User::ROLE_ORG_ADMIN,
                $organizationModel
            );

            $this->findOrCreateUser(
                $inventoryAgent,
                User::ROLE_INVENTORY_AGENT,
                $organizationModel
            );
        });

        $this->command?->info(
            'Demo organization and users are ready.'
        );
    }

    private function organizationSettings(): array
    {
        return [
            'name' => trim(
                (string) config('jardi.demo.organization.name')
            ),
            'slug' => strtolower(trim(
                (string) config('jardi.demo.organization.slug')
            )),
            'email' => strtolower(trim(
                (string) config('jardi.demo.organization.email')
            )),
            'phone' => trim(
                (string) config('jardi.demo.organization.phone')
            ),
            'trial_days' => (int) config(
                'jardi.demo.organization.trial_days'
            ),
        ];
    }

    private function userSettings(string $key): array
    {
        return [
            'name' => trim(
                (string) config("jardi.demo.{$key}.name")
            ),
            'email' => strtolower(trim(
                (string) config("jardi.demo.{$key}.email")
            )),
            'password' => (string) config(
                "jardi.demo.{$key}.password"
            ),
        ];
    }

    private function validateOrganization(array $organization): void
    {
        if (
            $organization['name'] === ''
            || mb_strlen($organization['name']) > 150
        ) {
            throw new RuntimeException(
                'Demo organization name is missing or too long.'
            );
        }

        if (
            $organization['slug'] === ''
            || mb_strlen($organization['slug']) > 160
            || preg_match(
                '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                $organization['slug']
            ) !== 1
        ) {
            throw new RuntimeException(
                'Demo organization slug is invalid.'
            );
        }

        if (
            $organization['email'] !== ''
            && (
                filter_var(
                    $organization['email'],
                    FILTER_VALIDATE_EMAIL
                ) === false
                || mb_strlen($organization['email']) > 190
            )
        ) {
            throw new RuntimeException(
                'Demo organization email is invalid.'
            );
        }

        if (mb_strlen($organization['phone']) > 30) {
            throw new RuntimeException(
                'Demo organization phone is too long.'
            );
        }

        if (
            $organization['trial_days'] < 1
            || $organization['trial_days'] > 90
        ) {
            throw new RuntimeException(
                'Demo trial days must be between 1 and 90.'
            );
        }
    }

    private function validateUser(array $user, string $label): void
    {
        if ($user['name'] === '' || mb_strlen($user['name']) > 150) {
            throw new RuntimeException(
                "Demo {$label} name is missing or too long."
            );
        }

        if (
            filter_var($user['email'], FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($user['email']) > 190
        ) {
            throw new RuntimeException(
                "Demo {$label} email is invalid."
            );
        }

        if (strlen($user['password']) < 16) {
            throw new RuntimeException(
                "Demo {$label} password must contain at least 16 characters."
            );
        }

        if (
            preg_match('/[a-z]/', $user['password']) !== 1
            || preg_match('/[A-Z]/', $user['password']) !== 1
            || preg_match('/[0-9]/', $user['password']) !== 1
            || preg_match('/[^a-zA-Z0-9]/', $user['password']) !== 1
        ) {
            throw new RuntimeException(
                "Demo {$label} password does not meet complexity requirements."
            );
        }
    }

    private function findOrCreateOrganization(
        array $settings
    ): Organization {
        $existingOrganization = Organization::query()
            ->where('slug', $settings['slug'])
            ->first();

        if ($existingOrganization !== null) {
            $existingEmail = strtolower(
                trim((string) $existingOrganization->email)
            );

            if (
                $existingOrganization->name !== $settings['name']
                || $existingEmail !== $settings['email']
            ) {
                throw new RuntimeException(
                    'The demo organization slug belongs to different data.'
                );
            }

            return $existingOrganization;
        }

        $organization = new Organization;
        $organization->name = $settings['name'];
        $organization->slug = $settings['slug'];
        $organization->email = $settings['email'] ?: null;
        $organization->phone = $settings['phone'] ?: null;
        $organization->address = null;
        $organization->logo_path = null;
        $organization->status = Organization::STATUS_TRIAL;
        $organization->trial_ends_at = now()->addDays(
            $settings['trial_days']
        );
        $organization->save();

        return $organization;
    }

    private function findOrCreateUser(
        array $settings,
        string $role,
        Organization $organization
    ): User {
        $existingUser = User::query()
            ->whereRaw('LOWER(email) = ?', [$settings['email']])
            ->first();

        if ($existingUser !== null) {
            if (
                $existingUser->role !== $role
                || $existingUser->organization_id !== $organization->id
            ) {
                throw new RuntimeException(
                    'A demo email already belongs to another account.'
                );
            }

            return $existingUser;
        }

        $user = new User;
        $user->organization_id = $organization->id;
        $user->name = $settings['name'];
        $user->email = $settings['email'];
        $user->email_verified_at = now();
        $user->phone = null;
        $user->password = Hash::make($settings['password']);
        $user->role = $role;
        $user->is_active = true;
        $user->last_login_at = null;
        $user->save();

        return $user;
    }
}
