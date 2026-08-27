import type { InertiaLinkProps } from '@inertiajs/react';
import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

const rupiahFormatter = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

export function formatRupiah(amount: number): string {
    return rupiahFormatter.format(amount);
}

export function whatsappLink(phone: string, message: string): string {
    const normalized = phone.replace(/\D/g, '').replace(/^0+/, '62');

    return `https://wa.me/${normalized}?text=${encodeURIComponent(message)}`;
}

/**
 * Laravel's CSRF middleware accepts the still-encrypted XSRF-TOKEN cookie
 * value verbatim in the X-XSRF-TOKEN header (it decrypts server-side) — the
 * same mechanism axios/Inertia use automatically for their own requests.
 * Needed for plain `fetch` POST/PUT calls that bypass Inertia's router.
 */
export function getCsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}
