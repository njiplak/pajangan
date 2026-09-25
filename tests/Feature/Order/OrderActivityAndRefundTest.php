<?php

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Mail\OrderRefundedMail;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\User;
use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

function activityStaff(array $permissions = ['order.view', 'order.update'], string $name = 'Rina Gudang'): User
{
    $user = User::factory()->create(['name' => $name]);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function activityOrder(array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ORD-ACT-001',
        'customer_name' => 'Yohana Wenda',
        'customer_email' => 'yohana@example.com',
        'customer_phone' => '0812',
        'shipping_address' => 'Jl. Test',
        'shipping_city' => 'Jayapura',
        'shipping_province' => 'Papua',
        'status' => Order::STATUS_PENDING,
        'subtotal' => 120000,
        'total' => 135000,
    ], $overrides));
}

function activityGateway(string $status): void
{
    app(PaymentGatewayManager::class)->register(new class($status) implements PaymentGatewayContract
    {
        public function __construct(private string $status) {}

        public function key(): string
        {
            return 'actfake';
        }

        public function label(): string
        {
            return 'Activity Fake';
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
            return ['reference' => 'REF-ACT', 'redirect_url' => null, 'token' => null, 'expires_at' => null, 'raw' => []];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => 'REF-ACT', 'status' => $this->status, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }

        public function getStatus(string $reference): array
        {
            return ['reference' => $reference, 'status' => $this->status, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }
    });
}

// --- Who changed what -----------------------------------------------------

test('a status change is logged with the staff member who made it', function () {
    $staff = activityStaff();
    $order = activityOrder(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

    $this->actingAs($staff)->put(route('backoffice.order.update-status', $order->id), ['status' => Order::STATUS_PROCESSING]);

    $activity = OrderActivity::where('order_id', $order->id)->sole();
    expect($activity->user_id)->toBe($staff->id);
    expect($activity->action)->toBe('status');
    expect($activity->description)->toContain('Dibayar')->toContain('Diproses');
});

test('a refused status change leaves no log line', function () {
    $staff = activityStaff();
    $order = activityOrder(['status' => Order::STATUS_CANCELLED]);

    $this->actingAs($staff)->put(route('backoffice.order.update-status', $order->id), ['status' => Order::STATUS_PAID]);

    expect(OrderActivity::count())->toBe(0);
});

test('a payment confirmed by the gateway is logged as the system, not a person', function () {
    activityGateway(PaymentStatus::PAID);
    $order = activityOrder(['payment_gateway' => 'actfake', 'payment_reference' => 'REF-ACT', 'payment_status' => PaymentStatus::PENDING]);

    $this->postJson(route('payment.callback', ['gateway' => 'actfake']), [])->assertOk();

    $activity = OrderActivity::where('order_id', $order->id)->sole();
    expect($activity->user_id)->toBeNull();
    expect($activity->action)->toBe('payment');
});

test('staff asking the gateway is logged with the answer', function () {
    activityGateway(PaymentStatus::PENDING);
    $staff = activityStaff();
    $order = activityOrder(['payment_gateway' => 'actfake', 'payment_reference' => 'REF-ACT', 'payment_status' => null]);

    $this->actingAs($staff)->post(route('backoffice.order.payment-check', $order->id));

    $check = OrderActivity::where('action', 'payment_check')->sole();
    expect($check->user_id)->toBe($staff->id);
    expect($check->description)->toContain(PaymentStatus::PENDING);
});

test('the expiry job logs the cancellation it made', function () {
    $order = activityOrder();
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(30)]);

    $this->artisan('orders:expire-unpaid');

    $activity = OrderActivity::where('order_id', $order->id)->where('action', 'status')->sole();
    expect($activity->user_id)->toBeNull();
    expect($activity->description)->toContain('otomatis');
});

test('a courier status change is logged once per change', function () {
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.webhook_token' => 'super-secret-token',
    ]);
    Http::fake([
        'api.biteship.com/v1/orders/bts-act-1' => Http::response([
            'success' => true, 'id' => 'bts-act-1', 'status' => 'picked', 'price' => 15000,
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => 'JNE1'],
        ], 200),
    ]);
    $order = activityOrder(['status' => Order::STATUS_PAID, 'biteship_order_id' => 'bts-act-1', 'tracking_number' => 'JNE1']);

    $hook = fn () => $this->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), ['order_id' => 'bts-act-1'])->assertOk();
    $hook();
    $hook();

    $activity = OrderActivity::where('order_id', $order->id)->where('action', 'courier')->sole();
    expect($activity->description)->toContain('picked');
});

