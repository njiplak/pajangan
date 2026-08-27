import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { ProductCard } from '@/components/storefront/product-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StorefrontLayout from '@/layouts/storefront-layout';
import { index as productsIndex } from '@/routes/products';
import type { ProductSummary } from '@/types/product';

type PaginationLink = { url: string | null; label: string; active: boolean };

type Props = {
    products: {
        data: ProductSummary[];
        links: PaginationLink[];
    };
    search: string;
};

export default function ProductsIndex({ products, search }: Props) {
    const [query, setQuery] = useState(search);

    const onSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            productsIndex().url,
            { q: query },
            { preserveState: true, replace: true },
        );
    };

    return (
        <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            <Head title="Produk">
                <meta
                    name="description"
                    content="Jelajahi produk asli UMKM Papua — kopi, kerajinan, hingga hasil bumi khas Papua."
                />
            </Head>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                    Semua Produk
                </h1>
                <form onSubmit={onSearch} className="flex w-full max-w-sm items-center gap-2">
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Cari produk..."
                    />
                    <Button type="submit" variant="outline" size="icon">
                        <Search className="size-4" />
                    </Button>
                </form>
            </div>

            {products.data.length === 0 ? (
                <p className="mt-10 text-sm text-muted-foreground">
                    Produk tidak ditemukan.
                </p>
            ) : (
                <div className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {products.data.map((product) => (
                        <ProductCard key={product.id} product={product} />
                    ))}
                </div>
            )}

            {products.links.length > 3 && (
                <div className="mt-10 flex flex-wrap items-center justify-center gap-1">
                    {products.links.map((link, index) => (
                        <Link
                            key={index}
                            href={link.url ?? '#'}
                            preserveScroll
                            className={
                                'rounded-md px-3 py-1.5 text-sm ' +
                                (link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : link.url
                                      ? 'text-muted-foreground hover:bg-accent'
                                      : 'pointer-events-none text-muted-foreground/40')
                            }
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

ProductsIndex.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
