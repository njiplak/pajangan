<?php

namespace App\Http\Controllers\Setting;

use App\Contract\Setting\SettingContract;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentSettingRequest;
use App\Models\Setting;
use App\Service\Payment\PaymentGatewayManager;
use App\Utils\WebResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use InvalidArgumentException;
use Throwable;

class PaymentSettingController extends Controller
{
    private const KEYS = [
        'payment_active_gateway',
        'payment_default_channel',
        'payment_admin_fee_borne_by',
        'payment_admin_fee_flat',
        'payment_channel_fees',
    ];

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly SettingContract $settings,
    ) {}

    public function index()
    {
        $current = $this->settings->allAsKeyValue();

        return Inertia::render('setting/payment/index', [
            'gateways' => collect($this->gateways->all())->map(fn ($key) => [
                'key' => $key,
                'label' => $this->gateways->resolve($key)->label(),
                'configured' => $this->gateways->resolve($key)->isConfigured(),
            ])->values(),
            'current' => [
                'payment_active_gateway' => $current['payment_active_gateway'] ?? null,
                'payment_default_channel' => $current['payment_default_channel'] ?? null,
                'payment_admin_fee_borne_by' => $current['payment_admin_fee_borne_by'] ?? 'merchant',
                'payment_admin_fee_flat' => $current['payment_admin_fee_flat'] ?? '0',
                'payment_channel_fees' => json_decode($current['payment_channel_fees'] ?? '{}', true) ?: [],
            ],
        ]);
    }

    public function channels(string $gateway)
    {
        try {
            $channels = $this->gateways->resolve($gateway)->listChannels();

            return response()->json(['channels' => $channels, 'error' => null]);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        } catch (Throwable $e) {
            // Listing channels calls the gateway's live API — credentials
            // may be wrong, sandbox may be unreachable, etc. Degrade to
            // an empty list with an explanation rather than a 500, so the
            // admin can still save free-text or fix credentials first.
            Log::warning("Failed to list channels for gateway [{$gateway}]: {$e->getMessage()}");

            return response()->json(['channels' => [], 'error' => $e->getMessage()]);
        }
    }

    public function update(PaymentSettingRequest $request)
    {
        $validated = $request->validated();
        $validated['payment_channel_fees'] = json_encode($validated['payment_channel_fees'] ?? []);

        foreach (self::KEYS as $key) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $validated[$key] ?? null]
            );
        }

        return WebResponse::response($validated, 'backoffice.setting.payment.index');
    }
}
