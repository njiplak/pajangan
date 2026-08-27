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
} from '@/routes/backoffice/page';
import type { Page } from '@/types/page';

const helper = createColumnHelper<Page>();

const columns: ColumnDef<Page, any>[] = [
    helper.accessor('id', {
        id: 'id',
        header: 'ID',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('title', {
        id: 'title',
        header: 'Judul',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('slug', {
        id: 'slug',
        header: 'Slug',
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
    createDateColumn<Page>('created_at'),
];

const routes = { fetch: fetchRoute, destroy: destroyRoute, destroyBulk, show, create };

export default function PageIndex() {
    return (
        <IndexPage<Page>
            title="Manajemen Halaman"
            description="Kelola halaman statis seperti Tentang Kami"
            addLabel="Tambah Halaman"
            columns={columns}
            routes={routes}
        />
    );
}

PageIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
