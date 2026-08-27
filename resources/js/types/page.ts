import type { Model } from './model';

export type Page = Model & {
    slug: string;
    title: string;
    body: string;
    meta_description: string | null;
    is_active: boolean;
};
