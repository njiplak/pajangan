import type { StorefrontSettings } from '@/types';

/**
 * Mirrors App\Service\Shipping\FreeShipping for display only — the server
 * computes the charged amount; this just lets the page show it early.
 */
export function freeShippingRule(settings: StorefrontSettings) {
    const threshold = Math.max(
        0,
        Number(settings.free_shipping_min_subtotal ?? 0) || 0,
    );
    const cap = Math.max(
        0,
        Number(settings.free_shipping_max_subsidy ?? 0) || 0,
    );

    return {
        enabled: threshold > 0,
        threshold,
        cap,
        discountFor(subtotal: number, shippingCost: number): number {
            if (threshold <= 0 || subtotal < threshold || shippingCost <= 0) {
                return 0;
            }

            return cap > 0 ? Math.min(shippingCost, cap) : shippingCost;
        },
    };
}
