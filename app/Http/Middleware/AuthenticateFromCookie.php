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

        \Illuminate\Support\Facades\Log::info('AuthenticateFromCookie middleware', [
            'has_auth_header' => !empty($authHeader),
            'auth_header_preview' => $authHeader ? substr($authHeader, 0, 30) . '...' : null,
            'has_cookie' => !empty($cookieToken),
            'cookie_preview' => $cookieToken ? substr($cookieToken, 0, 30) . '...' : null,
            'path' => $request->path(),
        ]);

        // If no Authorization header, try to get token from cookie
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            if ($cookieToken) {
                // Set the token in the request for JWT authentication
                $request->headers->set('Authorization', 'Bearer ' . $cookieToken);
                \Illuminate\Support\Facades\Log::info('AuthenticateFromCookie: Set Authorization from cookie');
            }
        }

        return $next($request);
    }
}
