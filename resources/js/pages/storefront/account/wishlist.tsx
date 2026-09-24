import { Head, Link, router } from '@inertiajs/react';
import { AccountNav } from '@/components/storefront/account-nav';
import { ProductCard } from '@/components/storefront/product-card';
import StorefrontLayout from '@/layouts/storefront-layout';
import { toggle } from '@/routes/account/wishlist';
import { index as productsIndex } from '@/routes/products';
import type { ProductSummary } from '@/types/product';

type Props = {
    products: ProductSummary[];
};

export default function Wishlist({ products }: Props) {
    return (
        <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
            <Head title="Wishlist" />

            <h1 className="text-xl font-semibold text-foreground">Wishlist</h1>

            <div className="mt-6">
                <AccountNav active="wishlist" />
            </div>

            {products.length === 0 ? (
                <div className="mt-6 rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    Belum ada produk favorit.{' '}
                    <Link
                        href={productsIndex()}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        Jelajahi produk
                    </Link>
                </div>
            ) : (
                <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
                    {products.map((product) => (
                        <div key={product.id} className="flex flex-col gap-1.5">
                            <ProductCard product={product} />
                            {/* Below the card: its top corners already carry
                                the discount and bundle badges. */}
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(
                                        toggle(product.id).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="self-start text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                            >
                                Hapus dari wishlist
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

Wishlist.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
