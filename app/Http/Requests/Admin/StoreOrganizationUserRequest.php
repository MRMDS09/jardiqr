<?php

namespace App\Http\Requests\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreOrganizationUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $organization = $this->route('organization');
        $role = $this->input('role');

        if (! $actor instanceof User || ! $organization instanceof Organization) {
            return false;
        }

        $roleForAuthorization = is_string($role)
            ? $role
            : User::ROLE_INVENTORY_AGENT;

        return Gate::forUser($actor)->allows(
            'createForOrganization',
            [User::class, $organization, $roleForAuthorization]
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'role' => [
                'required',
                'string',
                Rule::in([
                    User::ROLE_ORG_ADMIN,
                    User::ROLE_INVENTORY_AGENT,
                ]),
            ],
        ];
    }
}
