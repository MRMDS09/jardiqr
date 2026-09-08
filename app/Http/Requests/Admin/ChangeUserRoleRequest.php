<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        if (! $actor instanceof User || ! $target instanceof User) {
            return false;
        }

        $requestedRole = $this->input('role');
        $roleForAuthorization = in_array($requestedRole, [
            User::ROLE_ORG_ADMIN,
            User::ROLE_INVENTORY_AGENT,
        ], true)
            ? $requestedRole
            : User::ROLE_INVENTORY_AGENT;

        return Gate::forUser($actor)->allows(
            'changeRole',
            [$target, $roleForAuthorization]
        );
    }

    public function rules(): array
    {
        return [
            'role' => [
                'bail',
                'required',
                'string',
                Rule::in([
                    User::ROLE_ORG_ADMIN,
                    User::ROLE_INVENTORY_AGENT,
                ]),
            ],
            'name' => ['missing'],
            'email' => ['missing'],
            'phone' => ['missing'],
            'organization_id' => ['missing'],
            'is_active' => ['missing'],
            'password' => ['missing'],
            'password_confirmation' => ['missing'],
            'email_verified_at' => ['missing'],
            'last_login_at' => ['missing'],
            'remember_token' => ['missing'],
            'id' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
