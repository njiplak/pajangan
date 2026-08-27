import { Head, Link, router } from '@inertiajs/react';
import { Minus, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import StorefrontLayout from '@/layouts/storefront-layout';
import { formatRupiah } from '@/lib/utils';
import { destroy as cartDestroy, update as cartUpdate } from '@/routes/cart';
import { index as checkoutIndex } from '@/routes/checkout';
import { index as productsIndex } from '@/routes/products';
import type { CartSummary } from '@/types/cart';

type Props = {
    cart: CartSummary;
};

export default function CartIndex({ cart }: Props) {
    const changeQuantity = (productId: number, quantity: number) => {
        router.put(
            cartUpdate(productId).url,
            { quantity },
            { preserveScroll: true, preserveState: true },
        );
    };

    const removeItem = (productId: number) => {
        router.delete(cartDestroy(productId).url, { preserveScroll: true });
    };

    if (cart.items.length === 0) {
        return (
            <div className="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6">
                <p className="text-lg font-medium text-foreground">
                    Keranjang Anda kosong
                </p>
                <p className="mt-2 text-sm text-muted-foreground">
                    Yuk, jelajahi produk UMKM Papua terlebih dahulu.
                </p>
                <Button asChild className="mt-6">
                    <Link href={productsIndex()}>Jelajahi Produk</Link>
                </Button>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-4xl px-4 py-10 sm:px-6">
            <Head title="Keranjang Belanja">
                <meta name="robots" content="noindex,nofollow" />
            </Head>

            <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                Keranjang Belanja
            </h1>

            <div className="mt-6 divide-y divide-border rounded-xl border border-border">
                {cart.items.map((item) => (
                    <div key={item.product_id} className="flex items-center gap-4 p-4">
                        <div className="size-16 shrink-0 overflow-hidden rounded-md bg-muted">
                            {item.image && (
                                <img
                                    src={item.image}
                                    alt={item.name}
                                    className="h-full w-full object-cover"
                                />
                            )}
                        </div>
                        <div className="flex-1">
                            <p className="text-sm font-medium text-foreground">{item.name}</p>
                            <div className="flex items-center gap-2">
                                <p className="text-sm text-muted-foreground">
                                    {formatRupiah(item.effective_price)}
                                </p>
                                {!!item.discount_percent && item.discount_percent > 0 && (
                                    <p className="text-xs text-muted-foreground line-through">
                                        {formatRupiah(item.price)}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center rounded-md border border-input">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={item.quantity <= 1}
                                onClick={() => changeQuantity(item.product_id, item.quantity - 1)}
                            >
                                <Minus className="size-4" />
                            </Button>
                            <span className="w-8 text-center text-sm font-medium">
                                {item.quantity}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={item.quantity >= item.stock}
                                onClick={() => changeQuantity(item.product_id, item.quantity + 1)}
                            >
                                <Plus className="size-4" />
                            </Button>
                        </div>
                        <p className="w-28 text-right text-sm font-semibold text-foreground">
                            {formatRupiah(item.subtotal)}
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => removeItem(item.product_id)}
                        >
                            <Trash2 className="size-4 text-destructive" />
                        </Button>
                    </div>
                ))}
            </div>

            <div className="mt-6 flex flex-col items-end gap-2">
                <p className="text-sm text-muted-foreground">
                    Ongkos kirim akan dikonfirmasi oleh admin setelah pesanan dibuat.
                </p>
                <p className="text-lg font-semibold text-foreground">
                    Total: {formatRupiah(cart.total)}
                </p>
                <Button asChild size="lg">
                    <Link href={checkoutIndex()}>Lanjut ke Checkout</Link>
                </Button>
            </div>
        </div>
    );
}

CartIndex.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
