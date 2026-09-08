<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeUserRoleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChangeUserRoleController extends Controller
{
    public function __invoke(
        ChangeUserRoleRequest $request,
        User $user
    ): JsonResponse {
        $data = $request->validated();

        $newRole = $data['role'];

        if ($user->role === $newRole) {
            return response()->json($user);
        }

        $user->role = $newRole;
        $user->save();

        return response()->json($user);
    }
}
