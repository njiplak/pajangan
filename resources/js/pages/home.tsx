import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { ProductCard } from '@/components/storefront/product-card';
import { Button } from '@/components/ui/button';
import StorefrontLayout from '@/layouts/storefront-layout';
import { about } from '@/routes';
import { index as productsIndex } from '@/routes/products';
import type { SharedData } from '@/types';
import type { HomeBanner } from '@/types/banner';
import type { ProductSummary } from '@/types/product';

type Props = {
    featuredProducts: ProductSummary[];
    banners: HomeBanner[];
};

export default function Home({ featuredProducts, banners }: Props) {
    const { settings } = usePage<SharedData>().props;
    const heroEyebrow = settings.storefront_title ?? 'UMKM Papua.id';
    const heroTitle =
        settings.storefront_hero_title ?? 'Perjuangan Ekonomi Bermartabat untuk UMKM Papua';
    const heroSubtitle =
        settings.storefront_hero_subtitle ??
        'Jembatan yang menghubungkan produk asli Papua dengan pasar nasional dan global, tanpa kehilangan identitas budaya.';
    const metaDescription = settings.seo_default_description ?? heroSubtitle;

    return (
        <>
            <Head title="Beranda">
                <meta name="description" content={metaDescription} />
                <meta property="og:title" content={heroTitle} />
                <meta property="og:description" content={metaDescription} />
                {settings.seo_og_image_url && (
                    <meta property="og:image" content={settings.seo_og_image_url} />
                )}
            </Head>

            <section className="border-b border-border bg-gradient-to-b from-primary/5 to-transparent">
                <div className="mx-auto flex max-w-6xl flex-col items-center px-4 py-20 text-center sm:px-6">
                    <p className="text-xs font-medium tracking-[0.2em] text-primary uppercase">
                        {heroEyebrow}
                    </p>
                    <h1 className="mt-4 max-w-2xl text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
                        {heroTitle}
                    </h1>
                    <p className="mt-6 max-w-xl text-base leading-relaxed text-muted-foreground">
                        {heroSubtitle}
                    </p>
                    <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <Button asChild size="lg">
                            <Link href={productsIndex()}>
                                Jelajahi Produk
                                <ArrowRight className="size-4" />
                            </Link>
                        </Button>
                        <Button asChild size="lg" variant="outline">
                            <Link href={about()}>Kenali Kisah Kami</Link>
                        </Button>
                    </div>
                </div>
            </section>

            {banners.length > 0 && (
                <section className="mx-auto max-w-6xl px-4 pt-10 sm:px-6">
                    <div className="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2">
                        {banners.map((banner) => (
                            <a
                                key={banner.id}
                                href={banner.link ?? '#'}
                                className="block aspect-[21/9] w-full shrink-0 snap-start overflow-hidden rounded-xl bg-muted sm:w-[calc(100%-2.5rem)]"
                            >
                                {banner.image ? (
                                    <img
                                        src={banner.image}
                                        alt={banner.title}
                                        className="h-full w-full object-cover"
                                    />
                                ) : (
                                    <div className="flex h-full w-full items-center justify-center text-sm text-muted-foreground">
                                        {banner.title}
                                    </div>
                                )}
                            </a>
                        ))}
                    </div>
                </section>
            )}

            <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-2xl font-semibold tracking-tight text-foreground">
                        Produk Pilihan
                    </h2>
                    <Link
                        href={productsIndex()}
                        className="text-sm font-medium text-primary hover:underline"
                    >
                        Lihat semua
                    </Link>
                </div>

                {featuredProducts.length === 0 ? (
                    <p className="mt-8 text-sm text-muted-foreground">
                        Belum ada produk yang tersedia saat ini.
                    </p>
                ) : (
                    <div className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {featuredProducts.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                    </div>
                )}
            </section>
        </>
    );
}

Home.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
