<?php

namespace App\Http\Controllers;

use App\Services\Billing\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController extends Controller
{
    /** Fake purchase packs, in whole dollars. */
    public const PACKS = [1, 5, 20];

    public function index(Request $request): Response
    {
        return Inertia::render('Credits', [
            'packs' => self::PACKS,
            'transactions' => $request->user()->creditTransactions()->latest('id')->limit(50)->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => Money::format($t->amount_nanos),
                    'positive' => $t->amount_nanos >= 0,
                    'description' => $t->description,
                    'generation_id' => $t->generation_id,
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $data = $request->validate(['dollars' => ['required', 'integer', 'in:'.implode(',', self::PACKS)]]);

        $ledger->purchase($request->user(), $data['dollars'] * Money::NANOS_PER_DOLLAR);

        return back()->with('success', 'Added '.Money::format($data['dollars'] * Money::NANOS_PER_DOLLAR).' of credits.');
    }
}
