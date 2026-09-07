<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ChangeUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->targetUser();

        if (! $actor instanceof User || ! $target instanceof User) {
            return false;
        }

        return Gate::forUser($actor)->allows('changeStatus', $target);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => [
                'bail',
                'required',
                'boolean',
            ],
            'name' => ['missing'],
            'email' => ['missing'],
            'phone' => ['missing'],
            'password' => ['missing'],
            'password_confirmation' => ['missing'],
            'role' => ['missing'],
            'organization_id' => ['missing'],
            'email_verified_at' => ['missing'],
            'last_login_at' => ['missing'],
            'remember_token' => ['missing'],
            'id' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }

    private function targetUser(): ?User
    {
        $target = $this->route('user');

        return $target instanceof User ? $target : null;
    }
}
