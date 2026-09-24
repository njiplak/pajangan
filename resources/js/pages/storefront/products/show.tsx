import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Heart,
    MessageCircle,
    Minus,
    Package,
    Plus,
    ShoppingBag,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ProductReviews } from '@/components/storefront/product-reviews';
import { ShippingEstimate } from '@/components/storefront/shipping-estimate';
import StorefrontLayout from '@/layouts/storefront-layout';
import { FormResponse } from '@/lib/constant';
import { cn, formatRupiah, whatsappLink } from '@/lib/utils';
import { toggle as wishlistToggle } from '@/routes/account/wishlist';
import { store as cartStore } from '@/routes/cart';
import { login as customerLogin } from '@/routes/customer';
import { show as productShow } from '@/routes/products';
import type { SharedData } from '@/types';
import type { ProductDetail, ProductReview } from '@/types/product';

type Props = {
    product: ProductDetail;
    inWishlist: boolean;
    reviews: ProductReview[];
    myReview: {
        rating: number;
        body: string | null;
        is_visible: boolean;
    } | null;
    canReview: boolean;
};

export default function ProductShow({
    product,
    inWishlist,
    reviews,
    myReview,
    canReview,
}: Props) {
    const [quantity, setQuantity] = useState(1);
    const { settings, customer, googleLoginEnabled } =
        usePage<SharedData>().props;
    const isDisplayMode = settings.storefront_mode === 'display';
    const outOfStock = product.stock <= 0;
    const hasDiscount =
        !!product.discount_percent && product.discount_percent > 0;
    const savings = product.is_bundle
        ? product.components_total - product.effective_price
        : 0;
    const metaDescription = product.description
        ? product.description.length > 160
            ? `${product.description.slice(0, 157)}...`
            : product.description
        : (settings.seo_default_description ?? product.name);

    const decrement = () => setQuantity((q) => Math.max(1, q - 1));
    const increment = () => setQuantity((q) => Math.min(product.stock, q + 1));

    const addToCart = () => {
        router.post(
            cartStore().url,
            { product_id: product.id, quantity },
            { preserveScroll: true, ...FormResponse },
        );
    };

    return (
        <div className="mx-auto grid max-w-5xl gap-10 px-4 py-10 sm:px-6 lg:grid-cols-2">
            <Head title={product.name}>
                <meta name="description" content={metaDescription} />
                <meta property="og:title" content={product.name} />
                <meta property="og:description" content={metaDescription} />
                {product.images.length > 0 && (
                    <meta property="og:image" content={product.images[0]} />
                )}
            </Head>

            <div className="aspect-square overflow-hidden rounded-xl bg-muted">
                {product.images.length > 0 ? (
                    <img
                        src={product.images[0]}
                        alt={product.name}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-sm text-muted-foreground">
                        Tanpa gambar
                    </div>
                )}
            </div>

            <div className="flex flex-col gap-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                            {product.name}
                        </h1>
                        {(product.producer_name || product.producer_region) && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                {[
                                    product.producer_name,
                                    product.producer_region,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        )}
                    </div>
                    {customer ? (
                        <Button
                            variant="outline"
                            size="icon"
                            aria-pressed={inWishlist}
                            aria-label={
                                inWishlist
                                    ? 'Hapus dari wishlist'
                                    : 'Simpan ke wishlist'
                            }
                            onClick={() =>
                                router.post(
                                    wishlistToggle(product.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Heart
                                className={cn(
                                    'size-4',
                                    inWishlist &&
                                        'fill-destructive text-destructive',
                                )}
                            />
                        </Button>
                    ) : (
                        googleLoginEnabled && (
                            <Button
                                asChild
                                variant="outline"
                                size="icon"
                                aria-label="Masuk untuk menyimpan ke wishlist"
                            >
                                <Link href={customerLogin()}>
                                    <Heart className="size-4" />
                                </Link>
                            </Button>
                        )
                    )}
                </div>

                <div className="flex items-center gap-3">
                    <p className="text-2xl font-bold text-foreground">
                        {formatRupiah(product.effective_price)}
                    </p>
                    {hasDiscount && (
                        <>
                            <p className="text-base text-muted-foreground line-through">
                                {formatRupiah(product.price)}
                            </p>
                            <span className="rounded-full bg-destructive px-2 py-0.5 text-xs font-semibold text-destructive-foreground">
                                -{product.discount_percent}%
                            </span>
                        </>
                    )}
                </div>

                {savings > 0 && (
                    <p className="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                        Hemat {formatRupiah(savings)} dibanding beli satuan
                        <span className="ml-1 font-normal text-muted-foreground line-through">
                            {formatRupiah(product.components_total)}
                        </span>
                    </p>
                )}

                {product.description && (
                    <p className="text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                        {product.description}
                    </p>
                )}

                {product.is_bundle && product.bundle_items.length > 0 && (
                    <div className="rounded-lg border border-border p-4">
                        <p className="flex items-center gap-2 text-sm font-medium text-foreground">
                            <Package className="size-4" />
                            Isi paket ini
                        </p>
                        <ul className="mt-3 flex flex-col gap-2">
                            {product.bundle_items.map((item) => (
                                <li
                                    key={item.product_id}
                                    className="flex items-center gap-3 text-sm"
                                >
                                    {item.image ? (
                                        <img
                                            src={item.image}
                                            alt=""
                                            className="size-10 rounded-md object-cover"
                                        />
                                    ) : (
                                        <div className="size-10 rounded-md bg-muted" />
                                    )}
                                    <Link
                                        href={productShow(item.slug)}
                                        className="flex-1 text-foreground hover:underline"
                                    >
                                        {item.name}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        &times;{item.quantity}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <p className="text-sm text-muted-foreground">
                    {outOfStock
                        ? 'Stok habis'
                        : product.is_bundle
                          ? `Tersedia ${product.stock} paket`
                          : `Stok tersedia: ${product.stock}`}
                </p>

                {!isDisplayMode && !outOfStock && (
                    <ShippingEstimate
                        productId={product.id}
                        quantity={quantity}
                    />
                )}

                {isDisplayMode
                    ? settings.storefront_whatsapp_number && (
                          <Button asChild size="lg" className="gap-2">
                              <a
                                  href={whatsappLink(
                                      settings.storefront_whatsapp_number,
                                      `Halo, saya ingin memesan produk "${product.name}".`,
                                  )}
                                  target="_blank"
                                  rel="noopener noreferrer"
                              >
                                  <MessageCircle className="size-4" />
                                  Pesan via WhatsApp
                              </a>
                          </Button>
                      )
                    : !outOfStock && (
                          <div className="flex items-center gap-3">
                              <div className="flex items-center rounded-md border border-input">
                                  <Button
                                      type="button"
                                      variant="ghost"
                                      size="icon"
                                      onClick={decrement}
                                      disabled={quantity <= 1}
                                  >
                                      <Minus className="size-4" />
                                  </Button>
                                  <span className="w-10 text-center text-sm font-medium">
                                      {quantity}
                                  </span>
                                  <Button
                                      type="button"
                                      variant="ghost"
                                      size="icon"
                                      onClick={increment}
                                      disabled={quantity >= product.stock}
                                  >
                                      <Plus className="size-4" />
                                  </Button>
                              </div>
                              <Button
                                  onClick={addToCart}
                                  size="lg"
                                  className="gap-2"
                              >
                                  <ShoppingBag className="size-4" />
                                  Tambah ke Keranjang
                              </Button>
                          </div>
                      )}
            </div>

            <ProductReviews
                productId={product.id}
                ratingAvg={product.rating_avg ?? null}
                ratingCount={product.rating_count ?? 0}
                reviews={reviews}
                myReview={myReview}
                canReview={canReview}
            />
        </div>
    );
}

ProductShow.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
