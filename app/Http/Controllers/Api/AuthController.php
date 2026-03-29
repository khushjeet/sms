<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
class AuthController extends Controller
{
    /**
     * Handle user login
     * SRS Section 12.4: All sensitive actions logged
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Log failed login attempt
            AuditLog::create([
                'user_id' => null,
                'action' => 'login_failed',
                'model_type' => User::class,
                'model_id' => $user?->id,
                'old_values' => null,
                'new_values' => ['email' => $request->email],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'reason' => 'Invalid credentials',
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user is active
        if ($user->status !== 'active') {
            // Log blocked login attempt
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'login_blocked',
                'model_type' => User::class,
                'model_id' => $user->id,
                'old_values' => null,
                'new_values' => ['status' => $user->status],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'reason' => 'Account is not active',
            ]);

            return response()->json([
                'message' => 'Your account is not active. Please contact administrator.'
            ], 403);
        }

        // Revoke all existing tokens (optional - for single device login)
        // Uncomment for single device login:
        // $user->tokens()->delete();

        // Create new token with expiration (SRS: Security)
        $token = $user->createToken('auth-token', ['*'], now()->addDays(30))->plainTextToken;

        // Log successful login (SRS Section 12.4: Audit logs)
        $roleNames = $user->getRoleNames();
        $primaryRole = $user->getPrimaryRole();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'model_type' => User::class,
            'model_id' => $user->id,
            'old_values' => null,
            'new_values' => [
                'email' => $user->email,
                'role' => $primaryRole,
                'roles' => $roleNames,
                'login_at' => now()->toDateTimeString(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reason' => null,
        ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => now()->addDays(30)->toDateTimeString(),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $primaryRole,
                'roles' => $roleNames,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'avatar' => $user->avatar,
                'avatar_url' => $user->avatar_url,
                'full_name' => $user->full_name,
                'status' => $user->status,
            ]
        ]);
    }

    /**
     * Handle user logout
     * SRS Section 12.4: All sensitive actions logged
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Log logout action (SRS Section 12.4: Audit logs)
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'logout',
            'model_type' => User::class,
            'model_id' => $user->id,
            'old_values' => null,
            'new_values' => [
                'logout_at' => now()->toDateTimeString(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reason' => null,
        ]);

        // Revoke the current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated user
     */
    public function user(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load(['student', 'parent', 'staff'])
        ]);
    }

    /**
     * Revoke all tokens for the authenticated user
     * SRS: Security - Token management
     */
    public function revokeAllTokens(Request $request)
    {
        $user = $request->user();

        // Log token revocation
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'revoke_all_tokens',
            'model_type' => User::class,
            'model_id' => $user->id,
            'old_values' => null,
            'new_values' => [
                'revoked_at' => now()->toDateTimeString(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reason' => 'User requested token revocation',
        ]);

        $user->tokens()->delete();

        return response()->json([
            'message' => 'All tokens revoked successfully'
        ]);
    }
}
