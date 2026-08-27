<?php

use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function paymentSettingAdmin(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

test('guests are redirected away from the payment settings page', function () {
    $this->get(route('backoffice.setting.payment.index'))->assertRedirect(route('login'));
});

test('a user without setting.view is forbidden', function () {
    $user = paymentSettingAdmin([]);

    $this->actingAs($user)
        ->get(route('backoffice.setting.payment.index'))
        ->assertForbidden();
});

test('the index page lists every registered gateway with its configured status and current settings', function () {
    $user = paymentSettingAdmin(['setting.view']);

    Setting::create(['key' => 'payment_active_gateway', 'value' => 'tripay']);
    Setting::create(['key' => 'payment_admin_fee_borne_by', 'value' => 'customer']);
    Setting::create(['key' => 'payment_admin_fee_flat', 'value' => '4000']);

    $response = $this->actingAs($user)->get(route('backoffice.setting.payment.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('setting/payment/index')
        ->where('current.payment_active_gateway', 'tripay')
        ->where('current.payment_admin_fee_borne_by', 'customer')
        ->where('current.payment_admin_fee_flat', '4000')
        ->has('gateways', 5)
        ->where('gateways.0.key', 'tripay')
        ->where('gateways.0.label', 'Tripay')
        ->where('gateways.0.configured', false));
});

test('the channels endpoint 404s for an unknown gateway', function () {
    $user = paymentSettingAdmin(['setting.view']);

    $this->actingAs($user)
        ->get(route('backoffice.setting.payment.channels', ['gateway' => 'not-a-gateway']))
        ->assertNotFound();
});

test('the channels endpoint returns an empty list for a hosted-page gateway', function () {
    $user = paymentSettingAdmin(['setting.view']);

    $response = $this->actingAs($user)
        ->get(route('backoffice.setting.payment.channels', ['gateway' => 'midtrans']));

    $response->assertOk();
    $response->assertJson(['channels' => [], 'error' => null]);
});

test('the channels endpoint degrades gracefully instead of 500ing when the gateway call fails', function () {
    $user = paymentSettingAdmin(['setting.view']);

    // Tripay is configured with no credentials in the testing env, so its
    // listChannels() HTTP call will fail against the real (unmocked) host.
    $response = $this->actingAs($user)
        ->get(route('backoffice.setting.payment.channels', ['gateway' => 'tripay']));

    $response->assertOk();
    $response->assertJson(['channels' => []]);
    expect($response->json('error'))->not->toBeNull();
});

test('a user without setting.update cannot save payment settings', function () {
    $user = paymentSettingAdmin(['setting.view']);

    $this->actingAs($user)
        ->put(route('backoffice.setting.payment.update'), [
            'payment_active_gateway' => 'tripay',
            'payment_admin_fee_borne_by' => 'merchant',
        ])
        ->assertForbidden();
});

test('saving payment settings persists all four keys and rejects an unregistered gateway', function () {
    $user = paymentSettingAdmin(['setting.view', 'setting.update']);

    $this->actingAs($user)
        ->put(route('backoffice.setting.payment.update'), [
            'payment_active_gateway' => 'not-a-real-gateway',
            'payment_admin_fee_borne_by' => 'merchant',
        ])
        ->assertSessionHasErrors('payment_active_gateway');

    $this->actingAs($user)
        ->put(route('backoffice.setting.payment.update'), [
            'payment_active_gateway' => 'tripay',
            'payment_default_channel' => 'BRIVA',
            'payment_admin_fee_borne_by' => 'customer',
            'payment_admin_fee_flat' => 4000,
        ])
        ->assertRedirect();

    $settings = Setting::query()->pluck('value', 'key');

    expect($settings['payment_active_gateway'])->toBe('tripay');
    expect($settings['payment_default_channel'])->toBe('BRIVA');
    expect($settings['payment_admin_fee_borne_by'])->toBe('customer');
    expect((int) $settings['payment_admin_fee_flat'])->toBe(4000);
});

test('saving with a customer-borne fee policy requires the flat fee amount', function () {
    $user = paymentSettingAdmin(['setting.view', 'setting.update']);

    $this->actingAs($user)
        ->put(route('backoffice.setting.payment.update'), [
            'payment_active_gateway' => 'tripay',
            'payment_admin_fee_borne_by' => 'customer',
        ])
        ->assertSessionHasErrors('payment_admin_fee_flat');
});

test('saving persists the per-channel fee schedule and it comes back correctly on reload', function () {
    $user = paymentSettingAdmin(['setting.view', 'setting.update']);

    $this->actingAs($user)
        ->put(route('backoffice.setting.payment.update'), [
            'payment_active_gateway' => 'tripay',
            'payment_default_channel' => 'QRIS',
            'payment_admin_fee_borne_by' => 'customer',
            'payment_admin_fee_flat' => 2000,
            'payment_channel_fees' => [
                'tripay' => [
                    'QRIS' => ['flat' => 0, 'percent' => 0.7],
                    'BRIVA' => ['flat' => 4250, 'percent' => 0],
                ],
            ],
        ])
        ->assertRedirect();

    $stored = json_decode(Setting::query()->where('key', 'payment_channel_fees')->value('value'), true);

    expect($stored['tripay']['QRIS'])->toBe(['flat' => 0, 'percent' => 0.7]);
    expect($stored['tripay']['BRIVA'])->toBe(['flat' => 4250, 'percent' => 0]);

    $response = $this->actingAs($user)->get(route('backoffice.setting.payment.index'));
    $response->assertInertia(fn ($page) => $page
        ->where('current.payment_channel_fees.tripay.QRIS.percent', 0.7)
        ->where('current.payment_channel_fees.tripay.BRIVA.flat', 4250));
});

test('a bad percent value in the channel fee schedule is rejected', function () {
    $user = paymentSettingAdmin(['setting.view', 'setting.update']);

    $this->actingAs($user)
        ->put(route('backoffice.setting.payment.update'), [
            'payment_active_gateway' => 'tripay',
            'payment_admin_fee_borne_by' => 'merchant',
            'payment_channel_fees' => [
                'tripay' => ['QRIS' => ['flat' => 0, 'percent' => 150]],
            ],
        ])
        ->assertSessionHasErrors('payment_channel_fees.tripay.QRIS.percent');
});
