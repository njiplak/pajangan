import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import IndexPage from '@/components/index-page';
import AppLayout from '@/layouts/app-layout';
import {
    create,
    destroy as destroyRoute,
    destroyBulk,
    fetch as fetchRoute,
    show,
} from '@/routes/backoffice/category';
import type { Category } from '@/types/category';

const helper = createColumnHelper<Category>();

const columns: ColumnDef<Category, any>[] = [
    helper.accessor('name', {
        id: 'name',
        header: 'Nama',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('slug', {
        id: 'slug',
        header: 'Slug',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('sort_order', {
        id: 'sort_order',
        header: 'Urutan',
        enableColumnFilter: false,
        enableHiding: false,
    }),
];

const routes = {
    fetch: fetchRoute,
    destroy: destroyRoute,
    destroyBulk,
    show,
    create,
};

export default function CategoryIndex() {
    return (
        <IndexPage<Category>
            title="Kategori"
            description="Kelompokkan produk agar mudah dijelajahi. Menghapus kategori tidak menghapus produknya."
            addLabel="Tambah Kategori"
            columns={columns}
            routes={routes}
        />
    );
}

CategoryIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
