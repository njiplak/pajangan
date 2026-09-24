import { Head, Link } from '@inertiajs/react';
import { MapPin, UsersRound } from 'lucide-react';
import StorefrontLayout from '@/layouts/storefront-layout';
import { show } from '@/routes/producers';

type ProducerCard = {
    name: string;
    slug: string;
    region: string | null;
    photo: string | null;
    product_count: number;
};

export default function ProducersIndex({
    producers,
}: {
    producers: ProducerCard[];
}) {
    return (
        <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            <Head title="Produsen">
                <meta
                    name="description"
                    content="Kenali UMKM dan kelompok perajin Papua di balik setiap produk."
                />
            </Head>

            <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                Produsen Kami
            </h1>
            <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
                Setiap produk dibuat oleh UMKM dan kelompok perajin di tanah
                Papua. Kenali mereka dan cerita di balik karyanya.
            </p>

            {producers.length === 0 ? (
                <p className="mt-10 text-sm text-muted-foreground">
                    Belum ada produsen yang ditampilkan.
                </p>
            ) : (
                <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {producers.map((producer) => (
                        <Link
                            key={producer.slug}
                            href={show(producer.slug)}
                            className="group flex gap-4 rounded-xl border border-border p-4 transition-colors hover:bg-accent/50"
                        >
                            <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                                {producer.photo ? (
                                    <img
                                        src={producer.photo}
                                        alt=""
                                        className="size-full object-cover"
                                    />
                                ) : (
                                    <UsersRound className="size-6 text-muted-foreground" />
                                )}
                            </div>
                            <div className="min-w-0">
                                <p className="font-medium text-foreground group-hover:underline">
                                    {producer.name}
                                </p>
                                {producer.region && (
                                    <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                        <MapPin className="size-3" />
                                        {producer.region}
                                    </p>
                                )}
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {producer.product_count} produk
                                </p>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

ProducersIndex.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
