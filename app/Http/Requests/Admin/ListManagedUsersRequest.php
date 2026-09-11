<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ListManagedUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            return false;
        }

        return Gate::forUser($actor)->allows('viewAny', User::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['bail', 'nullable', 'string', 'max:255'],
            'page' => ['bail', 'sometimes', 'required', 'integer', 'min:1'],
            'organization_id' => ['missing'],
            'role' => ['missing'],
            'is_active' => ['missing'],
            'per_page' => ['missing'],
            'sort' => ['missing'],
            'direction' => ['missing'],
        ];
    }
}
