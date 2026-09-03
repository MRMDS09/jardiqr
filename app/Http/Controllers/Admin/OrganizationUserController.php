<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationUserRequest;
use App\Http\Requests\Admin\UpdateManagedUserRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OrganizationUserController extends Controller
{
    public function store(
        StoreOrganizationUserRequest $request,
        Organization $organization
    ): JsonResponse {
        $data = $request->validated();

        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->password = $data['password'];
        $user->role = $data['role'];
        $user->organization()->associate($organization);
        $user->save();

        return response()->json($user, 201);
    }

    public function update(
        UpdateManagedUserRequest $request,
        User $user
    ): JsonResponse {
        $data = $request->validated();

        $user->name = $data['name'];

        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }

        $user->save();

        return response()->json($user);
    }
}
