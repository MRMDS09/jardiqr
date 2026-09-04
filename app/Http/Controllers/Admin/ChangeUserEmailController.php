<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeUserEmailRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ChangeUserEmailController extends Controller
{
    public function __invoke(
        ChangeUserEmailRequest $request,
        User $user
    ): JsonResponse {
        $data = $request->validated();

        if ($user->email === $data['email']) {
            return response()->json($user);
        }

        $user->email = $data['email'];
        $user->email_verified_at = null;
        $user->save();

        return response()->json($user);
    }
}
