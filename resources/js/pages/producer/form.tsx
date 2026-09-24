import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FileUpload } from '@/components/file-upload';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { index, store, update } from '@/routes/backoffice/producer';
import type { Producer } from '@/types/producer';

type Props = {
    producer?: Producer;
};

export default function ProducerForm({ producer }: Props) {
    const { data, setData, post, transform, errors, processing } = useForm<{
        name: string;
        region: string;
        story: string;
        is_active: boolean;
        photo: File[];
        removed_photo: number | null;
    }>({
        name: producer?.name ?? '',
        region: producer?.region ?? '',
        story: producer?.story ?? '',
        is_active: producer?.is_active ?? true,
        photo: [],
        removed_photo: null,
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        transform((current) => ({
            ...current,
            photo: current.photo[0] ?? null,
            ...(producer ? { _method: 'put' } : {}),
        }));

        post(producer ? update(producer.id).url : store().url, {
            ...FormResponse,
            forceFormData: true,
        });
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {producer ? 'Ubah Produsen' : 'Produsen Baru'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label>Nama UMKM / Kelompok</Label>
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Daerah Asal</Label>
                        <Input
                            value={data.region}
                            onChange={(e) => setData('region', e.target.value)}
                            placeholder="Wamena, Papua Pegunungan"
                        />
                        <InputError message={errors.region} />
                    </div>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Cerita</Label>
                    <Textarea
                        rows={6}
                        value={data.story}
                        onChange={(e) => setData('story', e.target.value)}
                        placeholder="Siapa mereka, bagaimana produknya dibuat, apa yang membuatnya istimewa."
                    />
                    <InputError message={errors.story} />
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        checked={data.is_active}
                        onCheckedChange={(checked) =>
                            setData('is_active', checked === true)
                        }
                    />
                    <Label>Aktif &amp; tampil di storefront</Label>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Foto</Label>
                    <FileUpload
                        multiple={false}
                        maxFiles={1}
                        existingMedia={producer?.images ?? []}
                        onChange={(files) => setData('photo', files)}
                        onRemoveExisting={(ids) =>
                            setData('removed_photo', ids[0] ?? null)
                        }
                    />
                    <InputError message={errors.photo} />
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

ProducerForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
