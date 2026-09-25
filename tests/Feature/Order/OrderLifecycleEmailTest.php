<?php

use App\Contract\Payment\PaymentGatewayContract;
use App\Contract\Payment\PaymentStatus;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderDeliveredMail;
use App\Mail\OrderShipmentCancelledMail;
use App\Mail\OrderShippedMail;
use App\Mail\PaymentReceivedMail;
use App\Mail\StaffOrderPaidMail;
use App\Mail\StaffRefundNeededMail;
use App\Mail\StaffShipmentProblemMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;

function lifecycleOrder(array $overrides = []): Order
{
    return Order::create(array_merge([
        'order_number' => 'ORD-LC-001',
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

function lifecycleStaff(array $permissions = ['order.view', 'order.update'], string $email = 'staff@example.com'): User
{
    $user = User::factory()->create(['email' => $email]);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

function lifecycleSetStatus(User $user, Order $order, string $status): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->put(route('backoffice.order.update-status', $order->id), ['status' => $status]);
}

function lifecycleBiteship(string $status, ?string $waybill = 'JNE777'): void
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
        'services.biteship.origin_area_id' => 'ORIGIN-1',
        'services.biteship.webhook_token' => 'super-secret-token',
    ]);

    Http::fake([
        'api.biteship.com/v1/orders/bts-lc-1' => Http::response([
            'success' => true,
            'id' => 'bts-lc-1',
            'status' => $status,
            'price' => 15000,
            'courier' => ['company' => 'jne', 'type' => 'reg', 'waybill_id' => $waybill],
        ], 200),
    ]);
}

function lifecycleWebhook(): void
{
    test()->postJson(route('webhook.shipping.biteship', ['token' => 'super-secret-token']), [
        'order_id' => 'bts-lc-1',
    ])->assertOk();
}

function lifecyclePaidGateway(): void
{
    app(PaymentGatewayManager::class)->register(new class implements PaymentGatewayContract
    {
        public function key(): string
        {
            return 'lcfake';
        }

        public function label(): string
        {
            return 'Lifecycle Fake';
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
            return ['reference' => 'REF-LC', 'redirect_url' => null, 'token' => null, 'expires_at' => null, 'raw' => []];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => 'REF-LC', 'status' => PaymentStatus::PAID, 'paid_at' => now(), 'raw' => $payload];
        }

        public function getStatus(string $reference): array
        {
            return ['reference' => $reference, 'status' => PaymentStatus::PAID, 'amount' => 135000, 'paid_at' => null, 'raw' => []];
        }
    });
}

// --- Step 1: the courier can finish a paid order -------------------------

test('a paid order is completed when the courier reports delivery, and the customer is told once', function () {
    Mail::fake();
    lifecycleBiteship('delivered');
    $order = lifecycleOrder(['status' => Order::STATUS_PAID, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => 'JNE777']);

    lifecycleWebhook();
    lifecycleWebhook();

    expect($order->fresh()->status)->toBe(Order::STATUS_COMPLETED);
    Mail::assertSent(OrderDeliveredMail::class, 1);
});

test('a paid order moves to shipped when the courier picks it up', function () {
    Mail::fake();
    lifecycleBiteship('picked');
    $order = lifecycleOrder(['status' => Order::STATUS_PAID, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => 'JNE777']);

    lifecycleWebhook();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

// --- Step 2: staff can only move an order forward -------------------------

test('staff cannot move an order to a status it is not allowed to reach', function (string $from, string $to) {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => $from]);

    lifecycleSetStatus($staff, $order, $to)->assertSessionHasErrors('errors');

    expect($order->fresh()->status)->toBe($from);
    Mail::assertNothingSent();
})->with([
    'reopen a cancelled order' => [Order::STATUS_CANCELLED, Order::STATUS_PENDING],
    'undo a completed order' => [Order::STATUS_COMPLETED, Order::STATUS_SHIPPED],
    'cancel a completed order' => [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED],
    'ship an unpaid order' => [Order::STATUS_PENDING, Order::STATUS_SHIPPED],
    'move backward' => [Order::STATUS_SHIPPED, Order::STATUS_PROCESSING],
    'same status' => [Order::STATUS_PAID, Order::STATUS_PAID],
]);

