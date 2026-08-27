<?php

namespace App\Providers;

use App\Service\Payment\DokuService;
use App\Service\Payment\DuitkuService;
use App\Service\Payment\MidtransService;
use App\Service\Payment\PaymentGatewayManager;
use App\Service\Payment\TripayService;
use App\Service\Payment\XenditService;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            $manager = new PaymentGatewayManager;

            $manager->register($app->make(TripayService::class));
            $manager->register($app->make(MidtransService::class));
            $manager->register($app->make(XenditService::class));
            $manager->register($app->make(DuitkuService::class));
            $manager->register($app->make(DokuService::class));

            return $manager;
        });
    }

    public function boot(): void {}
}
