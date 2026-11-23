<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Services\Billing\InsufficientCredits;
use App\Services\GenerationService;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\PricingCatalog;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Generate', [
            'sizes' => StoreGenerationRequest::SIZES,
            'qualities' => StoreGenerationRequest::QUALITIES,
            'generations' => fn () => $request->user()->generations()->latest('id')->limit(6)->get()
                ->map->toFrontend(),
        ]);
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()->latest('id')->paginate(12);

        return Inertia::render('History', [
            'generations' => fn () => [
                'data' => $generations->getCollection()->map->toFrontend(),
                'links' => [
                    'prev' => $generations->previousPageUrl(),
                    'next' => $generations->nextPageUrl(),
                ],
                'page' => $generations->currentPage(),
                'last_page' => $generations->lastPage(),
                'total' => $generations->total(),
            ],
            'totals' => fn () => [
                'charged' => Money::format((int) $request->user()->generations()->sum('charged_nanos')),
                'succeeded' => $request->user()->generations()->where('status', Generation::SUCCEEDED)->count(),
                'failed' => $request->user()->generations()->where('status', Generation::FAILED)->count(),
            ],
        ]);
    }

    /** Live estimate for the form. Uses the same calculator that bills. */
    public function estimate(Request $request, PricingCalculator $calculator, PricingCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'chars' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', 'in:'.implode(',', StoreGenerationRequest::SIZES)],
            'quality' => ['required', 'in:'.implode(',', StoreGenerationRequest::QUALITIES)],
            'references' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $estimate = $calculator->estimate($catalog->current(), $data['chars'], $data['size'], $data['quality'], $data['references']);
        $balance = (int) $request->user()->balance_nanos;

        return response()->json([
            ...$estimate->toArray(),
            'affordable' => $balance >= $estimate->chargeMax,
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationService $generations): RedirectResponse
    {
        try {
            $generations->create($request->user(), $request->validated() + ['reference_images' => []]);
        } catch (InsufficientCredits $e) {
            return back()->withErrors([
                'credits' => 'This generation can cost up to '.Money::format($e->requiredNanos)
                    .', but your balance is '.Money::format($e->balanceNanos).'. Buy credits to continue.',
            ]);
        }

        return back()->with('success', 'Generation submitted.');
    }
}