test('reopening a cancelled order is refused, so its released stock is never sold twice', function () {
    $staff = lifecycleStaff();
    $product = Product::create(['name' => 'Kopi Wamena', 'price' => 60000, 'stock' => 10, 'weight_gram' => 250, 'is_active' => true]);
    $order = lifecycleOrder([
        'status' => Order::STATUS_CANCELLED,
        'stock_draw' => [$product->id => 3],
        'stock_released_at' => now(),
    ]);

    lifecycleSetStatus($staff, $order, Order::STATUS_PENDING)->assertSessionHasErrors('errors');

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
    expect($product->fresh()->stock)->toBe(10);
});

test('the order page only offers the statuses the order can move to', function () {
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_PAID]);

    $this->actingAs($staff)->get(route('backoffice.order.show', $order->id))
        ->assertInertia(fn ($page) => $page
            ->where('nextStatuses', [
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ]));
});

test('a finished order offers no further statuses', function () {
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_COMPLETED]);

    $this->actingAs($staff)->get(route('backoffice.order.show', $order->id))
        ->assertInertia(fn ($page) => $page->where('nextStatuses', []));
});

// --- Step 3: manual status changes tell the customer ----------------------

test('staff confirming a payment records it and emails the receipt', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder();

    lifecycleSetStatus($staff, $order, Order::STATUS_PAID)->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PAID);
    expect($order->payment_status)->toBe(PaymentStatus::PAID);
    expect($order->paid_at)->not->toBeNull();
    Mail::assertSent(PaymentReceivedMail::class, fn ($mail) => $mail->hasTo('yohana@example.com'));
    Mail::assertSent(PaymentReceivedMail::class, 1);
    // Staff did this themselves; nobody needs telling.
    Mail::assertNotSent(StaffOrderPaidMail::class);
});

test('a manually confirmed payment is never expired by the unpaid-order job', function () {
    $staff = lifecycleStaff();
    $order = lifecycleOrder();
    lifecycleSetStatus($staff, $order, Order::STATUS_PAID);
    Order::query()->whereKey($order->id)->update(['created_at' => now()->subHours(48)]);

    $this->artisan('orders:expire-unpaid');

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

test('moving to processing is internal and emails nobody', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_PAID]);

    lifecycleSetStatus($staff, $order, Order::STATUS_PROCESSING)->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_PROCESSING);
    Mail::assertNothingSent();
});

test('marking shipped by hand emails the customer when no shipment email went out yet', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_PROCESSING]);

    lifecycleSetStatus($staff, $order, Order::STATUS_SHIPPED)->assertSessionHasNoErrors();

    Mail::assertSent(OrderShippedMail::class, 1);
});

test('marking shipped by hand does not repeat the email a Biteship shipment already sent', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_PROCESSING, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => 'JNE777']);

    lifecycleSetStatus($staff, $order, Order::STATUS_SHIPPED)->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    Mail::assertNotSent(OrderShippedMail::class);
});

test('a tracking number typed in before shipping goes out once, with the shipped email', function (string $savedWhile) {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => $savedWhile]);

    $this->actingAs($staff)->put(route('backoffice.order.update-shipping', $order->id), [
        'shipping_cost' => 15000, 'courier_code' => 'jne', 'courier_service' => 'Regular', 'tracking_number' => 'JNE999',
    ])->assertSessionHasNoErrors();
    Mail::assertNothingSent();

    if ($savedWhile === Order::STATUS_PENDING) {
        lifecycleSetStatus($staff, $order, Order::STATUS_PAID)->assertSessionHasNoErrors();
    }
    lifecycleSetStatus($staff, $order, Order::STATUS_SHIPPED)->assertSessionHasNoErrors();

    Mail::assertSent(OrderShippedMail::class, 1);
    Mail::assertSent(OrderShippedMail::class, function (OrderShippedMail $mail) {
        expect($mail->render())->toContain('JNE999');

        return true;
    });
})->with([Order::STATUS_PENDING, Order::STATUS_PROCESSING]);

