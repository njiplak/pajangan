import { Head } from '@inertiajs/react';
import StorefrontLayout from '@/layouts/storefront-layout';

type Props = {
    page: {
        title: string;
        body: string;
        meta_description: string | null;
    };
};

export default function About({ page }: Props) {
    return (
        <div className="mx-auto max-w-3xl px-4 py-14 sm:px-6">
            <Head title={page.title}>
                {page.meta_description && (
                    <meta name="description" content={page.meta_description} />
                )}
            </Head>

            <p className="text-xs font-medium tracking-[0.2em] text-primary uppercase">
                {page.title}
            </p>

            <div className="mt-6 flex flex-col gap-4 text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                {page.body}
            </div>
        </div>
    );
}

About.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
