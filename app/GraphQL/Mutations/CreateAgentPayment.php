<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\AgentPayment;
use App\Services\PaymentService;
use GraphQL\Error\Error;

final readonly class CreateAgentPayment
{
    /**
     * Create a new agent payment.
     *
     * @param  null  $_
     * @param  array  $args
     * @return AgentPayment
     */
    public function __invoke(null $_, array $args): AgentPayment
    {
        $input = $args['input'];

        try {
            $paymentService = app(PaymentService::class);

            return $paymentService->createPayment(
                (int) $input['agent_id'],
                array_map('intval', $input['bonus_ids']),
                (int) $input['method_id'],
                $input['reference_number'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            throw new Error($e->getMessage());
        }
    }
}
