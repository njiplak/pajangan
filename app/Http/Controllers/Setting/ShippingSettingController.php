<?php

namespace App\Http\Controllers\Setting;

use App\Contract\Setting\SettingContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShippingSettingRequest;
use App\Models\Setting;
use App\Service\Shipping\ShippingProviderManager;
use App\Utils\WebResponse;
use Inertia\Inertia;

class ShippingSettingController extends Controller
{
    private const KEYS = [
        'shipping_preferred_collection_method',
    ];

    public function __construct(
        private readonly ShippingProviderManager $shipping,
        private readonly SettingContract $settings,
    ) {}

    public function index()
    {
        $current = $this->settings->allAsKeyValue();

        return Inertia::render('setting/shipping/index', [
            'providers' => collect($this->shipping->all())->map(fn ($key) => [
                'key' => $key,
                'label' => $this->shipping->resolve($key)->label(),
                'configured' => $this->shipping->resolve($key)->isConfigured(),
            ])->values(),
            'current' => [
                'shipping_preferred_collection_method' => $current['shipping_preferred_collection_method'] ?? null,
            ],
        ]);
    }

    public function update(ShippingSettingRequest $request)
    {
        $validated = $request->validated();

        foreach (self::KEYS as $key) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $validated[$key] ?? null]
            );
        }

        return WebResponse::response($validated, 'backoffice.setting.shipping.index');
    }
}
