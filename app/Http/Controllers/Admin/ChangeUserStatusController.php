<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeUserStatusRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChangeUserStatusController extends Controller
{
    public function __invoke(
        ChangeUserStatusRequest $request,
        User $user
    ): JsonResponse {
        $data = $request->validated();

        $isActive = (bool) $data['is_active'];

        if ($user->is_active === $isActive) {
            return response()->json($user);
        }

        $user->is_active = $isActive;
        $user->save();

        return response()->json($user);
    }
}
