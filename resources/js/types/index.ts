export type * from './auth';
export type * from './navigation';
export type * from './ui';

import type { Auth } from './auth';
import type { ShippingDestination } from './shipping';

export type StorefrontSettings = {
    storefront_mode: 'checkout' | 'display';
    storefront_whatsapp_number: string;
    storefront_title?: string;
    storefront_subtitle?: string;
    storefront_footer_description?: string;
    storefront_footer_copyright?: string;
    storefront_hero_title?: string;
    storefront_hero_subtitle?: string;
    seo_default_description?: string;
    seo_og_image_url?: string;
    free_shipping_min_subtotal?: string;
    free_shipping_max_subsidy?: string;
} & Record<string, string>;

export type SharedData = {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    cart: {
        count: number;
    };
    /** Null until the visitor picks one; nothing is inferred. */
    shippingDestination: ShippingDestination | null;
    /** The signed-in shopper, or null. Never a staff user. */
    customer: {
        name: string;
        email: string;
        avatar_url: string | null;
    } | null;
    googleLoginEnabled: boolean;
    flash: {
        status: string | null;
    };
    settings: StorefrontSettings;
    [key: string]: unknown;
};
