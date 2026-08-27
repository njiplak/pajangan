<?php

namespace App\Providers;

use App\Service\Shipping\BiteshipService;
use App\Service\Shipping\ShippingProviderManager;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShippingProviderManager::class, function ($app) {
            $manager = new ShippingProviderManager;

            $manager->register($app->make(BiteshipService::class));

            return $manager;
        });
    }

    public function boot(): void {}
}
