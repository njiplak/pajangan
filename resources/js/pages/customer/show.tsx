import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { ORDER_STATUS_LABEL } from '@/lib/order-status';
import { formatRupiah } from '@/lib/utils';
import { show as showOrder } from '@/routes/backoffice/order';
import type { Customer, CustomerAddress } from '@/types/customer';
import type { OrderStatus } from '@/types/order';

type CustomerOrder = {
    id: number;
    order_number: string;
    status: OrderStatus;
    total: number;
    paid_at: string | null;
    created_at: string;
};

type Props = {
    customer: Customer;
    addresses: CustomerAddress[];
    orders: CustomerOrder[];
    totalSpent: number;
};

export default function CustomerShow({
    customer,
    addresses,
    orders,
    totalSpent,
}: Props) {
    return (
        <div className="flex flex-col gap-4">
            <div>
                <h1 className="text-xl font-semibold">{customer.name}</h1>
                <p className="text-sm break-all text-muted-foreground">
                    {customer.email}
                    {customer.phone ? ` · ${customer.phone}` : ''}
                    {customer.email_verified_at ? ' · email terverifikasi' : ''}
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <Stat label="Jumlah pesanan" value={String(orders.length)} />
                <Stat
                    label="Total belanja (lunas)"
                    value={formatRupiah(totalSpent)}
                />
                <Stat
                    label="Pelanggan sejak"
                    value={new Date(customer.created_at).toLocaleDateString(
                        'id-ID',
                    )}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="rounded-xl border border-border bg-card p-5 text-sm lg:col-span-2">
                    <h2 className="font-semibold text-foreground">Pesanan</h2>
                    {orders.length === 0 ? (
                        <p className="mt-3 text-muted-foreground">
                            Belum ada pesanan.
                        </p>
                    ) : (
                        <ul className="mt-3 divide-y divide-border">
                            {orders.map((order) => (
                                <li
                                    key={order.id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-2"
                                >
                                    <Link
                                        href={showOrder(order.id).url}
                                        className="font-medium text-foreground underline-offset-4 hover:underline"
                                    >
                                        {order.order_number}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {new Date(
                                            order.created_at,
                                        ).toLocaleDateString('id-ID')}
                                    </span>
                                    <Badge variant="secondary">
                                        {ORDER_STATUS_LABEL[order.status] ??
                                            order.status}
                                    </Badge>
                                    <span className="font-medium">
                                        {formatRupiah(order.total)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="rounded-xl border border-border bg-card p-5 text-sm">
                    <h2 className="font-semibold text-foreground">Alamat</h2>
                    {addresses.length === 0 ? (
                        <p className="mt-3 text-muted-foreground">
                            Belum ada alamat tersimpan.
                        </p>
                    ) : (
                        <ul className="mt-3 flex flex-col gap-3">
                            {addresses.map((address) => (
                                <li
                                    key={address.id}
                                    className="text-muted-foreground"
                                >
                                    <span className="font-medium text-foreground">
                                        {address.label ??
                                            address.recipient_name}
                                        {address.is_default ? ' (utama)' : ''}
                                    </span>
                                    <br />
                                    {address.recipient_name} · {address.phone}
                                    <br />
                                    {address.address}, {address.city},{' '}
                                    {address.province}{' '}
                                    {address.postal_code ?? ''}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border bg-card p-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-lg font-semibold">{value}</p>
        </div>
    );
}

CustomerShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
