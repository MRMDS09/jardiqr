<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateManagedUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        if (! $actor instanceof User || ! $target instanceof User) {
            return false;
        }

        return Gate::forUser($actor)->allows('update', $target);
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
            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],
            'email' => ['missing'],
            'role' => ['missing'],
            'organization_id' => ['missing'],
            'is_active' => ['missing'],
            'password' => ['missing'],
            'password_confirmation' => ['missing'],
            'email_verified_at' => ['missing'],
            'last_login_at' => ['missing'],
            'remember_token' => ['missing'],
            'id' => ['missing'],
        ];
    }
}
