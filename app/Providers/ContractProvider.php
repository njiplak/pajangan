<?php

namespace App\Providers;

use App\Contract\Auth\UserAuthContract;
use App\Contract\AuthContract;
use App\Contract\Banner\BannerContract;
use App\Contract\BaseContract;
use App\Contract\Order\OrderContract;
use App\Contract\Page\PageContract;
use App\Contract\Product\ProductContract;
use App\Contract\Setting\PermissionContract;
use App\Contract\Setting\RoleContract;
use App\Contract\Setting\SettingContract;
use App\Contract\Setting\UserContract;
use App\Service\Auth\UserAuthService;
use App\Service\AuthService;
use App\Service\Banner\BannerService;
use App\Service\BaseService;
use App\Service\Order\OrderService;
use App\Service\Page\PageService;
use App\Service\Product\ProductService;
use App\Service\Setting\PermissionService;
use App\Service\Setting\RoleService;
use App\Service\Setting\SettingService;
use App\Service\Setting\UserService;
use Illuminate\Support\ServiceProvider;

class ContractProvider extends ServiceProvider
{
    public array $bindings = [
        // Base
        BaseContract::class => BaseService::class,
        AuthContract::class => AuthService::class,
        UserAuthContract::class => UserAuthService::class,

        // Setting
        SettingContract::class => SettingService::class,
        RoleContract::class => RoleService::class,
        PermissionContract::class => PermissionService::class,
        UserContract::class => UserService::class,

        // Storefront
        ProductContract::class => ProductService::class,
        OrderContract::class => OrderService::class,
        PageContract::class => PageService::class,
        BannerContract::class => BannerService::class,
    ];

    public function register(): void
    {
        foreach ($this->bindings as $contract => $service) {
            $this->app->bind($contract, $service);
        }
    }

    public function boot(): void {}
}
