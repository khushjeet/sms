<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetLinkController extends Controller
{
    private const GENERIC_STATUS = 'If your account is eligible, you will receive a password reset link shortly.';
    private const RESETTABLE_ROLES = ['super_admin', 'school_admin', 'accountant', 'teacher', 'staff', 'hr', 'principal'];

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()
            ->where('email', $request->string('email')->toString())
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereIn('role', self::RESETTABLE_ROLES)
            ->first();

        if (!$user) {
            return response()->json(['status' => self::GENERIC_STATUS]);
        }

        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);

        if ($token === '') {
            throw ValidationException::withMessages([
                'email' => ['Unable to create a password reset token.'],
            ]);
        }

        return response()->json(['status' => self::GENERIC_STATUS]);
    }
}
