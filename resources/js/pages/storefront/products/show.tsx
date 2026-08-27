import { Head, router, usePage } from '@inertiajs/react';
import { MessageCircle, Minus, Plus, ShoppingBag } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import StorefrontLayout from '@/layouts/storefront-layout';
import { FormResponse } from '@/lib/constant';
import { formatRupiah, whatsappLink } from '@/lib/utils';
import { store as cartStore } from '@/routes/cart';
import type { SharedData } from '@/types';
import type { ProductDetail } from '@/types/product';

type Props = {
    product: ProductDetail;
};

export default function ProductShow({ product }: Props) {
    const [quantity, setQuantity] = useState(1);
    const { settings } = usePage<SharedData>().props;
    const isDisplayMode = settings.storefront_mode === 'display';
    const outOfStock = product.stock <= 0;
    const hasDiscount = !!product.discount_percent && product.discount_percent > 0;
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
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                        {product.name}
                    </h1>
                    {(product.producer_name || product.producer_region) && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {[product.producer_name, product.producer_region]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
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

                {product.description && (
                    <p className="text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                        {product.description}
                    </p>
                )}

                <p className="text-sm text-muted-foreground">
                    {outOfStock ? 'Stok habis' : `Stok tersedia: ${product.stock}`}
                </p>

                {isDisplayMode ? (
                    settings.storefront_whatsapp_number && (
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
                ) : (
                    !outOfStock && (
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
                            <Button onClick={addToCart} size="lg" className="gap-2">
                                <ShoppingBag className="size-4" />
                                Tambah ke Keranjang
                            </Button>
                        </div>
                    )
                )}
            </div>
        </div>
    );
}

ProductShow.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
