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
    is_bundle: boolean;
    bundle_items: BundleItemInput[];
    images: MediaItem[];
};

export type BundleItemInput = {
    product_id: number;
    quantity: number;
};

/** A product that may be placed inside a bundle, as offered by the form. */
export type ComponentOption = {
    id: number;
    name: string;
    effective_price: number;
    stock: number;
    weight_gram: number;
};

export type BundleContentItem = {
    product_id: number;
    name: string;
    slug: string;
    quantity: number;
    effective_price: number;
    image: string | null;
};

export type ProductSummary = {
    id: number;
    name: string;
    slug: string;
    price: number;
    discount_percent: number | null;
    effective_price: number;
    stock: number;
    is_bundle: boolean;
    producer_name: string | null;
    producer_region: string | null;
    image: string | null;
};

export type ProductDetail = ProductSummary & {
    description: string | null;
    images: string[];
    bundle_items: BundleContentItem[];
    components_total: number;
};
