<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BonusPaymentRequest;
use App\Models\BonusPaymentStatus;
use App\Services\BonusPaymentService;
use App\Services\FinancialLedger;

final readonly class UpdateBonusPaymentRequestStatus
{
    public function __construct(private BonusPaymentService $bonusPaymentService = new BonusPaymentService) {}

    public function __invoke(null $_, array $args): BonusPaymentRequest
    {
        return FinancialLedger::transaction(function () use ($args) {
            $request = BonusPaymentRequest::with('status')->lockForUpdate()->findOrFail($args['request_id']);
            $code = $args['status_code'];
            if (! in_array($code, ['requested', 'approved', 'paid', 'cancelled'], true)) {
                throw new \App\Exceptions\FinancialException('Недопустимый статус выплаты.');
            }
            if ($request->status->code === $code) {
                return $request;
            }
            $status = BonusPaymentStatus::where('code', $code)->firstOrFail();
            if ($request->status->code === 'paid') {
                $this->bonusPaymentService->rollbackSettlement($request);
            }
            if ($request->status->code === 'cancelled' && $code !== 'cancelled') {
                $this->bonusPaymentService->linkBonusesToPaymentRequest($request, (int) $request->agent_id, (float) $request->amount, $request->requester_type);
            }
            if ($code === 'paid') {
                $this->bonusPaymentService->settleBonuses($request);
            }
            if ($code === 'cancelled') {
                $request->linkedBonuses()->delete();
            }
            $request->update(['status_id' => $status->id, 'payment_date' => $code === 'paid' ? now() : null]);

            return $request->fresh(['agent', 'status', 'linkedBonuses.bonus']);
        });
    }
}
