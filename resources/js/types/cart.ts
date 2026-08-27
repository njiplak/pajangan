export type CartItem = {
    product_id: number;
    name: string;
    slug: string;
    price: number;
    discount_percent: number | null;
    effective_price: number;
    quantity: number;
    stock: number;
    subtotal: number;
    image: string | null;
};

export type CartSummary = {
    items: CartItem[];
    subtotal: number;
    total: number;
};
