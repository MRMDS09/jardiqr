<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->canAccessApplication()) {
            return false;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === User::ROLE_PLATFORM_ADMIN;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->role === User::ROLE_PLATFORM_ADMIN
            || $this->organizationAdminBelongsTo($user, $organization);
    }

    public function create(User $user): bool
    {
        return $user->role === User::ROLE_PLATFORM_ADMIN;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->role === User::ROLE_PLATFORM_ADMIN
            || $this->organizationAdminBelongsTo($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->role === User::ROLE_PLATFORM_ADMIN;
    }

    private function organizationAdminBelongsTo(User $user, Organization $organization): bool
    {
        return $user->role === User::ROLE_ORG_ADMIN
            && $user->organization_id !== null
            && (int) $user->organization_id === (int) $organization->getKey();
    }
}
