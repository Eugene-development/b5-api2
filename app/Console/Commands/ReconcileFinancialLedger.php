<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BonusPaymentRequest;
use App\Models\Contract;
use App\Models\Order;
use App\Services\BonusAccrualService;
use App\Services\FinancialLedger;
use Illuminate\Console\Command;

/** Repairs only deterministically reconstructible reservations; never guesses paid amounts. */
final class ReconcileFinancialLedger extends Command
{
    protected $signature = 'bonuses:reconcile-ledger {--apply : Apply safe corrections in one transaction} {--repair-types : Infer request type from uniformly typed owned bonus links}';

    protected $description = 'Check financial consistency and normalize existing partial reservations';

    public function handle(): int
    {
        try {
            return FinancialLedger::transaction(function () {
                $problems = 0;
                $partial = 0;
                $typeFixes = [];
                foreach (BonusPaymentRequest::with(['status', 'linkedBonuses.bonus'])->get() as $request) {
                    if ($request->status->code === 'cancelled') {
                        continue;
                    }
                    $links = $request->linkedBonuses;
                    if ($links->isEmpty() || $links->sum(fn ($l) => FinancialLedger::cents($l->covered_amount)) !== FinancialLedger::cents($request->amount)) {
                        $problems++;

                        continue;
                    }
                    $requestType = $request->requester_type;
                    $recipients = $links->pluck('bonus.recipient_type')->unique()->values();
                    if ($this->option('repair-types') && $recipients->isNotEmpty()) {
                        $inferred = $recipients->every(fn ($type) => $type === 'curator') ? 'curator'
                            : ($recipients->every(fn ($type) => in_array($type, ['agent', 'referrer'], true)) ? 'agent' : null);
                        if ($inferred && $inferred !== $requestType) {
                            $requestType = $inferred;
                            $typeFixes[$request->id] = $inferred;
                        }
                    }
                    foreach ($links as $link) {
                        $b = $link->bonus;
                        $covered = FinancialLedger::cents($link->covered_amount);
                        $types = $requestType === 'curator' ? ['curator'] : ['agent', 'referrer'];
                        if (! $b || (int) $b->user_id !== (int) $request->agent_id || ! in_array($b->recipient_type, $types, true)
                            || $covered <= 0 || $covered > FinancialLedger::cents($b->commission_amount)
                            || ($request->status->code === 'paid') !== ($b->paid_at !== null)
                            || $b->paymentRequestLinks()->count() !== 1
                            || $b->payments()->whereHas('status', fn ($q) => $q->whereIn('code', ['pending', 'completed']))->exists()) {
                            $problems++;

                            continue;
                        }
                        if ($covered < FinancialLedger::cents($b->commission_amount)) {
                            if ($b->paid_at !== null) {
                                $problems++;
                            } else {
                                $partial++;
                            }
                        }
                    }
                }
                if ($problems) {
                    $this->error("Conflicting financial records: {$problems}. No changes; manual reconciliation required.");

                    return self::FAILURE;
                }
                $typeCount = count($typeFixes);
                if (! $this->option('apply')) {
                    $this->info("No conflicting request coverage. Partial reservations to normalize: {$partial}; request types to correct: {$typeCount}.");

                    return self::SUCCESS;
                }
                foreach ($typeFixes as $id => $type) {
                    BonusPaymentRequest::whereKey($id)->update(['requester_type' => $type]);
                }
                foreach (BonusPaymentRequest::with(['status', 'linkedBonuses.bonus'])->get() as $request) {
                    if ($request->status->code === 'cancelled') {
                        $request->linkedBonuses()->delete();

                        continue;
                    }
                    foreach ($request->linkedBonuses as $link) {
                        $b = $link->bonus;
                        $remaining = FinancialLedger::cents($b->commission_amount) - FinancialLedger::cents($link->covered_amount);
                        if ($remaining > 0 && $b->paid_at === null) {
                            $remainder = $b->replicate();
                            $remainder->commission_amount = $remaining / 100;
                            $remainder->save();
                            $b->update(['commission_amount' => $link->covered_amount]);
                        }
                    }
                }
                foreach (Contract::cursor() as $source) {
                    app(BonusAccrualService::class)->sync($source);
                }
                foreach (Order::cursor() as $source) {
                    app(BonusAccrualService::class)->sync($source);
                }
                $this->info('Financial reconciliation committed; paid amounts were preserved.');

                return self::SUCCESS;
            });
        } catch (\Throwable $e) {
            // No row IDs, identities, payment details or amounts are emitted.
            $this->error('Financial reconciliation rolled back: '.class_basename($e));

            return self::FAILURE;
        }
    }
}
