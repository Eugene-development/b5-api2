<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bonus;
use Illuminate\Support\Facades\DB;

/** Serializes the two payment paths and source edits in a single lock order. */
final class FinancialLedger
{
    private static int $depth = 0;

    public static function transaction(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            if (self::$depth === 0) {
                if (! DB::table('financial_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new \RuntimeException('Не применена миграция финансового учёта.');
                }
            }
            self::$depth++;
            try {
                return $callback();
            } finally {
                self::$depth--;
            }
        });
    }

    public static function cents(float|string|int $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function reserved(Bonus $bonus): bool
    {
        return $bonus->paymentRequestLinks()->exists()
            || $bonus->payments()->whereHas('status', fn ($q) => $q->whereIn('code', ['pending', 'completed']))->exists();
    }

    public static function assertUncommitted($bonuses): void
    {
        foreach ($bonuses->get() as $bonus) {
            if ($bonus->paid_at !== null || self::reserved($bonus) || $bonus->payments()->exists()) {
                throw new \App\Exceptions\FinancialException('Есть зарезервированные или выплаченные бонусы. Сначала отмените связанную выплату.');
            }
        }
    }
}
