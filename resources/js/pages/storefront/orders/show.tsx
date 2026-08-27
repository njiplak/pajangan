import { Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import StorefrontLayout from '@/layouts/storefront-layout';
import { formatRupiah } from '@/lib/utils';
import type { Order } from '@/types/order';

type Props = {
    order: Order;
};

const STATUS_LABEL: Record<string, string> = {
    pending: 'Menunggu Konfirmasi Pembayaran',
    paid: 'Pembayaran Diterima',
    processing: 'Sedang Diproses',
    shipped: 'Sedang Dikirim',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

export default function OrderShow({ order }: Props) {
    return (
        <div className="mx-auto max-w-2xl px-4 py-14 sm:px-6">
            <Head title="Status Pesanan">
                <meta name="robots" content="noindex,nofollow" />
            </Head>

            <div className="flex flex-col items-center text-center">
                <div className="flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <CheckCircle2 className="size-7" />
                </div>
                <h1 className="mt-4 text-2xl font-semibold tracking-tight text-foreground">
                    Pesanan Berhasil Dibuat
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Nomor Pesanan:{' '}
                    <span className="font-medium text-foreground">
                        {order.order_number}
                    </span>
                </p>
                <Badge variant="secondary" className="mt-3">
                    {STATUS_LABEL[order.status] ?? order.status}
                </Badge>
            </div>

            <div className="mt-8 rounded-xl border border-border p-5">
                <h2 className="font-semibold text-foreground">Detail Pesanan</h2>
                <div className="mt-4 flex flex-col gap-3">
                    {order.items.map((item) => (
                        <div key={item.id} className="flex justify-between text-sm">
                            <span className="text-muted-foreground">
                                {item.product_name} × {item.quantity}
                            </span>
                            <span className="font-medium text-foreground">
                                {formatRupiah(item.subtotal)}
                            </span>
                        </div>
                    ))}
                </div>
                <div className="mt-4 flex justify-between border-t border-border pt-4 text-sm font-semibold text-foreground">
                    <span>Total</span>
                    <span>{formatRupiah(order.total)}</span>
                </div>
            </div>

            <div className="mt-6 rounded-xl border border-border p-5 text-sm">
                <h2 className="font-semibold text-foreground">Dikirim ke</h2>
                <p className="mt-2 text-muted-foreground">
                    {order.customer_name} · {order.customer_phone}
                </p>
                <p className="mt-1 text-muted-foreground">
                    {order.shipping_address}, {order.shipping_city},{' '}
                    {order.shipping_province}
                    {order.shipping_postal_code ? ` ${order.shipping_postal_code}` : ''}
                </p>
            </div>

            <p className="mt-6 text-center text-xs text-muted-foreground">
                Simpan tautan halaman ini sebagai bukti pesanan Anda. Admin akan
                menghubungi Anda melalui {order.customer_email} atau{' '}
                {order.customer_phone} untuk konfirmasi pembayaran dan pengiriman.
            </p>
        </div>
    );
}

OrderShow.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
