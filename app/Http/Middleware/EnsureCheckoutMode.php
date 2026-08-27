<?php

namespace App\Http\Middleware;

use App\Contract\Setting\SettingContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCheckoutMode
{
    public function __construct(private readonly SettingContract $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mode = $this->settings->allAsKeyValue()['storefront_mode'] ?? 'checkout';

        if ($mode === 'display') {
            abort(404);
        }

        return $next($request);
    }
}
