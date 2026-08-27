import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/page';
import type { Page } from '@/types/page';

type Props = {
    page?: Page;
};

export default function PageForm({ page }: Props) {
    const { data, setData, post, transform, errors, processing } = useForm<{
        title: string;
        body: string;
        meta_description: string;
        is_active: boolean;
    }>({
        title: page?.title ?? '',
        body: page?.body ?? '',
        meta_description: page?.meta_description ?? '',
        is_active: page?.is_active ?? true,
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (page) {
            transform((current) => ({ ...current, _method: 'put' }));
            post(update(page.id).url, FormResponse);
        } else {
            post(store().url, FormResponse);
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {page ? 'Ubah Halaman' : 'Halaman Baru'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <Label>Judul</Label>
                    <Input
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                    />
                    <InputError message={errors.title} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Isi Halaman</Label>
                    <Textarea
                        rows={14}
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                    />
                    <InputError message={errors.body} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Meta Deskripsi (SEO)</Label>
                    <Textarea
                        rows={2}
                        value={data.meta_description}
                        onChange={(e) => setData('meta_description', e.target.value)}
                    />
                    <InputError message={errors.meta_description} />
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked === true)}
                    />
                    <Label>Aktif &amp; tampil di storefront</Label>
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
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Simpan
                    </Button>
                </div>
            </form>
        </div>
    );
}

PageForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
