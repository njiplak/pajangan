import type { Model } from './model';

export type Category = Model & {
    name: string;
    slug: string;
    sort_order: number;
};

export type CategoryOption = {
    id: number;
    name: string;
};
