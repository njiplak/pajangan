import { router } from '@inertiajs/react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { Eye } from 'lucide-react';
import NextTable from '@/components/next-table';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { createDateColumn } from '@/lib/column-helpers';
import { fetch as fetchCustomers, show } from '@/routes/backoffice/customer';
import type { Base } from '@/types/base';
import type { Customer } from '@/types/customer';

const helper = createColumnHelper<Customer>();

const columns: ColumnDef<Customer, any>[] = [
    helper.accessor('name', {
        id: 'name',
        header: 'Nama',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.accessor('email', {
        id: 'email',
        header: 'Email',
        enableColumnFilter: false,
        enableHiding: false,
    }),
    helper.display({
        id: 'phone',
        header: 'Telepon',
        enableColumnFilter: false,
        enableHiding: false,
        cell: (ctx) => ctx.row.original.phone ?? '-',
    }),
    createDateColumn<Customer>('created_at'),
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

export default function CustomerIndex() {
    return (
        <div className="flex flex-col gap-4">
            <div>
                <h1 className="text-xl font-semibold">Pelanggan</h1>
                <p className="text-sm text-muted-foreground">
                    Akun pelanggan toko online beserta riwayat pesanannya
                </p>
            </div>
            <NextTable<Customer>
                load={async (params) => {
                    const response = await window.fetch(
                        fetchCustomers({ query: params as Record<string, any> })
                            .url,
                    );
                    return response.json() as Promise<Base<Customer[]>>;
                }}
                id="id"
                columns={columns}
                mode="table"
                searchPlaceholder="Cari nama, email, atau telepon..."
            />
        </div>
    );
}

CustomerIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
