export type * from './auth';
export type * from './navigation';
export type * from './ui';

import type { Auth } from './auth';

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
} & Record<string, string>;

export type SharedData = {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    cart: {
        count: number;
    };
    settings: StorefrontSettings;
    [key: string]: unknown;
};
