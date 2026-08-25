<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class UserPolicy
{
    public function before(User $actor, string $ability): ?bool
    {
        if (! $actor->canAccessApplication()) {
            return false;
        }

        return null;
    }

    public function viewAny(User $actor): bool
    {
        return $this->isPlatformAdmin($actor);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->isPlatformAdmin($actor)
            || $this->belongsToSameOrganization($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $this->isPlatformAdmin($actor);
    }

    public function createForOrganization(
        User $actor,
        Organization $organization,
        string $role
    ): bool {
        if (! $this->organizationAcceptsUsers($organization)) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return $this->isKnownOrganizationRole($role);
        }

        return $actor->role === User::ROLE_ORG_ADMIN
            && $role === User::ROLE_INVENTORY_AGENT
            && $this->actorBelongsToOrganization($actor, $organization);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->canManageOrganizationUser($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->canManageOrganizationUser($actor, $target);
    }

    public function changeRole(User $actor, User $target, string $newRole): bool
    {
        return $this->isPlatformAdmin($actor)
            && $this->isOrganizationUser($target)
            && $this->isKnownOrganizationRole($newRole);
    }

    public function changeStatus(User $actor, User $target): bool
    {
        return $this->canManageOrganizationUser($actor, $target);
    }

    public function changeOrganization(
        User $actor,
        User $target,
        Organization $newOrganization
    ): bool {
        return $this->isPlatformAdmin($actor)
            && $this->isOrganizationUser($target)
            && $this->organizationAcceptsUsers($newOrganization);
    }

    private function canManageOrganizationUser(User $actor, User $target): bool
    {
        if ($this->isPlatformAdmin($actor)) {
            return $this->isOrganizationUser($target);
        }

        return $this->isManageableInventoryAgent($actor, $target);
    }

    private function isManageableInventoryAgent(User $actor, User $target): bool
    {
        return $target->role === User::ROLE_INVENTORY_AGENT
            && $this->belongsToSameOrganization($actor, $target);
    }

    private function belongsToSameOrganization(User $actor, User $target): bool
    {
        return $actor->role === User::ROLE_ORG_ADMIN
            && $actor->organization_id !== null
            && $target->organization_id !== null
            && $this->isKnownOrganizationRole($target->role)
            && (int) $actor->organization_id === (int) $target->organization_id;
    }

    private function actorBelongsToOrganization(User $actor, Organization $organization): bool
    {
        return $actor->organization_id !== null
            && (int) $actor->organization_id === (int) $organization->getKey();
    }

    private function isPlatformAdmin(User $user): bool
    {
        return $user->role === User::ROLE_PLATFORM_ADMIN;
    }

    private function isOrganizationUser(User $user): bool
    {
        return $user->organization_id !== null
            && $this->isKnownOrganizationRole($user->role);
    }

    private function isKnownOrganizationRole(string $role): bool
    {
        return in_array($role, [
            User::ROLE_ORG_ADMIN,
            User::ROLE_INVENTORY_AGENT,
        ], true);
    }

    private function organizationAcceptsUsers(Organization $organization): bool
    {
        return in_array($organization->status, [
            Organization::STATUS_TRIAL,
            Organization::STATUS_ACTIVE,
        ], true);
    }
}
