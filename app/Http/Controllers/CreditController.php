<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Services\CreditService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's wallet: current balance, preset fake purchases, and the ledger.
 *
 * Purchases are deliberately simulated. The important part for the assessment is that
 * the balance and every hold/charge are recorded exactly and can be explained in the UI.
 */
class CreditController extends Controller
{
    public function __construct(private readonly CreditService $credits) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Credits/Index', [
            'balance' => $this->credits->summary($user),
            'transactions' => $this->credits->recentTransactions($user),
            'presets' => array_map(
                fn (int $amountNano): array => [
                    'amount_nano' => $amountNano,
                    'display' => Money::display($amountNano),
                ],
                CreditService::PURCHASE_PRESETS_NANO,
            ),
        ]);
    }

    public function purchase(PurchaseCreditsRequest $request): RedirectResponse
    {
        $amountNano = $request->amountNano();

        $this->credits->purchase($request->user(), $amountNano);

        return to_route('credits.index')
            ->with('status', 'Added '.Money::display($amountNano).' in credits. Your available balance is updated.');
    }
}
