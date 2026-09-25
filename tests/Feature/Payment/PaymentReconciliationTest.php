<?php

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Mail\OrderCancelledMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\StaffOrderPaidMail;
use App\Mail\StaffRefundNeededMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

/**
 * A gateway whose status lookup answers whatever the test sets, and whose
 * webhook always reports the given status for REF-RC.
 */
function reconcileGateway(string $status = PaymentStatus::PAID, bool $explode = false): void
{
    app(PaymentGatewayManager::class)->register(new class($status, $explode) implements PaymentGatewayContract
    {
        public function __construct(private string $status, private bool $explode) {}

        public function key(): string
        {
            return 'rcfake';
        }

        public function label(): string
        {
            return 'Reconcile Fake';
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
            return ['reference' => 'REF-RC', 'redirect_url' => null, 'token' => null, 'expires_at' => null, 'raw' => []];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => 'REF-RC', 'status' => $this->status, 'amount' => 135000, 'paid_at' => now()->toIso8601String(), 'raw' => $payload];
        }

        public function getStatus(string $reference): array
        {
            if ($this->explode) {
                throw new RuntimeException('gateway unreachable');
            }

            return ['reference' => $reference, 'status' => $this->status, 'amount' => 135000, 'paid_at' => now()->toIso8601String(), 'raw' => ['checked' => true]];
        }
    });
}

function reconcileOrder(array $overrides = []): Order
{
    $product = Product::create(['name' => 'Noken', 'price' => 120000, 'stock' => 7, 'weight_gram' => 300, 'is_active' => true]);

    return Order::create(array_merge([
        'order_number' => 'ORD-RC-001',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 120000,
        'total' => 135000,
        'payment_gateway' => 'rcfake',
        'payment_reference' => 'REF-RC',
        'payment_status' => PaymentStatus::PENDING,
        'stock_draw' => [$product->id => 3],
    ], $overrides));
}

function reconcileStaff(array $permissions = ['order.view', 'order.update']): User
{
    $user = User::factory()->create(['email' => 'staff@example.com']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function expireOld(Order $order): void
{
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(30)]);
    test()->artisan('orders:expire-unpaid')->assertSuccessful();
}

// --- The expiry job asks the gateway first -------------------------------

test('an order the gateway says was paid is recorded as paid instead of expired', function () {
    Mail::fake();
    reconcileGateway(PaymentStatus::PAID);
    reconcileStaff(['order.view']);
    $order = reconcileOrder();

    expireOld($order);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PAID);
    expect($order->payment_status)->toBe(PaymentStatus::PAID);
    expect($order->paid_at)->not->toBeNull();
    expect($order->stock_released_at)->toBeNull();
    expect(Product::first()->stock)->toBe(7);
    Mail::assertSent(PaymentReceivedMail::class, 1);
    Mail::assertSent(StaffOrderPaidMail::class, 1);
    Mail::assertNotSent(OrderCancelledMail::class);
});

test('an order the gateway confirms is unpaid still expires, with the gateway status recorded', function () {
    Mail::fake();
    reconcileGateway(PaymentStatus::EXPIRED);
    $order = reconcileOrder();

    expireOld($order);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_CANCELLED);
    expect($order->payment_status)->toBe(PaymentStatus::EXPIRED);
    expect(Product::first()->stock)->toBe(10);
    Mail::assertSent(OrderCancelledMail::class, 1);
});

test('when the gateway cannot be asked, the order is left alone rather than cancelled on a guess', function () {
    Mail::fake();
    Log::spy();
    reconcileGateway(explode: true);
    $order = reconcileOrder();

    expireOld($order);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PENDING);
    expect($order->stock_released_at)->toBeNull();
    expect(Product::first()->stock)->toBe(7);
    Mail::assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'ORD-RC-001'));
});

test('an order that never reached a gateway expires without asking one', function () {
    Mail::fake();
    $order = reconcileOrder(['payment_gateway' => null, 'payment_reference' => null, 'payment_status' => null]);

    expireOld($order);

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
});

// --- A payment arriving after cancellation --------------------------------

test('a payment that lands after the order was cancelled is recorded, the order stays cancelled, and a refund is set in motion', function () {
    Mail::fake();
    reconcileGateway(PaymentStatus::PAID);
    reconcileStaff(['order.view']);
    $order = reconcileOrder(['status' => Order::STATUS_CANCELLED, 'stock_released_at' => now()]);

    $this->postJson(route('payment.callback', ['gateway' => 'rcfake']), ['late' => true])->assertOk();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_CANCELLED);
    expect($order->payment_status)->toBe(PaymentStatus::PAID);
    expect($order->paid_at)->not->toBeNull();
    Mail::assertSent(OrderCancelledMail::class, function (OrderCancelledMail $mail) {
        expect($mail->render())->toContain('pengembalian dana');

        return $mail->hasTo('yohana@example.com');
    });
    Mail::assertSent(StaffRefundNeededMail::class, 1);
    Mail::assertNotSent(PaymentReceivedMail::class);
    Mail::assertNotSent(StaffOrderPaidMail::class);
});

// --- Staff can ask the gateway on demand ---------------------------------

test('staff checking a pending order with the gateway records the payment it reports', function () {
    Mail::fake();
    reconcileGateway(PaymentStatus::PAID);
    $staff = reconcileStaff();
    $order = reconcileOrder();

    $this->actingAs($staff)->post(route('backoffice.order.payment-check', $order->id))->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
    Mail::assertSent(PaymentReceivedMail::class, 1);
});

test('checking an order the gateway still reports unpaid changes nothing but the recorded gateway status', function () {
    Mail::fake();
    reconcileGateway(PaymentStatus::PENDING);
    $staff = reconcileStaff();
    $order = reconcileOrder(['payment_status' => null]);

    $this->actingAs($staff)->post(route('backoffice.order.payment-check', $order->id))->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PENDING);
    expect($order->payment_status)->toBe(PaymentStatus::PENDING);
    Mail::assertNothingSent();
});

test('a gateway failure during a manual check is shown to staff', function () {
    reconcileGateway(explode: true);
    $staff = reconcileStaff();
    $order = reconcileOrder();

    $this->actingAs($staff)->post(route('backoffice.order.payment-check', $order->id))
        ->assertSessionHasErrors('errors');

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});

test('an order with no gateway transaction cannot be checked', function () {
    $staff = reconcileStaff();
    $order = reconcileOrder(['payment_gateway' => null, 'payment_reference' => null]);

    $this->actingAs($staff)->post(route('backoffice.order.payment-check', $order->id))
        ->assertSessionHasErrors('errors');
});

test('checking a payment requires order.update', function () {
    reconcileGateway(PaymentStatus::PAID);
    $viewer = reconcileStaff(['order.view']);
    $order = reconcileOrder();

    $this->actingAs($viewer)->post(route('backoffice.order.payment-check', $order->id))->assertForbidden();

    expect($order->fresh()->status)->toBe(Order::STATUS_PENDING);
});
