<?php

namespace App\Http\Middleware;

use App\Contract\Setting\SettingContract;
use App\Http\Controllers\Customer\CustomerAuthController;
use App\Service\Cart\CartService;
use App\Service\Shipping\ShippingDestination;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly CartService $cart,
        private readonly SettingContract $settings,
        private readonly ShippingDestination $destination,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Pinned to the staff guard: `auth:customer` makes `customer`
            // the default guard on account pages, and an unpinned user()
            // would then hand a Customer to the permission lookup below.
            'auth' => [
                'user' => $staff = $request->user('web'),
                'permissions' => $staff
                    ? $staff->getPermissionsViaRoles()->pluck('name')->toArray()
                    : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Null until the visitor tells us where to ship; nothing is guessed.
            'shippingDestination' => $this->destination->get(),
            // Deliberately minimal: this is sent with every page.
            'customer' => ($customer = $request->user('customer')) ? [
                'name' => $customer->name,
                'email' => $customer->email,
                'avatar_url' => $customer->avatar_url,
            ] : null,
            'googleLoginEnabled' => CustomerAuthController::googleEnabled(),
            'flash' => [
                'status' => $request->session()->get('status'),
            ],
            'cart' => [
                'count' => $this->cart->count(),
            ],
            'settings' => $this->settings->allAsKeyValue(),
        ];
    }
}
