import { Head, Link } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import { home } from '@/routes';
import { show as trackOrder } from '@/routes/track';
import StorefrontLayout from '@/layouts/storefront-layout';

export default function Closed() {
    return (
        <div className="mx-auto flex max-w-md flex-col items-center gap-4 px-4 py-20 text-center sm:px-6">
            <Head title="Belum Bisa Checkout" />

            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <ShoppingBag className="size-5" />
            </div>

            <h1 className="text-lg font-semibold text-foreground">
                Belum Bisa Checkout
            </h1>

            <p className="text-sm leading-relaxed text-muted-foreground">
                Toko sedang tidak menerima pesanan baru saat ini. Anda masih
                bisa melihat katalog produk, dan pesanan yang sudah ada tetap
                bisa dilacak.
            </p>

            <div className="mt-2 flex flex-wrap items-center justify-center gap-3 text-sm font-medium">
                <Link
                    href={home()}
                    className="rounded-md bg-primary px-4 py-2 text-primary-foreground transition-colors hover:bg-primary/90"
                >
                    Lihat Katalog
                </Link>
                <Link
                    href={trackOrder()}
                    className="rounded-md border border-border px-4 py-2 text-foreground transition-colors hover:bg-accent"
                >
                    Lacak Pesanan
                </Link>
            </div>
        </div>
    );
}

Closed.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
