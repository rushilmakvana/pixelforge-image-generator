<?php

namespace App\Services\Billing;

use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Spend, provider cost and profit per UTC day and per user.
 *
 * Grouped by the UTC day a generation *completed*, because that's how
 * Pixelforge buckets GET /v1/usage. Only succeeded generations cost anything.
 */
class AdminReport
{
    public function __construct(private PixelforgeClient $client) {}

    public function build(Carbon $start, Carbon $end): array
    {
        $generations = Generation::query()
            ->with('user:id,name,email')
            ->where('status', Generation::SUCCEEDED)
            ->whereBetween('completed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->get(['id', 'user_id', 'completed_at', 'provider_cost_nanos', 'charged_nanos']);

        $usage = $this->providerUsage($start, $end);

        $days = collect();
        for ($day = $end->copy(); $day->gte($start); $day->subDay()) {
            $date = $day->toDateString();
            $ours = $generations->filter(fn ($g) => $g->completed_at->utc()->toDateString() === $date);
            $reported = $usage['days'][$date] ?? null;
            $row = $this->totals($ours);

            $days->push([
                'date' => $date,
                ...$this->present($row),
                'pixelforge_cost' => $reported !== null ? Money::format($reported['cost']) : null,
                'pixelforge_generations' => $reported['generations'] ?? null,
                'matches' => $reported === null ? null
                    : $reported['cost'] === $row['provider'] && $reported['generations'] === $row['count'],
                'users' => $ours->groupBy('user_id')
                    ->map(fn (Collection $g) => ['user' => $g->first()->user->name, 'email' => $g->first()->user->email, ...$this->present($this->totals($g))])
                    ->sortByDesc('charged_nanos')->values(),
            ]);
        }

        $users = $generations->groupBy('user_id')
            ->map(fn (Collection $g) => ['user' => $g->first()->user->name, 'email' => $g->first()->user->email, ...$this->present($this->totals($g))])
            ->sortByDesc('charged_nanos')->values();

        return [
            'days' => $days->filter(fn ($d) => $d['count'] > 0 || ($d['pixelforge_generations'] ?? 0) > 0)->values(),
            'users' => $users,
            'total' => [
                ...$this->present($this->totals($generations)),
                'pixelforge_cost' => isset($usage['total']) ? Money::format($usage['total']) : null,
                'matches' => isset($usage['total']) ? $usage['total'] === (int) $generations->sum('provider_cost_nanos') : null,
            ],
            'usage_error' => $usage['error'] ?? null,
        ];
    }

    private function totals(Collection $generations): array
    {
        $charged = (int) $generations->sum('charged_nanos');
        $provider = (int) $generations->sum('provider_cost_nanos');

        return ['count' => $generations->count(), 'charged' => $charged, 'provider' => $provider, 'profit' => $charged - $provider];
    }

    private function present(array $t): array
    {
        return [
            'count' => $t['count'],
            'charged' => Money::format($t['charged']),
            'charged_nanos' => $t['charged'],
            'provider_cost' => Money::format($t['provider']),
            'provider_cost_exact' => Money::toDecimal($t['provider']),
            'profit' => Money::format($t['profit']),
        ];
    }

    /** Pixelforge's own numbers for this API key, to reconcile against. */
    private function providerUsage(Carbon $start, Carbon $end): array
    {
        try {
            $response = $this->client->usage($start->toDateString(), $end->toDateString());
        } catch (Throwable $e) {
            return ['error' => 'Could not load Pixelforge usage: '.$e->getMessage()];
        }

        return [
            'days' => collect($response['data'] ?? [])->mapWithKeys(fn ($d) => [
                $d['date'] => ['cost' => Money::parse($d['cost_usd']), 'generations' => (int) $d['generations']],
            ])->all(),
            'total' => Money::parse($response['total']['cost_usd'] ?? '0'),
        ];
    }
}
