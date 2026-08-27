import { router } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { Eye } from 'lucide-react';
import NextTable from '@/components/next-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { createDateColumn } from '@/lib/column-helpers';
import { formatRupiah } from '@/lib/utils';
import { fetch as fetchOrders, show } from '@/routes/backoffice/order';
import type { Base } from '@/types/base';
import type { Order } from '@/types/order';

const STATUS_LABEL: Record<string, string> = {
    pending: 'Menunggu Pembayaran',
    paid: 'Dibayar',
    processing: 'Diproses',
    shipped: 'Dikirim',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

const helper = createColumnHelper<Order>();

const columns: ColumnDef<Order, any>[] = [
    helper.accessor('order_number', {
        id: 'order_number',
        header: 'No. Pesanan',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('customer_name', {
        id: 'customer_name',
        header: 'Pelanggan',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.display({
        id: 'total',
        header: 'Total',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => formatRupiah(ctx.row.original.total),
    }),
    helper.display({
        id: 'status',
        header: 'Status',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => (
            <Badge variant="secondary">
                {STATUS_LABEL[ctx.row.original.status] ?? ctx.row.original.status}
            </Badge>
        ),
    }),
    createDateColumn<Order>('created_at'),
    helper.display({
        id: 'action',
        header: 'Aksi',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => (
            <Button
                variant="outline"
                size="sm"
                onClick={() => router.visit(show(ctx.row.original.id).url)}
            >
                <Eye className="size-4" />
                Detail
            </Button>
        ),
    }),
];

export default function OrderIndex() {
    return (
        <div className="flex flex-col gap-4">
            <div>
                <h1 className="text-xl font-semibold">Manajemen Pesanan</h1>
                <p className="text-sm text-muted-foreground">
                    Pantau dan perbarui status pesanan pelanggan
                </p>
            </div>
            <NextTable<Order>
                load={async (params) => {
                    const response = await window.fetch(
                        fetchOrders({ query: params as Record<string, any> }).url,
                    );
                    return response.json() as Promise<Base<Order[]>>;
                }}
                id="id"
                columns={columns}
                mode="table"
            />
        </div>
    );
}

OrderIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