test('marking completed by hand emails the delivery note', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_SHIPPED]);

    lifecycleSetStatus($staff, $order, Order::STATUS_COMPLETED)->assertSessionHasNoErrors();

    Mail::assertSent(OrderDeliveredMail::class, 1);
});

test('cancelling tells the customer even when the stock had already gone back', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['stock_released_at' => now()]);

    lifecycleSetStatus($staff, $order, Order::STATUS_CANCELLED)->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED);
    Mail::assertSent(OrderCancelledMail::class, 1);
});

test('saving a new tracking number on a shipped order emails it to the customer once', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_SHIPPED]);
    $payload = [
        'shipping_cost' => 15000,
        'courier_code' => 'jne',
        'courier_name' => 'JNE',
        'courier_service' => 'Regular',
        'tracking_number' => 'JNE999',
    ];

    $this->actingAs($staff)->put(route('backoffice.order.update-shipping', $order->id), $payload)->assertSessionHasNoErrors();
    // Saving again with the same number is not news to the customer.
    $this->actingAs($staff)->put(route('backoffice.order.update-shipping', $order->id), $payload)->assertSessionHasNoErrors();

    Mail::assertSent(OrderShippedMail::class, function (OrderShippedMail $mail) {
        expect($mail->render())->toContain('JNE999');

        return true;
    });
    Mail::assertSent(OrderShippedMail::class, 1);
});

test('a corrected tracking number is emailed again', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_SHIPPED, 'tracking_number' => 'JNE999']);

    $this->actingAs($staff)->put(route('backoffice.order.update-shipping', $order->id), [
        'shipping_cost' => 15000, 'courier_code' => 'jne', 'courier_service' => 'Regular', 'tracking_number' => 'JNE998',
    ])->assertSessionHasNoErrors();

    Mail::assertSent(OrderShippedMail::class, 1);
});

test('saving shipping details on an unpaid or cancelled order emails nobody', function (string $status) {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => $status]);

    $this->actingAs($staff)->put(route('backoffice.order.update-shipping', $order->id), [
        'shipping_cost' => 15000, 'courier_code' => 'jne', 'courier_service' => 'Regular', 'tracking_number' => 'JNE999',
    ])->assertSessionHasNoErrors();

    Mail::assertNothingSent();
})->with([Order::STATUS_PENDING, Order::STATUS_CANCELLED]);

test('a waybill the courier assigns after the shipment was created is emailed to the customer', function () {
    Mail::fake();
    lifecycleBiteship('allocated', 'JNE555');
    $order = lifecycleOrder(['status' => Order::STATUS_PAID, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => null]);

    lifecycleWebhook();
    lifecycleWebhook();

    Mail::assertSent(OrderShippedMail::class, function (OrderShippedMail $mail) {
        expect($mail->render())->toContain('JNE555');

        return true;
    });
    Mail::assertSent(OrderShippedMail::class, 1);
});

// --- Step 4: staff hear about the things they must act on -----------------

test('a confirmed payment tells staff who can see orders that one is ready to pack', function () {
    Mail::fake();
    lifecyclePaidGateway();
    lifecycleStaff(['order.view'], 'packer@example.com');
    lifecycleStaff(['product.view'], 'catalogue@example.com');
    $order = lifecycleOrder(['payment_gateway' => 'lcfake', 'payment_reference' => 'REF-LC', 'payment_status' => PaymentStatus::PENDING]);

    $this->postJson(route('payment.callback', ['gateway' => 'lcfake']), ['ok' => true])->assertOk();
    $this->postJson(route('payment.callback', ['gateway' => 'lcfake']), ['ok' => true])->assertOk();

    Mail::assertSent(StaffOrderPaidMail::class, 1);
    Mail::assertSent(StaffOrderPaidMail::class, function (StaffOrderPaidMail $mail) use ($order) {
        expect($mail->render())->toContain($order->order_number);

        return $mail->hasTo('packer@example.com') && ! $mail->hasTo('catalogue@example.com');
    });
});

