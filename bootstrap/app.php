<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureCheckoutMode;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
        then: function () {
            $loadRoutes = function ($directory, $middleware) {
                if (!is_dir($directory)) {
                    return;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        Route::middleware($middleware)->group($file->getPathname());
                    }
                }
            };

            $loadRoutes(base_path('routes/web'), 'web');
            $loadRoutes(base_path('routes/api'), 'api');
        }
    )
    ->withSchedule(function (Schedule $schedule) {
        // Returns stock from orders that were never paid for. Needs
        // `php artisan schedule:run` on a cron, or nothing here runs.
        $schedule->command('orders:expire-unpaid')->hourly()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
            'checkout-mode' => EnsureCheckoutMode::class,
        ]);

        // Customers and staff sign in in different places; sending a
        // logged-out shopper to the staff login would strand them.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('akun', 'akun/*') ? '/masuk' : '/auth/login'
        );
        $middleware->redirectUsersTo('/backoffice');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
