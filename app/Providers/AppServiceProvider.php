<?php

namespace App\Providers;

use App\Services\Integrations\CarrierTrackingAdapterRegistry;
use App\Services\Integrations\BankStatementAdapterRegistry;
use App\Services\Integrations\EInvoiceProviderRegistry;
use App\Services\Integrations\HttpCarrierTrackingAdapter;
use App\Services\Integrations\HttpBankStatementAdapter;
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
            $registry->register($app->make(HttpCarrierTrackingAdapter::class));
            foreach ((array) config('integrations.carrier_tracking_adapters', []) as $adapterClass) {
                $registry->register($app->make($adapterClass));
            }
            return $registry;
        });
        $this->app->singleton(BankStatementAdapterRegistry::class, function ($app): BankStatementAdapterRegistry {
            $registry = new BankStatementAdapterRegistry();
            $registry->register($app->make(HttpBankStatementAdapter::class));
            foreach ((array) config('integrations.bank_statement_adapters', []) as $adapterClass) {
                $registry->register($app->make($adapterClass));
            }
            return $registry;
        });
        $this->app->singleton(EInvoiceProviderRegistry::class, function ($app): EInvoiceProviderRegistry {
            $registry = new EInvoiceProviderRegistry();
            foreach ((array) config('integrations.e_invoice_adapters', []) as $providerClass) {
                $registry->register($app->make($providerClass));
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
