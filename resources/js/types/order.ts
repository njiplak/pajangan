import type { Model } from './model';

export type OrderStatus =
    | 'pending'
    | 'paid'
    | 'processing'
    | 'shipped'
    | 'completed'
    | 'cancelled';

export type OrderItem = Model & {
    order_id: number;
    product_id: number | null;
    product_name: string;
    unit_price: number;
    quantity: number;
    subtotal: number;
};

export type Order = Model & {
    order_number: string;
    customer_name: string;
    customer_email: string;
    customer_phone: string;
    shipping_address: string;
    shipping_city: string;
    shipping_province: string;
    shipping_postal_code: string | null;
    shipping_cost: number | null;
    shipping_area_id: string | null;
    shipping_area_name: string | null;
    courier_code: string | null;
    courier_name: string | null;
    courier_service: string | null;
    courier_etd: string | null;
    tracking_number: string | null;
    biteship_order_id: string | null;
    notes: string | null;
    status: OrderStatus;
    subtotal: number;
    total: number;
    items: OrderItem[];
};

export type ShippingArea = {
    id: string;
    name: string;
    postal_code: string | null;
};

export type ShippingCollectionMethod = 'pickup' | 'drop_off';

export type ShippingRateOption = {
    courier_code: string;
    courier_name: string;
    courier_service_code: string;
    courier_service_name: string;
    price: number;
    duration: string | null;
    collection_methods: ShippingCollectionMethod[];
};
