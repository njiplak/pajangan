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

/** A storefront customer as staff see it in the backoffice. */
export type Customer = {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    email_verified_at: string | null;
    created_at: string;
};

export type CustomerAddress = Omit<
    SavedAddress,
    'destination_area_id' | 'destination_area_name'
>;
