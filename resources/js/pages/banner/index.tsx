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
} from '@/routes/backoffice/banner';
import type { Banner } from '@/types/banner';

const helper = createColumnHelper<Banner>();

const columns: ColumnDef<Banner, any>[] = [
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
    helper.accessor('title', {
        id: 'title',
        header: 'Judul',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('sort_order', {
        id: 'sort_order',
        header: 'Urutan',
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
    createDateColumn<Banner>('created_at'),
];

const routes = { fetch: fetchRoute, destroy: destroyRoute, destroyBulk, show, create };

export default function BannerIndex() {
    return (
        <IndexPage<Banner>
            title="Manajemen Banner"
            description="Kelola banner yang tampil di halaman beranda"
            addLabel="Tambah Banner"
            columns={columns}
            routes={routes}
        />
    );
}

BannerIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
