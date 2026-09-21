<?php

namespace App\Models\Concerns;

use App\Services\FinancialLedger;

trait HasFinancialTransactions
{
    public function save(array $options = [])
    {
        return FinancialLedger::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return FinancialLedger::transaction(fn () => parent::delete());
    }
}
