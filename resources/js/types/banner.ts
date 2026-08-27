import type { MediaItem } from './media';
import type { Model } from './model';

export type Banner = Model & {
    title: string;
    link: string | null;
    is_active: boolean;
    sort_order: number;
    images: MediaItem[];
};

export type HomeBanner = {
    id: number;
    title: string;
    link: string | null;
    image: string | null;
};
