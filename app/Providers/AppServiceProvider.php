<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.api_key'),
        ));

        $this->app->bind(PricingCalculator::class, fn () => new PricingCalculator(
            markupBps: (int) config('services.pixelforge.markup_bps'),
        ));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::define('admin', fn (User $user) => $user->is_admin);
    }
}
