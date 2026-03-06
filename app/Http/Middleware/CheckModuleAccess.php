<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleAccess
{
    /**
     * Handle an incoming request.
     * Check if user has access to a specific module
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (!auth()->guard()->check()) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        $user = Auth::user();

        if (!$user->canAccessModule($module)) {
            return response()->json([
                'message' => 'You do not have access to this module: ' . $module
            ], 403);
        }

        return $next($request);
    }
}
