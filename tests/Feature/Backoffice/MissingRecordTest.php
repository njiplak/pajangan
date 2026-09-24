<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;

/*
 * Every backoffice edit page for a record that does not exist must be a
 * 404. Before, Product and Banner threw a TypeError (500) and the others
 * opened an empty "edit" form for a record that was never there.
 */
dataset('backoffice show routes', [
    'product' => ['backoffice.product.show', 'product.update'],
    'banner' => ['backoffice.banner.show', 'banner.update'],
    'page' => ['backoffice.page.show', 'page.update'],
    'order' => ['backoffice.order.show', 'order.view'],
    'producer' => ['backoffice.producer.show', 'product.update'],
    'category' => ['backoffice.category.show', 'product.update'],
    'role' => ['backoffice.setting.role.show', 'role.update'],
    'permission' => ['backoffice.setting.permission.show', 'permission.update'],
    'user' => ['backoffice.setting.user.show', 'user.update'],
    'setting' => ['backoffice.setting.setting.show', 'setting.update'],
]);

test('opening a record that does not exist is a 404', function (string $route, string $permission) {
    $staff = User::factory()->create();
    Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    $staff->givePermissionTo($permission);

    $this->actingAs($staff)->get(route($route, 999999))->assertNotFound();
})->with('backoffice show routes');
