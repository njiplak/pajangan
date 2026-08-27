import type { MediaItem } from './media';
import type { Model } from './model';

export type Product = Model & {
    name: string;
    slug: string;
    description: string | null;
    price: number;
    discount_percent: number | null;
    stock: number;
    weight_gram: number;
    producer_name: string | null;
    producer_region: string | null;
    is_active: boolean;
    images: MediaItem[];
};

export type ProductSummary = {
    id: number;
    name: string;
    slug: string;
    price: number;
    discount_percent: number | null;
    effective_price: number;
    stock: number;
    producer_name: string | null;
    producer_region: string | null;
    image: string | null;
};

export type ProductDetail = ProductSummary & {
    description: string | null;
    images: string[];
};
