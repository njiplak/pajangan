import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';

import IndexPage from '@/components/index-page';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { createDateColumn } from '@/lib/column-helpers';
import { formatRupiah } from '@/lib/utils';
import {
    create,
    destroy as destroyRoute,
    destroyBulk,
    fetch as fetchRoute,
    show,
} from '@/routes/backoffice/product';
import type { Product } from '@/types/product';

const helper = createColumnHelper<Product>();

const columns: ColumnDef<Product, any>[] = [
    helper.accessor('id', {
        id: 'id',
        header: 'ID',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.display({
        id: 'image',
        header: 'Gambar',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => {
            const image = ctx.row.original.images?.[0];

            return image ? (
                <img
                    src={image.preview_url || image.original_url}
                    alt=""
                    className="size-10 rounded-md object-cover"
                />
            ) : (
                <div className="size-10 rounded-md bg-muted" />
            );
        },
    }),
    helper.accessor('name', {
        id: 'name',
        header: 'Nama',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.display({
        id: 'price',
        header: 'Harga',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => formatRupiah(ctx.row.original.price),
    }),
    helper.accessor('stock', {
        id: 'stock',
        header: 'Stok',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.display({
        id: 'is_active',
        header: 'Status',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => (
            <Badge variant={ctx.row.original.is_active ? 'default' : 'secondary'}>
                {ctx.row.original.is_active ? 'Aktif' : 'Nonaktif'}
            </Badge>
        ),
    }),
    createDateColumn<Product>('created_at'),
];

const routes = { fetch: fetchRoute, destroy: destroyRoute, destroyBulk, show, create };

export default function ProductIndex() {
    return (
        <IndexPage<Product>
            title="Manajemen Produk"
            description="Kelola produk UMKM yang ditampilkan di storefront"
            addLabel="Tambah Produk"
            columns={columns}
            routes={routes}
        />
    );
}

ProductIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
