<?php

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Mail\OrderDeliveredMail;
use App\Models\Order;
use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

function deliveryOrder(array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ORD-DLV-001',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_SHIPPED,
        'subtotal' => 120000,
        'total' => 135000,
        'biteship_order_id' => 'bts-dlv-1',
        'courier_code' => 'jne',
        'courier_service' => 'reg',
    ], $overrides));
}

function fakeShipmentStatus(string $status): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.webhook_token' => 'super-secret-token',
    ]);

    Http::fake([
        'api.biteship.com/v1/orders/bts-dlv-1' => Http::response([
            'success' => true,
            'id' => 'bts-dlv-1',
            'status' => $status,
            'price' => 15000,
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => 'JNE777'],
        ], 200),
    ]);
}

function registerRetryGateway(?string $redirect = 'https://pay.test/retry', bool $explode = false): void
{
    app(PaymentGatewayManager::class)->register(new class($redirect, $explode) implements PaymentGatewayContract
    {
        public function __construct(private ?string $redirect, private bool $explode) {}

        public function key(): string
        {
            return 'retryfake';
        }

        public function label(): string
        {
            return 'Retry Fake';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function listChannels(): array
        {
            return [];
        }

        public function createTransaction(Order $order, int $amount, array $options = []): array
        {
            if ($this->explode) {
                throw new RuntimeException('gateway down');
            }

            return ['reference' => 'RETRY-'.$order->order_number, 'redirect_url' => $this->redirect, 'token' => null, 'expires_at' => null, 'raw' => ['amount' => $amount]];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => '', 'status' => PaymentStatus::PENDING, 'paid_at' => null, 'raw' => []];
        }

        public function getStatus(string $reference): array
        {
            return ['reference' => $reference, 'status' => PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }
    });

    \App\Models\Setting::updateOrCreate(['key' => 'payment_active_gateway'], ['value' => 'retryfake']);
}

test('the courier reporting delivery emails the customer once', function () {
    Mail::fake();
    fakeShipmentStatus('delivered');
    $order = deliveryOrder();

    $hook = fn () => $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-dlv-1',
    ])->assertOk();

    $hook();

    expect($order->fresh()->status)->toBe(Order::STATUS_COMPLETED);
    Mail::assertSent(OrderDeliveredMail::class, function (OrderDeliveredMail $mail) {
        expect($mail->render())->toContain('JNE777');

        return $mail->hasTo('yohana@example.com');
    });

    // Biteship re-sends the same status; the customer hears about it once.
    $hook();
    Mail::assertSent(OrderDeliveredMail::class, 1);
});

test('an in-transit update does not claim the parcel arrived', function () {
    Mail::fake();
    fakeShipmentStatus('intransit');
    deliveryOrder(['status' => Order::STATUS_PROCESSING]);

    $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-dlv-1',
    ])->assertOk();

    Mail::assertNotSent(OrderDeliveredMail::class);
});

test('an unpaid order page offers a way back to paying', function () {
    $order = deliveryOrder(['status' => Order::STATUS_PENDING, 'biteship_order_id' => null]);

    $this->get(URL::signedRoute('order.show', ['order' => $order->order_number]))
        ->assertInertia(fn ($page) => $page->where('payUrl', fn ($url) => str_contains((string) $url, '/bayar')));
});

test('a paid order page offers no pay button', function () {
    $order = deliveryOrder([
        'status' => Order::STATUS_PAID,
        'payment_status' => PaymentStatus::PAID,
        'biteship_order_id' => null,
    ]);

    $this->get(URL::signedRoute('order.show', ['order' => $order->order_number]))
        ->assertInertia(fn ($page) => $page->where('payUrl', null));
});

test('paying again reopens the gateway for an abandoned payment', function () {
    registerRetryGateway('https://pay.test/retry');
    $order = deliveryOrder(['status' => Order::STATUS_PENDING, 'biteship_order_id' => null, 'shipping_cost' => 15000]);

    $payUrl = URL::signedRoute('order.pay', ['order' => $order->order_number]);

    $this->post($payUrl)->assertRedirect('https://pay.test/retry');

    // The button posts through Inertia's router, which needs a 409 with
    // the external location to leave the SPA for the gateway page.
    $this->withHeaders(['X-Inertia' => 'true'])
        ->post($payUrl)
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://pay.test/retry');

    expect($order->fresh()->payment_reference)->toBe('RETRY-ORD-DLV-001');
    // Charged the same total the customer was quoted: subtotal + ongkir.
    expect($order->fresh()->total)->toBe(135000);
});

test('a paid order cannot be paid again', function () {
    registerRetryGateway();
    $order = deliveryOrder([
        'status' => Order::STATUS_PAID,
        'payment_status' => PaymentStatus::PAID,
        'biteship_order_id' => null,
    ]);

    $this->post(URL::signedRoute('order.pay', ['order' => $order->order_number]))
        ->assertSessionHasErrors('payment');
});

test('a cancelled order cannot be paid', function () {
    registerRetryGateway();
    $order = deliveryOrder(['status' => Order::STATUS_CANCELLED, 'biteship_order_id' => null]);

    $this->post(URL::signedRoute('order.pay', ['order' => $order->order_number]))
        ->assertSessionHasErrors('payment');
});

test('the pay action refuses an unsigned request', function () {
    registerRetryGateway();
    $order = deliveryOrder(['status' => Order::STATUS_PENDING, 'biteship_order_id' => null]);

    $this->post(route('order.pay', ['order' => $order->order_number]))->assertForbidden();
});

test('a gateway outage on retry tells the customer instead of failing silently', function () {
    registerRetryGateway(explode: true);
    $order = deliveryOrder(['status' => Order::STATUS_PENDING, 'biteship_order_id' => null]);

    $this->post(URL::signedRoute('order.pay', ['order' => $order->order_number]))
        ->assertSessionHasErrors('payment');

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});
