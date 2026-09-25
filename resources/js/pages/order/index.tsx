import { Link, router } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { Download, Eye, Plus } from 'lucide-react';
import { useCallback, useState } from 'react';
import NextTable from '@/components/next-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { createDateColumn } from '@/lib/column-helpers';
import { formatRupiah } from '@/lib/utils';
import {
    create,
    exportMethod,
    fetch as fetchOrders,
    show,
} from '@/routes/backoffice/order';
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

const ALL = 'all';

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

type FilterProps = {
    updateParams?: (params: Record<string, unknown>) => void;
    currentParams?: Record<string, unknown>;
};

function OrderFilters({ updateParams, currentParams = {} }: FilterProps) {
    const value = (key: string) =>
        (currentParams[key] as string | undefined) ?? '';

    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-1.5">
                <Label>Status</Label>
                <Select
                    value={value('filter[status]') || ALL}
                    onValueChange={(status) =>
                        updateParams?.({
                            'filter[status]':
                                status === ALL ? undefined : status,
                        })
                    }
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>Semua status</SelectItem>
                        {Object.entries(STATUS_LABEL).map(([status, label]) => (
                            <SelectItem key={status} value={status}>
                                {label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="filter-from">Dari tanggal</Label>
                <Input
                    id="filter-from"
                    type="date"
                    value={value('filter[created_from]')}
                    onChange={(e) =>
                        updateParams?.({
                            'filter[created_from]': e.target.value || undefined,
                        })
                    }
                />
            </div>
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="filter-to">Sampai tanggal</Label>
                <Input
                    id="filter-to"
                    type="date"
                    value={value('filter[created_to]')}
                    onChange={(e) =>
                        updateParams?.({
                            'filter[created_to]': e.target.value || undefined,
                        })
                    }
                />
            </div>
        </div>
    );
}

export default function OrderIndex() {
    const [filters, setFilters] = useState<Record<string, unknown>>({});

    // Export follows whatever the table is currently filtered to.
    const onParamsChange = useCallback((params: Record<string, unknown>) => {
        setFilters(
            Object.fromEntries(
                Object.entries(params).filter(
                    ([key, val]) =>
                        key.startsWith('filter[') && val !== undefined,
                ),
            ),
        );
    }, []);

    const activeFilterCount = [
        'filter[status]',
        'filter[created_from]',
        'filter[created_to]',
    ].filter((key) => filters[key] !== undefined).length;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold">Manajemen Pesanan</h1>
                    <p className="text-sm text-muted-foreground">
                        Pantau dan perbarui status pesanan pelanggan
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" size="sm">
                        <a
                            href={
                                exportMethod({
                                    query: filters as Record<string, string>,
                                }).url
                            }
                        >
                            <Download className="size-4" />
                            Ekspor CSV
                        </a>
                    </Button>
                    <Button asChild size="sm">
                        <Link href={create().url}>
                            <Plus className="size-4" />
                            Catat Pesanan
                        </Link>
                    </Button>
                </div>
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
                searchPlaceholder="Cari nomor, nama, email, atau telepon..."
                filterComponent={<OrderFilters />}
                activeFilterCount={activeFilterCount}
                onParamsChange={onParamsChange}
            />
        </div>
    );
}

OrderIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
