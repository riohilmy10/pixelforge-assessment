<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Every credit movement in the app goes through this class.
 *
 * The invariant is simple: a user can never start a generation whose worst-case quote
 * exceeds `credit_balance_nano - reserved_nano`. Reserving, settling and releasing all
 * take a row lock on the user, so two simultaneous submits cannot both spend the same
 * credits, and a webhook racing a poller still writes one charge.
 *
 * A generation's `reserved_nano` is the source of truth for whether money is still held.
 * Settlement and release set it to zero, which makes both operations safe to run twice.
 * Rows created before balances existed have `reserved_nano = 0` and are left alone.
 */
class CreditService
{
    /** Preset top-up amounts in nano: $5, $10 and $25. */
    public const PURCHASE_PRESETS_NANO = [
        5_000_000_000,
        10_000_000_000,
        25_000_000_000,
    ];

    /**
     * Add credits to an account. This is a simulated purchase: no payment provider is
     * involved, but the ledger entry looks exactly like a real top-up.
     */
    public function purchase(User $user, int $amountNano): CreditTransaction
    {
        if ($amountNano <= 0) {
            throw new InvalidArgumentException('A purchase amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amountNano): CreditTransaction {
            $locked = $this->lockUser($user->id);

            $locked->credit_balance_nano += $amountNano;
            $locked->save();

            return $this->record(
                user: $locked,
                generation: null,
                type: CreditTransaction::TYPE_PURCHASE,
                amountNano: $amountNano,
                description: 'Simulated top-up — no payment taken',
            );
        });
    }

    /**
     * Hold the worst-case quote for a generation that is about to be queued.
     *
     * Called inside the same transaction that creates the generation row, so a refusal
     * (or any later failure) rolls the row back too. The user row lock serialises
     * concurrent submits and is what stops two requests from overdrawing one balance.
     */
    public function reserve(User $user, Generation $generation, int $amountNano): void
    {
        if ($amountNano <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $generation, $amountNano): void {
            $locked = $this->lockUser($user->id);
            $available = $locked->availableNano();

            if ($available < $amountNano) {
                throw new InsufficientCreditsException($amountNano, $available);
            }

            $locked->reserved_nano += $amountNano;
            $locked->save();

            $generation->reserved_nano = $amountNano;
            $generation->save();

            $this->record(
                user: $locked,
                generation: $generation,
                type: CreditTransaction::TYPE_RESERVE,
                amountNano: -$amountNano,
                description: 'Worst-case quote held',
            );
        });
    }

    /**
     * Release the hold and charge the actual settled amount.
     *
     * The debit is capped at the held quote, so a rare rate change cannot make the
     * balance negative or bill the user above the "pay at most" figure. The actual debit
     * is stored in `settled_charge_nano`; any excess above the hold is logged as an
     * absorbed overage. Legacy generations with no hold are recorded but not debited.
     */
    public function settle(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $locked = Generation::query()
                ->whereKey($generation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            $charged = max(0, (int) $locked->charged_nano);

            if ($locked->reserved_nano <= 0) {
                // Rows created before balances existed have no hold. Keep their nominal
                // charge visible, but do not retroactively debit a balance. Guard the
                // write so a repeated call cannot overwrite an already settled amount.
                if ($locked->settled_charge_nano === null) {
                    $locked->settled_charge_nano = $charged;
                    $locked->save();
                }

                return;
            }

            $held = $locked->reserved_nano;
            $settled = min($charged, $held);
            $user = $this->lockUser($locked->user_id);

            $this->releaseHold($user, $locked, $held, 'Worst-case quote returned');

            if ($settled > 0) {
                $user->credit_balance_nano -= $settled;
                $user->save();

                if ($user->credit_balance_nano < 0) {
                    // The reservation invariant should prevent this; keep a signal if
                    // inconsistent data ever gets here.
                    Log::warning('pixelforge.balance_negative_after_settlement', [
                        'generation_id' => $locked->id,
                        'user_id' => $user->id,
                        'settled_nano' => $settled,
                        'credit_balance_nano' => $user->credit_balance_nano,
                    ]);
                }

                $this->record(
                    user: $user,
                    generation: $locked,
                    type: CreditTransaction::TYPE_CHARGE,
                    amountNano: -$settled,
                    description: 'Provider cost plus markup',
                );
            }

            if ($charged > $settled) {
                Log::warning('pixelforge.charge_overage_absorbed', [
                    'generation_id' => $locked->id,
                    'user_id' => $user->id,
                    'charged_nano' => $charged,
                    'held_nano' => $held,
                    'settled_nano' => $settled,
                ]);
            }

            $locked->settled_charge_nano = $settled;
            $locked->reserved_nano = 0;
            $locked->save();
        });

        $generation->reserved_nano = 0;
    }

    /**
     * Return a hold without charging. Used for provider failures and local rejections.
     */
    public function release(Generation $generation, string $description = 'Hold released'): void
    {
        DB::transaction(function () use ($generation, $description): void {
            $locked = Generation::query()
                ->whereKey($generation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->reserved_nano <= 0) {
                return;
            }

            $held = $locked->reserved_nano;
            $user = $this->lockUser($locked->user_id);

            $this->releaseHold($user, $locked, $held, $description);

            $locked->reserved_nano = 0;
            $locked->save();
        });

        $generation->reserved_nano = 0;
    }

    /**
     * The numbers the dashboard and credits page show. "Available" is what the user can
     * actually spend right now; "reserved" is committed to generations already running.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        return [
            'credit_balance_nano' => $user->credit_balance_nano,
            'reserved_nano' => $user->reserved_nano,
            'available_nano' => $user->availableNano(),
            'credit_balance_display' => Money::display($user->credit_balance_nano),
            'reserved_display' => Money::display($user->reserved_nano),
            'available_display' => Money::display($user->availableNano()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentTransactions(User $user, int $limit = 25): array
    {
        return $user->creditTransactions()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (CreditTransaction $transaction): array => $transaction->toUiArray())
            ->all();
    }

    /**
     * Lock the owning row. Every balance mutation begins here, which is why the
     * affordability check and the update it guards see the same numbers.
     */
    private function lockUser(int $userId): User
    {
        return User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Decrease the user's hold and write the matching ledger line. The running balance
     * stored on the entry is the spendable amount after the release.
     */
    private function releaseHold(User $user, Generation $generation, int $held, string $description): void
    {
        $user->reserved_nano = max(0, $user->reserved_nano - $held);
        $user->save();

        $this->record(
            user: $user,
            generation: $generation,
            type: CreditTransaction::TYPE_RELEASE,
            amountNano: $held,
            description: $description,
        );
    }

    /**
     * Append one ledger entry. `balance_after_nano` is the spendable amount, not the
     * owned balance, so a statement reads like a bank account the user can act on.
     */
    private function record(
        User $user,
        ?Generation $generation,
        string $type,
        int $amountNano,
        string $description,
    ): CreditTransaction {
        return CreditTransaction::query()->create([
            'user_id' => $user->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_nano' => $amountNano,
            'balance_after_nano' => $user->availableNano(),
            'reserved_after_nano' => $user->reserved_nano,
            'description' => $description,
        ]);
    }
}
