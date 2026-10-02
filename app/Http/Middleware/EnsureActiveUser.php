<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if (! $user || $user->ban || ! $user->is_active) {
            return new JsonResponse([
                'message' => 'Учётная запись заблокирована или отключена.',
            ], Response::HTTP_FORBIDDEN);
        }

        $sessionVersion = Auth::guard('api')->getPayload()->get('session_version');
        // Keep existing sessions usable until this account first resets its password.
        $legacySession = $sessionVersion === null && (string) $user->getRememberToken() === '';

        if (! $legacySession && (! is_string($sessionVersion)
            || ! hash_equals($user->getJWTSessionVersion(), $sessionVersion))) {
            return new JsonResponse([
                'message' => 'Сессия завершена. Войдите снова.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
