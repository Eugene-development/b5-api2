<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFromCookie
{
    /**
     * Handle an incoming request.
     *
     * Extract JWT token from httpOnly cookie and add to Authorization header
     * for GraphQL and API requests that need authentication.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if Authorization header already exists
        $authHeader = $request->header('Authorization');
        $cookieToken = $request->cookie('b5_auth_token');

        // If no Authorization header, try to get token from cookie
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            if ($cookieToken) {
                // Set the token in the request for JWT authentication
                $request->headers->set('Authorization', 'Bearer '.$cookieToken);
            }
        }

        return $next($request);
    }
}
