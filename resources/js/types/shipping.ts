export type ShippingDestination = {
    id: string;
    name: string;
};

export type ShippingEstimate = {
    courier_name: string;
    courier_service_name: string;
    price: number;
    duration: string | null;
};
