import type { MediaItem } from './media';
import type { Model } from './model';

export type Producer = Model & {
    name: string;
    slug: string;
    region: string | null;
    story: string | null;
    is_active: boolean;
    images: MediaItem[];
};

export type ProducerOption = {
    id: number;
    name: string;
    region: string | null;
};
