<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Services\BonusService;
use Illuminate\Support\Facades\Auth;

final readonly class AgentBonusStatsQuery
{
    /**
     * Get agent bonus statistics for the authenticated user.
     *
     * @param  null  $_
     * @param  array  $args
     * @return array
     */
    public function __invoke(null $_, array $args): array
    {
        // Debug: log the incoming request
        $authHeader = request()->header('Authorization');
        \Illuminate\Support\Facades\Log::info('AgentBonusStatsQuery: Request details', [
            'has_auth_header' => !empty($authHeader),
            'auth_header_length' => $authHeader ? strlen($authHeader) : 0,
            'jwt_secret_set' => !empty(config('jwt.secret')),
            'jwt_secret_length' => config('jwt.secret') ? strlen(config('jwt.secret')) : 0,
        ]);

        $user = Auth::user();
        if (!$user) {
            // Try to manually decode JWT to see what's wrong
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
                try {
                    $payload = \Tymon\JWTAuth\Facades\JWTAuth::setToken($token)->getPayload();
                    \Illuminate\Support\Facades\Log::warning('AgentBonusStatsQuery: JWT payload decoded but Auth::user() is null', [
                        'payload_sub' => $payload->get('sub'),
                        'payload_email' => $payload->get('email'),
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('AgentBonusStatsQuery: JWT decode failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'total_pending' => 0,
                'total_available' => 0,
                'total_paid' => 0,
            ];
        }

        $filters = $args['filters'] ?? null;
        $bonusService = app(BonusService::class);

        return $bonusService->getAgentStats($user->id, $filters);
    }
}