test('with no staff able to see orders, a payment still completes and the gap is logged', function () {
    Mail::fake();
    Log::spy();
    lifecyclePaidGateway();
    $order = lifecycleOrder(['payment_gateway' => 'lcfake', 'payment_reference' => 'REF-LC', 'payment_status' => PaymentStatus::PENDING]);

    $this->postJson(route('payment.callback', ['gateway' => 'lcfake']), ['ok' => true])->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
    Mail::assertNotSent(StaffOrderPaidMail::class);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'order.view'));
});

test('cancelling a paid order reminds staff a refund is owed', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_PAID, 'paid_at' => now(), 'payment_status' => PaymentStatus::PAID]);

    lifecycleSetStatus($staff, $order, Order::STATUS_CANCELLED)->assertSessionHasNoErrors();

    Mail::assertSent(OrderCancelledMail::class, fn ($mail) => $mail->hasTo('yohana@example.com'));
    Mail::assertSent(StaffRefundNeededMail::class, function (StaffRefundNeededMail $mail) use ($order) {
        expect($mail->render())->toContain($order->order_number);

        return $mail->hasTo('staff@example.com');
    });
});

test('cancelling an unpaid order owes no refund', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder();

    lifecycleSetStatus($staff, $order, Order::STATUS_CANCELLED)->assertSessionHasNoErrors();

    Mail::assertNotSent(StaffRefundNeededMail::class);
});

test('a courier problem alerts staff once and leaves the order status for them to decide', function () {
    Mail::fake();
    lifecycleStaff(['order.view'], 'packer@example.com');
    lifecycleBiteship('returned');
    $order = lifecycleOrder(['status' => Order::STATUS_SHIPPED, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => 'JNE777']);

    lifecycleWebhook();
    lifecycleWebhook();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    Mail::assertSent(StaffShipmentProblemMail::class, 1);
    Mail::assertSent(StaffShipmentProblemMail::class, function (StaffShipmentProblemMail $mail) {
        expect($mail->render())->toContain('returned');

        return $mail->hasTo('packer@example.com');
    });
    // The customer is not told about a problem staff haven't looked at yet.
    Mail::assertNotSent(OrderShippedMail::class);
});

test('a normal courier update raises no alert', function () {
    Mail::fake();
    lifecycleStaff(['order.view'], 'packer@example.com');
    lifecycleBiteship('picked');
    lifecycleOrder(['status' => Order::STATUS_PAID, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => 'JNE777']);

    lifecycleWebhook();

    Mail::assertNotSent(StaffShipmentProblemMail::class);
});

// --- Step 5: a withdrawn shipment is explained to the customer ------------

function lifecycleCancelShipment(User $staff, Order $order): \Illuminate\Testing\TestResponse
{
    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.base_url' => 'https://api.biteship.com',
    ]);

    Http::fake([
        'api.biteship.com/v1/orders/bts-lc-1/cancel' => Http::response(['success' => true, 'id' => 'bts-lc-1', 'status' => 'cancelled'], 200),
    ]);

    return test()->actingAs($staff)->post(route('backoffice.order.shipping-cancel', $order->id), [
        'cancellation_reason_code' => 'change_courier',
    ]);
}

test('cancelling a shipment tells the customer the old tracking number is void', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_PAID, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => 'JNE777']);

    lifecycleCancelShipment($staff, $order)->assertSessionHasNoErrors();

    Mail::assertSent(OrderShipmentCancelledMail::class, function (OrderShipmentCancelledMail $mail) {
        expect($mail->render())->toContain('JNE777');

        return $mail->hasTo('yohana@example.com');
    });
});

test('cancelling the shipment of a cancelled order sends no second message', function () {
    Mail::fake();
    $staff = lifecycleStaff();
    $order = lifecycleOrder(['status' => Order::STATUS_CANCELLED, 'biteship_order_id' => 'bts-lc-1', 'tracking_number' => 'JNE777']);

    lifecycleCancelShipment($staff, $order)->assertSessionHasNoErrors();

    Mail::assertNotSent(OrderShipmentCancelledMail::class);
});
