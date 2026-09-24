import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import IndexPage from '@/components/index-page';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { createDateColumn } from '@/lib/column-helpers';
import {
    create,
    destroy as destroyRoute,
    destroyBulk,
    fetch as fetchRoute,
    show,
} from '@/routes/backoffice/producer';
import type { Producer } from '@/types/producer';

const helper = createColumnHelper<Producer>();

const columns: ColumnDef<Producer, any>[] = [
    helper.accessor('id', {
        id: 'id',
        header: 'ID',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('name', {
        id: 'name',
        header: 'Nama',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('region', {
        id: 'region',
        header: 'Daerah',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => ctx.getValue() ?? '-',
    }),
    helper.display({
        id: 'is_active',
        header: 'Status',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => (
            <Badge
                variant={ctx.row.original.is_active ? 'default' : 'secondary'}
            >
                {ctx.row.original.is_active ? 'Aktif' : 'Nonaktif'}
            </Badge>
        ),
    }),
    createDateColumn<Producer>('created_at'),
];

const routes = {
    fetch: fetchRoute,
    destroy: destroyRoute,
    destroyBulk,
    show,
    create,
};

export default function ProducerIndex() {
    return (
        <IndexPage<Producer>
            title="Produsen"
            description="UMKM dan kelompok perajin di balik setiap produk"
            addLabel="Tambah Produsen"
            columns={columns}
            routes={routes}
        />
    );
}

ProducerIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
