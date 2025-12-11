<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\AgentPayment;
use App\Services\PaymentService;
use GraphQL\Error\Error;

final readonly class UpdatePaymentStatus
{
    /**
     * Update payment status.
     *
     * @param  null  $_
     * @param  array  $args
     * @return AgentPayment
     */
    public function __invoke(null $_, array $args): AgentPayment
    {
        $paymentId = (int) $args['payment_id'];
        $statusCode = $args['status_code'];

        $payment = AgentPayment::findOrFail($paymentId);
        $paymentService = app(PaymentService::class);

        if ($statusCode === 'completed') {
            return $paymentService->completePayment($payment);
        } elseif ($statusCode === 'failed') {
            return $paymentService->failPayment($payment);
        } else {
            throw new Error("Неподдерживаемый статус: {$statusCode}");
        }
    }
}
