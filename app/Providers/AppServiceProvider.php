<?php

namespace App\Providers;

use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use App\Services\Integrations\BankStatementAdapterRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(CarrierTrackingAdapterRegistry::class, function ($app): CarrierTrackingAdapterRegistry {
            $registry = new CarrierTrackingAdapterRegistry();
            foreach ((array) config('integrations.carrier_tracking_adapters', []) as $adapterClass) {
                $registry->register($app->make($adapterClass));
            }
            return $registry;
        });
        $this->app->singleton(BankStatementAdapterRegistry::class, function ($app): BankStatementAdapterRegistry {
            $registry = new BankStatementAdapterRegistry();
            foreach ((array) config('integrations.bank_statement_adapters', []) as $adapterClass) {
                $registry->register($app->make($adapterClass));
            }
            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
