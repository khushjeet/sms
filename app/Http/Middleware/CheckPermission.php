<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        if (!auth()->check()) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (!empty($permissions)) {
            foreach ($permissions as $permission) {
                if ($user->hasPermission($permission)) {
                    return $next($request);
                }
            }

            return response()->json([
                'message' => 'This action is unauthorized. Missing permission: ' . implode(' or ', $permissions),
            ], 403);
        }

        return $next($request);
    }
}
