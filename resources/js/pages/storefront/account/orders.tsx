import { Head, Link } from '@inertiajs/react';
import { AccountNav } from '@/components/storefront/account-nav';
import { OrderSummaryCard } from '@/components/storefront/order-summary-card';
import type { AccountOrderSummary } from '@/components/storefront/order-summary-card';
import StorefrontLayout from '@/layouts/storefront-layout';
import { cn } from '@/lib/utils';
import { index as productsIndex } from '@/routes/products';

type PaginationLink = { url: string | null; label: string; active: boolean };

type Props = {
    orders: {
        data: AccountOrderSummary[];
        links: PaginationLink[];
    };
};

export default function AccountOrders({ orders }: Props) {
    return (
        <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
            <Head title="Pesanan Saya" />

            <h1 className="text-xl font-semibold text-foreground">
                Pesanan Saya
            </h1>

            <div className="mt-6">
                <AccountNav active="orders" />
            </div>

            <div className="mt-6 flex flex-col gap-3">
                {orders.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                        Belum ada pesanan.{' '}
                        <Link
                            href={productsIndex()}
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            Mulai belanja
                        </Link>
                    </div>
                ) : (
                    orders.data.map((order) => (
                        <OrderSummaryCard
                            key={order.order_number}
                            order={order}
                        />
                    ))
                )}
            </div>

            {orders.links.length > 3 && (
                <nav className="mt-8 flex flex-wrap justify-center gap-1">
                    {orders.links.map((link, index) =>
                        link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm',
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent',
                                )}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span
                                key={index}
                                className="px-3 py-1.5 text-sm text-muted-foreground/50"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ),
                    )}
                </nav>
            )}
        </div>
    );
}

AccountOrders.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
