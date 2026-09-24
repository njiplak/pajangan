export type ShippingDestination = {
    id: string;
    name: string;
    postal_code: string | null;
    district: string | null;
    city: string | null;
    province: string | null;
};

export type ShippingEstimate = {
    courier_name: string;
    courier_service_name: string;
    price: number;
    duration: string | null;
};
