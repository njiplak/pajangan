import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/category';
import type { Category } from '@/types/category';

type Props = {
    category?: Category;
};

export default function CategoryForm({ category }: Props) {
    const { data, setData, post, put, errors, processing } = useForm({
        name: category?.name ?? '',
        sort_order: category?.sort_order ?? 0,
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (category) {
            put(update(category.id).url, FormResponse);
        } else {
            post(store().url, FormResponse);
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {category ? 'Ubah Kategori' : 'Kategori Baru'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label>Nama</Label>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Kopi, Noken, Madu…"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Urutan</Label>
                        <Input
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(e) =>
                                setData('sort_order', Number(e.target.value))
                            }
                        />
                        <InputError message={errors.sort_order} />
                    </div>
                </div>

                <div className="flex flex-col gap-2 sm:flex-row">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.visit(index().url)}
                    >
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && (
                            <LoaderCircle className="size-4 animate-spin" />
                        )}
                        Simpan
                    </Button>
                </div>
            </form>
        </div>
    );
}

CategoryForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
