import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, MapPin, UsersRound } from 'lucide-react';
import { ProductCard } from '@/components/storefront/product-card';
import StorefrontLayout from '@/layouts/storefront-layout';
import { index } from '@/routes/producers';
import type { ProductSummary } from '@/types/product';

type Props = {
    producer: {
        name: string;
        region: string | null;
        story: string | null;
        photo: string | null;
    };
    products: ProductSummary[];
};

export default function ProducerShow({ producer, products }: Props) {
    const description = producer.story
        ? producer.story.slice(0, 157) +
          (producer.story.length > 157 ? '...' : '')
        : `Produk dari ${producer.name}${producer.region ? `, ${producer.region}` : ''}.`;

    return (
        <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            <Head title={producer.name}>
                <meta name="description" content={description} />
                <meta property="og:title" content={producer.name} />
                {producer.photo && (
                    <meta property="og:image" content={producer.photo} />
                )}
            </Head>

            <Link
                href={index()}
                className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="size-4" />
                Semua produsen
            </Link>

            <div className="mt-6 grid gap-8 md:grid-cols-[240px_1fr]">
                <div className="aspect-square overflow-hidden rounded-xl bg-muted">
                    {producer.photo ? (
                        <img
                            src={producer.photo}
                            alt={producer.name}
                            className="size-full object-cover"
                        />
                    ) : (
                        <div className="flex size-full items-center justify-center">
                            <UsersRound className="size-10 text-muted-foreground" />
                        </div>
                    )}
                </div>
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        {producer.name}
                    </h1>
                    {producer.region && (
                        <p className="mt-2 flex items-center gap-1 text-sm text-muted-foreground">
                            <MapPin className="size-4" />
                            {producer.region}
                        </p>
                    )}
                    {producer.story && (
                        <p className="mt-4 text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                            {producer.story}
                        </p>
                    )}
                </div>
            </div>

            <h2 className="mt-12 text-lg font-semibold text-foreground">
                Produk dari {producer.name}
            </h2>
            {products.length === 0 ? (
                <p className="mt-4 text-sm text-muted-foreground">
                    Belum ada produk yang dijual saat ini.
                </p>
            ) : (
                <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {products.map((product) => (
                        <ProductCard key={product.id} product={product} />
                    ))}
                </div>
            )}
        </div>
    );
}

ProducerShow.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
