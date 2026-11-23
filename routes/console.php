<?php

use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Schedule;

/*
 * Webhooks are the fast path; this is the safety net. Anything still in flight
 * that hasn't changed for a minute gets polled. Capped per run to stay well
 * under Pixelforge's 60 requests/minute.
 */
Schedule::call(function () {
    Generation::query()
        ->inFlight()
        ->where('updated_at', '<', now()->subMinute())
        ->orderBy('updated_at')
        ->limit(20)
        ->pluck('id')
        ->each(fn (int $id) => SyncGeneration::dispatch($id));
})->name('sync-in-flight-generations')->everyMinute()->withoutOverlapping();
