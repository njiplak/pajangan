<?php

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Service\Payment\PaymentGatewayManager;
use App\Service\Payment\PaymentService;

/**
 * Minimal in-memory double so these tests exercise PaymentService's own
 * orchestration logic (fee policy, persistence) without depending on any
 * real gateway's HTTP behavior.
 */
class FakeGateway implements PaymentGatewayContract
{
    public array $lastCall = [];

    public function __construct(private string $gatewayKey, private bool $configured = true) {}

    public function key(): string
    {
        return $this->gatewayKey;
    }

    public function label(): string
    {
        return $this->gatewayKey;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function listChannels(): array
    {
        return [];
    }

    public function createTransaction(Order $order, int $amount, array $options = []): array
    {
        $this->lastCall = compact('order', 'amount', 'options');

        return [
            'reference' => 'FAKE-REF-'.$order->order_number,
            'redirect_url' => 'https://fake.test/pay',
            'token' => null,
            'expires_at' => null,
            'raw' => ['amount' => $amount],
        ];
    }

    public function verifyNotification(string $rawBody, array $headers = []): bool
    {
        return true;
    }

    public function parseNotification(array $payload): array
    {
        return ['reference' => $payload['reference'], 'status' => PaymentStatus::PAID, 'amount' => 0, 'paid_at' => null, 'raw' => $payload];
    }

    public function getStatus(string $reference): array
    {
        return ['reference' => $reference, 'status' => PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
    }
}

function paymentTestOrder(): Order
{
    return Order::create([
        'order_number' => 'ORD-FAKE-001',
        'customer_name' => 'Budi',
        'customer_email' => 'budi@example.com',
        'customer_phone' => '08123456789',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 100000,
        'total' => 100000,
    ]);
}

test('initiate defaults the admin fee to zero and merchant-borne when no Setting is configured', function () {
    $manager = app(PaymentGatewayManager::class);
    $manager->register($fake = new FakeGateway('fake'));

    $service = app(PaymentService::class);
    $order = paymentTestOrder();

    $service->initiate($order, 'fake');

    $order->refresh();
    expect($order->admin_fee)->toBe(0);
    expect($order->fee_borne_by)->toBe('merchant');
    expect($order->total)->toBe(100000);
    expect($fake->lastCall['amount'])->toBe(100000);
});

test('initiate adds the configured flat admin fee to the total when the policy is customer-borne', function () {
    Setting::create(['key' => 'payment_admin_fee_borne_by', 'value' => 'customer']);
    Setting::create(['key' => 'payment_admin_fee_flat', 'value' => '4000']);

    $manager = app(PaymentGatewayManager::class);
    $manager->register($fake = new FakeGateway('fake'));

    $service = app(PaymentService::class);
    $order = paymentTestOrder();

    $service->initiate($order, 'fake', ['method' => 'QRIS']);

    $order->refresh();
    expect($order->admin_fee)->toBe(4000);
    expect($order->fee_borne_by)->toBe('customer');
    expect($order->total)->toBe(104000);
    expect($order->payment_gateway)->toBe('fake');
    expect($order->payment_channel)->toBe('QRIS');
    expect($order->payment_reference)->toBe('FAKE-REF-ORD-FAKE-001');
    expect($order->payment_status)->toBe(PaymentStatus::PENDING);
    expect($fake->lastCall['amount'])->toBe(104000);
});

test('initiate prefers a per-channel fee entry over the flat fallback, computing flat + percent of subtotal', function () {
    Setting::create(['key' => 'payment_admin_fee_borne_by', 'value' => 'customer']);
    Setting::create(['key' => 'payment_admin_fee_flat', 'value' => '999']); // must be ignored
    Setting::create(['key' => 'payment_channel_fees', 'value' => json_encode([
        'fake' => ['QRIS' => ['flat' => 1000, 'percent' => 2.5]],
    ])]);

    $manager = app(PaymentGatewayManager::class);
    $manager->register($fake = new FakeGateway('fake'));

    $order = paymentTestOrder(); // subtotal 100000

    app(PaymentService::class)->initiate($order, 'fake', ['method' => 'QRIS']);

    $order->refresh();
    // 1000 flat + 2.5% of 100000 (2500) = 3500
    expect($order->admin_fee)->toBe(3500);
    expect($order->total)->toBe(103500);
});

test('initiate falls back to the flat fee when the channel has no per-channel entry configured', function () {
    Setting::create(['key' => 'payment_admin_fee_borne_by', 'value' => 'customer']);
    Setting::create(['key' => 'payment_admin_fee_flat', 'value' => '2000']);
    Setting::create(['key' => 'payment_channel_fees', 'value' => json_encode([
        'fake' => ['QRIS' => ['flat' => 1000, 'percent' => 0]],
    ])]);

    $manager = app(PaymentGatewayManager::class);
    $manager->register($fake = new FakeGateway('fake'));

    $order = paymentTestOrder();

    // No 'method' option passed and no payment_default_channel Setting,
    // so there's no channel to key the per-channel schedule on at all.
    app(PaymentService::class)->initiate($order, 'fake');

    $order->refresh();
    expect($order->admin_fee)->toBe(2000);
});

test('initiate refuses to use a gateway that is registered but not configured', function () {
    $manager = app(PaymentGatewayManager::class);
    $manager->register(new FakeGateway('unconfigured', configured: false));

    app(PaymentService::class)->initiate(paymentTestOrder(), 'unconfigured');
})->throws(RuntimeException::class);

test('activeGatewayKey prefers the Setting value when it names a configured gateway, and falls back otherwise', function () {
    $manager = app(PaymentGatewayManager::class);
    $manager->register(new FakeGateway('gateway-a'));
    $manager->register(new FakeGateway('gateway-b'));

    $service = app(PaymentService::class);

    // No Setting yet: falls back to the first configured gateway.
    expect($service->activeGatewayKey())->toBe('gateway-a');

    Setting::create(['key' => 'payment_active_gateway', 'value' => 'gateway-b']);
    expect(app(PaymentService::class)->activeGatewayKey())->toBe('gateway-b');
});
