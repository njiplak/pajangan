import { Head, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    CreditCard,
    PackageCheck,
    RotateCcw,
    Truck,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import StorefrontLayout from '@/layouts/storefront-layout';
import { cn, formatRupiah } from '@/lib/utils';
import type { SharedData } from '@/types';
import type {
    OrderStatus,
    OrderTimelineStep,
    StorefrontOrder,
} from '@/types/order';

type Props = {
    order: StorefrontOrder;
    timeline: OrderTimelineStep[];
    /** Present only while the order can still be paid for. */
    payUrl: string | null;
    /** Present once the order is past paying. */
    reorderUrl: string | null;
};

const HEADLINE: Record<
    OrderStatus,
    { title: string; body: string; icon: LucideIcon; tone: string }
> = {
    pending: {
        title: 'Menunggu Pembayaran',
        body: 'Pesanan Anda sudah tercatat. Selesaikan pembayaran agar kami bisa menyiapkannya.',
        icon: Clock,
        tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    },
    paid: {
        title: 'Pembayaran Diterima',
        body: 'Terima kasih! Pesanan Anda akan segera kami siapkan.',
        icon: CheckCircle2,
        tone: 'bg-primary/10 text-primary',
    },
    processing: {
        title: 'Pesanan Sedang Disiapkan',
        body: 'Pesanan Anda sedang dikemas dan menunggu dijemput kurir.',
        icon: CheckCircle2,
        tone: 'bg-primary/10 text-primary',
    },
    shipped: {
        title: 'Pesanan Dalam Perjalanan',
        body: 'Pesanan Anda sudah bersama kurir.',
        icon: Truck,
        tone: 'bg-primary/10 text-primary',
    },
    completed: {
        title: 'Pesanan Telah Sampai',
        body: 'Semoga Anda menyukainya. Terima kasih sudah mendukung UMKM Papua.',
        icon: PackageCheck,
        tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    cancelled: {
        title: 'Pesanan Dibatalkan',
        body: 'Pesanan ini dibatalkan dan tidak ada biaya yang dikenakan.',
        icon: XCircle,
        tone: 'bg-destructive/10 text-destructive',
    },
};

export default function OrderShow({
    order,
    timeline,
    payUrl,
    reorderUrl,
}: Props) {
    const { settings } = usePage<SharedData>().props;
    const headline = HEADLINE[order.status] ?? HEADLINE.pending;
    const Icon = headline.icon;
    const cancelled = order.status === 'cancelled';
    // Staff can cancel an order that was already paid; "no charge" would
    // then be untrue, so the refund case gets its own wording.
    const body =
        cancelled && order.paid_at
            ? 'Pesanan ini dibatalkan. Karena pembayaran sudah kami terima, tim kami akan menghubungi Anda untuk pengembalian dana.'
            : headline.body;

    return (
        <div className="mx-auto max-w-2xl px-4 py-14 sm:px-6">
            <Head title="Status Pesanan">
                <meta name="robots" content="noindex,nofollow" />
            </Head>

            <div className="flex flex-col items-center text-center">
                <div
                    className={cn(
                        'flex size-14 items-center justify-center rounded-full',
                        headline.tone,
                    )}
                >
                    <Icon className="size-7" />
                </div>
                <h1 className="mt-4 text-2xl font-semibold tracking-tight text-foreground">
                    {headline.title}
                </h1>
                <p className="mt-2 max-w-md text-sm text-muted-foreground">
                    {body}
                </p>
                <p className="mt-3 text-sm text-muted-foreground">
                    Nomor Pesanan:{' '}
                    <span className="font-medium text-foreground">
                        {order.order_number}
                    </span>
                </p>

                {payUrl && (
                    <Button
                        size="lg"
                        className="mt-6 gap-2"
                        onClick={() => router.post(payUrl)}
                    >
                        <CreditCard className="size-4" />
                        Bayar Sekarang
                    </Button>
                )}

                {reorderUrl && settings.storefront_mode !== 'display' && (
                    <Button
                        variant="outline"
                        className="mt-6 gap-2"
                        onClick={() => router.post(reorderUrl)}
                    >
                        <RotateCcw className="size-4" />
                        Beli Lagi
                    </Button>
                )}
            </div>

            <ol className="mt-10 flex flex-col gap-3 rounded-xl border border-border p-5">
                {timeline.map((step) => (
                    <li
                        key={step.key}
                        className="flex items-center gap-3 text-sm"
                    >
                        <span
                            className={cn(
                                'size-2.5 shrink-0 rounded-full',
                                step.done
                                    ? step.key === 'cancelled'
                                        ? 'bg-destructive'
                                        : 'bg-primary'
                                    : 'bg-muted-foreground/30',
                            )}
                        />
                        <span
                            className={
                                step.done
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            }
                        >
                            {step.label}
                        </span>
                    </li>
                ))}
            </ol>

            {!cancelled && (order.courier_name || order.tracking_number) && (
                <div className="mt-6 rounded-xl border border-border p-5 text-sm">
                    <h2 className="font-semibold text-foreground">
                        Pengiriman
                    </h2>
                    <p className="mt-2 text-muted-foreground">
                        Kurir:{' '}
                        <span className="text-foreground">
                            {order.courier_name ?? '-'}
                            {order.courier_service
                                ? ` (${order.courier_service})`
                                : ''}
                        </span>
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        Nomor resi:{' '}
                        <span className="font-medium text-foreground">
                            {order.tracking_number ??
                                'Muncul setelah paket diserahkan ke kurir'}
                        </span>
                    </p>
                </div>
            )}

            <div className="mt-6 rounded-xl border border-border p-5">
                <h2 className="font-semibold text-foreground">
                    Detail Pesanan
                </h2>
                <div className="mt-4 flex flex-col gap-3">
                    {order.items.map((item) => (
                        <div
                            key={item.id}
                            className="flex justify-between gap-4 text-sm"
                        >
                            <span className="text-muted-foreground">
                                {item.product_name} × {item.quantity}
                            </span>
                            <span className="font-medium text-foreground">
                                {formatRupiah(item.subtotal)}
                            </span>
                        </div>
                    ))}
                </div>

                <dl className="mt-4 flex flex-col gap-2 border-t border-border pt-4 text-sm">
                    <div className="flex justify-between">
                        <dt className="text-muted-foreground">Subtotal</dt>
                        <dd className="text-foreground">
                            {formatRupiah(order.subtotal)}
                        </dd>
                    </div>
                    <div className="flex justify-between">
                        <dt className="text-muted-foreground">
                            Ongkir
                            {order.courier_name
                                ? ` (${order.courier_name})`
                                : ''}
                        </dt>
                        <dd className="text-foreground">
                            {formatRupiah(order.shipping_cost)}
                        </dd>
                    </div>
                    {order.admin_fee > 0 && (
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">
                                Biaya admin
                            </dt>
                            <dd className="text-foreground">
                                {formatRupiah(order.admin_fee)}
                            </dd>
                        </div>
                    )}
                    <div className="flex justify-between border-t border-border pt-2 font-semibold text-foreground">
                        <dt>Total</dt>
                        <dd>{formatRupiah(order.total)}</dd>
                    </div>
                </dl>
            </div>

            <div className="mt-6 rounded-xl border border-border p-5 text-sm">
                <h2 className="font-semibold text-foreground">Dikirim ke</h2>
                <p className="mt-2 text-muted-foreground">
                    {order.customer_name} · {order.customer_phone}
                </p>
                <p className="mt-1 text-muted-foreground">
                    {order.shipping_address}, {order.shipping_city},{' '}
                    {order.shipping_province}
                    {order.shipping_postal_code
                        ? ` ${order.shipping_postal_code}`
                        : ''}
                </p>
            </div>

            <p className="mt-6 text-center text-xs text-muted-foreground">
                Tautan halaman ini juga kami kirim ke {order.customer_email}.
                Simpan email tersebut untuk memantau pesanan Anda kapan saja.
            </p>
        </div>
    );
}

OrderShow.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
