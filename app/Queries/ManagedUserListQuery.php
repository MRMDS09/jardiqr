<?php

namespace App\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class ManagedUserListQuery
{
    public function paginate(
        User $actor,
        ?string $search = null,
        int $page = 1
    ): LengthAwarePaginator {
        Gate::forUser($actor)->authorize('viewAny', User::class);

        $query = User::query()
            ->whereHas('organization')
            ->whereIn('role', [
                User::ROLE_ORG_ADMIN,
                User::ROLE_INVENTORY_AGENT,
            ]);

        if ($actor->role === User::ROLE_ORG_ADMIN) {
            $query->where('organization_id', $actor->organization_id);
        }

        if ($search !== null && $search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        return $query->orderBy('users.id', 'asc')
            ->paginate(15, ['users.*'], 'page', $page);
    }
}
