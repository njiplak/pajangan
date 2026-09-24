import type { OrderStatus } from '@/types/order';

/** Short labels for order lists; the order page has its own headlines. */
export const ORDER_STATUS_LABEL: Record<OrderStatus, string> = {
    pending: 'Menunggu Pembayaran',
    paid: 'Dibayar',
    processing: 'Disiapkan',
    shipped: 'Dikirim',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};
