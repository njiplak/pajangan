export type SavedAddress = {
    id: number;
    label: string | null;
    recipient_name: string;
    phone: string;
    address: string;
    city: string;
    province: string;
    postal_code: string | null;
    destination_area_id: string;
    destination_area_name: string;
    is_default: boolean;
};
