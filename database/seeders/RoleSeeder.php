<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = ['setting', 'role', 'permission', 'user', 'product', 'order', 'page', 'banner'];
        $actions = ['view', 'create', 'update', 'delete'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                // Orders are never created/deleted through the backoffice UI,
                // only viewed and status-updated (see routes/web/backoffice.php).
                if ($module === 'order' && ! in_array($action, ['view', 'update'], true)) {
                    continue;
                }

                Permission::firstOrCreate([
                    'name' => "{$module}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }

        $superAdmin = Role::firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'web',
        ]);

        $superAdmin->syncPermissions(Permission::where('guard_name', 'web')->get());

        User::where('email', 'test@example.com')->first()?->assignRole($superAdmin);
    }
}