test('saving shipping details is logged', function () {
    $staff = activityStaff();
    $order = activityOrder(['status' => Order::STATUS_PROCESSING]);

    $this->actingAs($staff)->put(route('backoffice.order.update-shipping', $order->id), [
        'shipping_cost' => 15000, 'courier_code' => 'jne', 'courier_service' => 'Regular', 'tracking_number' => 'JNE999',
    ])->assertSessionHasNoErrors();

    $activity = OrderActivity::where('action', 'shipping')->sole();
    expect($activity->user_id)->toBe($staff->id);
    expect($activity->description)->toContain('JNE999');
});

// --- Internal notes -------------------------------------------------------

test('staff can leave an internal note on an order', function () {
    $staff = activityStaff();
    $order = activityOrder();

    $this->actingAs($staff)->post(route('backoffice.order.notes', $order->id), ['body' => 'Pelanggan minta dibungkus kado.'])
        ->assertSessionHasNoErrors();

    $note = OrderActivity::where('action', 'note')->sole();
    expect($note->description)->toBe('Pelanggan minta dibungkus kado.');
    expect($note->user_id)->toBe($staff->id);
});

test('an empty note is refused', function () {
    $staff = activityStaff();
    $order = activityOrder();

    $this->actingAs($staff)->post(route('backoffice.order.notes', $order->id), ['body' => '   '])
        ->assertSessionHasErrors('body');
});

test('leaving a note requires order.update', function () {
    $viewer = activityStaff(['order.view']);
    $order = activityOrder();

    $this->actingAs($viewer)->post(route('backoffice.order.notes', $order->id), ['body' => 'x'])->assertForbidden();
});

test('the order page shows the log newest first with who did it', function () {
    $staff = activityStaff();
    $order = activityOrder();
    $this->actingAs($staff)->post(route('backoffice.order.notes', $order->id), ['body' => 'pertama']);
    $this->actingAs($staff)->post(route('backoffice.order.notes', $order->id), ['body' => 'kedua']);

    $this->actingAs($staff)->get(route('backoffice.order.show', $order->id))
        ->assertInertia(fn ($page) => $page
            ->where('activities.0.description', 'kedua')
            ->where('activities.0.user_name', 'Rina Gudang')
            ->where('activities.1.description', 'pertama'));
});

// --- Refunds --------------------------------------------------------------

test('recording a refund on a cancelled paid order marks it refunded, logs it and tells the customer', function () {
    Mail::fake();
    $staff = activityStaff();
    $order = activityOrder(['status' => Order::STATUS_CANCELLED, 'paid_at' => now(), 'payment_status' => PaymentStatus::PAID]);

    $this->actingAs($staff)->post(route('backoffice.order.refund', $order->id), ['refund_reference' => 'TRF-BCA-0925'])
        ->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->payment_status)->toBe(PaymentStatus::REFUNDED);
    expect($order->refunded_at)->not->toBeNull();
    expect($order->refund_reference)->toBe('TRF-BCA-0925');
    expect(OrderActivity::where('action', 'refund')->sole()->user_id)->toBe($staff->id);
    Mail::assertSent(OrderRefundedMail::class, function (OrderRefundedMail $mail) {
        expect($mail->render())->toContain('TRF-BCA-0925');

        return $mail->hasTo('yohana@example.com');
    });
});

test('a refund can only be recorded once, and only for a cancelled order that was paid', function (array $state) {
    Mail::fake();
    $staff = activityStaff();
    $order = activityOrder($state);

    $this->actingAs($staff)->post(route('backoffice.order.refund', $order->id), [])
        ->assertSessionHasErrors('errors');

    expect($order->fresh()->refunded_at?->toDateTimeString())->toBe($state['refunded_at'] ?? null);
    Mail::assertNothingSent();
})->with([
    'unpaid cancelled order' => [['status' => Order::STATUS_CANCELLED]],
    'paid order still active' => [['status' => Order::STATUS_PAID, 'paid_at' => now(), 'payment_status' => PaymentStatus::PAID]],
    'already refunded' => [['status' => Order::STATUS_CANCELLED, 'paid_at' => now(), 'payment_status' => PaymentStatus::REFUNDED, 'refunded_at' => '2026-09-01 10:00:00']],
]);

test('a stale gateway report cannot undo a recorded refund', function () {
    activityGateway(PaymentStatus::PAID);
    $order = activityOrder([
        'status' => Order::STATUS_CANCELLED, 'paid_at' => now(), 'refunded_at' => now(),
        'payment_status' => PaymentStatus::REFUNDED, 'payment_gateway' => 'actfake', 'payment_reference' => 'REF-ACT',
    ]);

    $this->postJson(route('payment.callback', ['gateway' => 'actfake']), [])->assertOk();

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::REFUNDED);
});
