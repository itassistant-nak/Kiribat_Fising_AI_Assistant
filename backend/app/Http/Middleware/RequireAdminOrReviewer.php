<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminOrReviewer
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, ['admin', 'reviewer'])) {
            return response()->json([
                'error' => 'Unauthorized. Administrator or reviewer role required.',
                'code' => 'ROLE_FORBIDDEN'
            ], 403);
        }

        return $next($request);
    }
}
