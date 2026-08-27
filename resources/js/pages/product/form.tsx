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
import { index, store, update } from '@/routes/backoffice/product';
import type { Product } from '@/types/product';

type Props = {
    product?: Product;
};

export default function ProductForm({ product }: Props) {
    const { data, setData, post, transform, errors, processing } = useForm<{
        name: string;
        description: string;
        price: number | string;
        discount_percent: number | string;
        stock: number | string;
        weight_gram: number | string;
        producer_name: string;
        producer_region: string;
        is_active: boolean;
        images: File[];
        removed_images: number[];
    }>({
        name: product?.name ?? '',
        description: product?.description ?? '',
        price: product?.price ?? 0,
        discount_percent: product?.discount_percent ?? '',
        stock: product?.stock ?? 0,
        weight_gram: product?.weight_gram ?? 1000,
        producer_name: product?.producer_name ?? '',
        producer_region: product?.producer_region ?? '',
        is_active: product?.is_active ?? true,
        images: [],
        removed_images: [],
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (product) {
            transform((current) => ({ ...current, _method: 'put' }));
            post(update(product.id).url, { ...FormResponse, forceFormData: true });
        } else {
            post(store().url, { ...FormResponse, forceFormData: true });
        }
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <h1 className="text-xl font-semibold">
                {product ? 'Ubah Produk' : 'Produk Baru'}
            </h1>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <Label>Nama Produk</Label>
                    <Input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Deskripsi</Label>
                    <Textarea
                        rows={4}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                    />
                    <InputError message={errors.description} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label>Harga (Rp)</Label>
                        <Input
                            type="number"
                            min={0}
                            value={data.price}
                            onChange={(e) => setData('price', e.target.value)}
                        />
                        <InputError message={errors.price} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Diskon (%)</Label>
                        <Input
                            type="number"
                            min={0}
                            max={90}
                            value={data.discount_percent}
                            onChange={(e) => setData('discount_percent', e.target.value)}
                            placeholder="Kosongkan jika tidak ada diskon"
                        />
                        <InputError message={errors.discount_percent} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Stok</Label>
                        <Input
                            type="number"
                            min={0}
                            value={data.stock}
                            onChange={(e) => setData('stock', e.target.value)}
                        />
                        <InputError message={errors.stock} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Berat (gram)</Label>
                        <Input
                            type="number"
                            min={1}
                            value={data.weight_gram}
                            onChange={(e) => setData('weight_gram', e.target.value)}
                        />
                        <InputError message={errors.weight_gram} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Nama UMKM/Produsen</Label>
                        <Input
                            value={data.producer_name}
                            onChange={(e) => setData('producer_name', e.target.value)}
                        />
                        <InputError message={errors.producer_name} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Daerah Asal</Label>
                        <Input
                            value={data.producer_region}
                            onChange={(e) => setData('producer_region', e.target.value)}
                        />
                        <InputError message={errors.producer_region} />
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked === true)}
                    />
                    <Label>Aktif &amp; tampil di storefront</Label>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Gambar Produk</Label>
                    <FileUpload
                        multiple
                        maxFiles={5}
                        existingMedia={product?.images ?? []}
                        onChange={(files) => setData('images', files)}
                        onRemoveExisting={(ids) => setData('removed_images', ids)}
                    />
                    <InputError message={errors.images} />
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

ProductForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
