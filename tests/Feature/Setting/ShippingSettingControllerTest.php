<?php

use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function shippingSettingAdmin(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
    }

    return $user;
}

test('guests are redirected away from the shipping settings page', function () {
    $this->get(route('backoffice.setting.shipping.index'))->assertRedirect(route('login'));
});

test('a user without setting.view is forbidden', function () {
    $user = shippingSettingAdmin([]);

    $this->actingAs($user)
        ->get(route('backoffice.setting.shipping.index'))
        ->assertForbidden();
});

test('the index page lists biteship with its configured status and the current preference', function () {
    $user = shippingSettingAdmin(['setting.view']);

    config([
        'services.biteship.api_key' => 'biteship_test.abc',
        'services.biteship.origin_area_id' => 'IDNP6IDNC148IDND854IDZ10730',
    ]);
    Setting::create(['key' => 'shipping_preferred_collection_method', 'value' => 'pickup']);

    $response = $this->actingAs($user)->get(route('backoffice.setting.shipping.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('setting/shipping/index')
        ->where('providers.0.key', 'biteship')
        ->where('providers.0.label', 'Biteship')
        ->where('providers.0.configured', true)
        ->where('current.shipping_preferred_collection_method', 'pickup'));
});

test('the index page reports biteship as unconfigured when credentials are missing', function () {
    $user = shippingSettingAdmin(['setting.view']);

    config(['services.biteship.api_key' => null, 'services.biteship.origin_area_id' => null]);

    $response = $this->actingAs($user)->get(route('backoffice.setting.shipping.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('providers.0.configured', false));
});

test('a user without setting.update cannot save the preferred collection method', function () {
    $user = shippingSettingAdmin(['setting.view']);

    $this->actingAs($user)
        ->put(route('backoffice.setting.shipping.update'), ['shipping_preferred_collection_method' => 'pickup'])
        ->assertForbidden();
});

test('saving persists the preferred collection method', function () {
    $user = shippingSettingAdmin(['setting.view', 'setting.update']);

    $response = $this->actingAs($user)->put(
        route('backoffice.setting.shipping.update'),
        ['shipping_preferred_collection_method' => 'drop_off']
    );

    $response->assertRedirect(route('backoffice.setting.shipping.index'));
    expect(Setting::where('key', 'shipping_preferred_collection_method')->value('value'))->toBe('drop_off');
});

test('an invalid collection method is rejected', function () {
    $user = shippingSettingAdmin(['setting.view', 'setting.update']);

    $response = $this->actingAs($user)->put(
        route('backoffice.setting.shipping.update'),
        ['shipping_preferred_collection_method' => 'teleport']
    );

    $response->assertSessionHasErrors('shipping_preferred_collection_method');
});
