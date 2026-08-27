import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FileUpload } from '@/components/file-upload';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/banner';
import type { Banner } from '@/types/banner';

type Props = {
    banner?: Banner;
};

export default function BannerForm({ banner }: Props) {
    const { data, setData, post, transform, errors, processing } = useForm<{
        title: string;
        link: string;
        sort_order: number | string;
        is_active: boolean;
        image: File[];
        removed_image: number | null;
    }>({
        title: banner?.title ?? '',
        link: banner?.link ?? '',
        sort_order: banner?.sort_order ?? 0,
        is_active: banner?.is_active ?? true,
        image: [],
        removed_image: null,
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (banner) {
            transform((current) => ({
                ...current,
                image: current.image[0] ?? null,
                _method: 'put',
            }));
            post(update(banner.id).url, { ...FormResponse, forceFormData: true });
        } else {
            transform((current) => ({ ...current, image: current.image[0] ?? null }));
            post(store().url, { ...FormResponse, forceFormData: true });
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {banner ? 'Ubah Banner' : 'Banner Baru'}
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

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label>Tautan (opsional)</Label>
                        <Input
                            value={data.link}
                            onChange={(e) => setData('link', e.target.value)}
                            placeholder="/produk/nama-produk"
                        />
                        <InputError message={errors.link} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Urutan</Label>
                        <Input
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', e.target.value)}
                        />
                        <InputError message={errors.sort_order} />
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked === true)}
                    />
                    <Label>Aktif &amp; tampil di beranda</Label>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Gambar Banner</Label>
                    <FileUpload
                        multiple={false}
                        maxFiles={1}
                        existingMedia={banner?.images ?? []}
                        onChange={(files) => setData('image', files)}
                        onRemoveExisting={(ids) => setData('removed_image', ids[0] ?? null)}
                    />
                    <InputError message={errors.image} />
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

BannerForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
