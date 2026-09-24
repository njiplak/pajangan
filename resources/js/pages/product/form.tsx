import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { FileUpload } from '@/components/file-upload';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { formatRupiah } from '@/lib/utils';
import { index, store, update } from '@/routes/backoffice/product';
import type { ProducerOption } from '@/types/producer';
import type {
    BundleItemInput,
    ComponentOption,
    Product,
} from '@/types/product';

type Props = {
    product?: Product;
    componentOptions: ComponentOption[];
    producerOptions: ProducerOption[];
};

export default function ProductForm({
    product,
    componentOptions,
    producerOptions,
}: Props) {
    const { data, setData, post, transform, errors, processing } = useForm<{
        name: string;
        description: string;
        price: number | string;
        discount_percent: number | string;
        stock: number | string;
        weight_gram: number | string;
        producer_id: string;
        is_active: boolean;
        is_bundle: boolean;
        bundle_items: BundleItemInput[];
        images: File[];
        removed_images: number[];
    }>({
        name: product?.name ?? '',
        description: product?.description ?? '',
        price: product?.price ?? 0,
        discount_percent: product?.discount_percent ?? '',
        stock: product?.stock ?? 0,
        weight_gram: product?.weight_gram ?? 1000,
        producer_id: product?.producer_id ? String(product.producer_id) : '',
        is_active: product?.is_active ?? true,
        is_bundle: product?.is_bundle ?? false,
        bundle_items: product?.bundle_items ?? [],
        images: [],
        removed_images: [],
    });

    const optionsById = new Map(
        componentOptions.map((option) => [option.id, option]),
    );
    const chosenIds = new Set(data.bundle_items.map((item) => item.product_id));

    const addBundleItem = () => {
        const next = componentOptions.find(
            (option) => !chosenIds.has(option.id),
        );

        if (next) {
            setData('bundle_items', [
                ...data.bundle_items,
                { product_id: next.id, quantity: 1 },
            ]);
        }
    };

    const updateBundleItem = (
        index: number,
        patch: Partial<BundleItemInput>,
    ) => {
        setData(
            'bundle_items',
            data.bundle_items.map((item, i) =>
                i === index ? { ...item, ...patch } : item,
            ),
        );
    };

    const removeBundleItem = (index: number) => {
        setData(
            'bundle_items',
            data.bundle_items.filter((_, i) => i !== index),
        );
    };

    // Mirrors Product::availableStock() and shippingWeightGram() so staff see
    // what the bundle will actually offer before they save it.
    const derived = data.bundle_items.reduce(
        (acc, item) => {
            const option = optionsById.get(item.product_id);

            if (!option || item.quantity < 1) {
                return { stock: 0, weight: acc.weight, worth: acc.worth };
            }

            return {
                stock: Math.min(
                    acc.stock,
                    Math.floor(option.stock / item.quantity),
                ),
                weight: acc.weight + option.weight_gram * item.quantity,
                worth: acc.worth + option.effective_price * item.quantity,
            };
        },
        { stock: Infinity, weight: 0, worth: 0 },
    );

    const derivedStock =
        data.bundle_items.length === 0 || derived.stock === Infinity
            ? 0
            : derived.stock;

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        if (product) {
            transform((current) => ({ ...current, _method: 'put' }));
            post(update(product.id).url, {
                ...FormResponse,
                forceFormData: true,
            });
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

                <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 p-3">
                    <Checkbox
                        checked={data.is_bundle}
                        onCheckedChange={(checked) =>
                            setData('is_bundle', checked === true)
                        }
                    />
                    <div className="grid gap-0.5">
                        <Label>Jual sebagai paket</Label>
                        <p className="text-xs text-muted-foreground">
                            Paket tidak punya stok sendiri. Stok dan beratnya
                            mengikuti isi paket, dan setiap penjualan paket
                            memotong stok produk di dalamnya.
                        </p>
                    </div>
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
                            onChange={(e) =>
                                setData('discount_percent', e.target.value)
                            }
                            placeholder="Kosongkan jika tidak ada diskon"
                        />
                        <InputError message={errors.discount_percent} />
                    </div>
                    {!data.is_bundle && (
                        <>
                            <div className="flex flex-col gap-1.5">
                                <Label>Stok</Label>
                                <Input
                                    type="number"
                                    min={0}
                                    value={data.stock}
                                    onChange={(e) =>
                                        setData('stock', e.target.value)
                                    }
                                />
                                <InputError message={errors.stock} />
                            </div>
                            <div className="flex flex-col gap-1.5">
                                <Label>Berat (gram)</Label>
                                <Input
                                    type="number"
                                    min={1}
                                    value={data.weight_gram}
                                    onChange={(e) =>
                                        setData('weight_gram', e.target.value)
                                    }
                                />
                                <InputError message={errors.weight_gram} />
                            </div>
                        </>
                    )}
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <Label>Produsen</Label>
                        <Select
                            value={data.producer_id || 'none'}
                            onValueChange={(value) =>
                                setData(
                                    'producer_id',
                                    value === 'none' ? '' : value,
                                )
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Pilih produsen" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    Tanpa produsen
                                </SelectItem>
                                {producerOptions.map((producer) => (
                                    <SelectItem
                                        key={producer.id}
                                        value={String(producer.id)}
                                    >
                                        {producer.name}
                                        {producer.region
                                            ? ` — ${producer.region}`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Belum ada di daftar? Tambahkan dulu di menu
                            Produsen.
                        </p>
                        <InputError message={errors.producer_id} />
                    </div>
                </div>

                {data.is_bundle && (
                    <div className="flex flex-col gap-3 rounded-lg border border-border p-4">
                        <div className="flex items-center justify-between gap-2">
                            <Label>Isi Paket</Label>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addBundleItem}
                                disabled={
                                    data.bundle_items.length >=
                                    componentOptions.length
                                }
                            >
                                <Plus className="size-4" />
                                Tambah Produk
                            </Button>
                        </div>

                        {componentOptions.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Belum ada produk satuan yang bisa dimasukkan ke
                                paket. Buat produk biasa terlebih dahulu.
                            </p>
                        )}

                        {data.bundle_items.map((item, index) => (
                            <div
                                key={index}
                                className="flex flex-wrap items-end gap-2"
                            >
                                <div className="flex min-w-50 flex-1 flex-col gap-1.5">
                                    <Label className="text-xs">Produk</Label>
                                    <Select
                                        value={String(item.product_id)}
                                        onValueChange={(value) =>
                                            updateBundleItem(index, {
                                                product_id: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih produk" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {componentOptions
                                                .filter(
                                                    (option) =>
                                                        option.id ===
                                                            item.product_id ||
                                                        !chosenIds.has(
                                                            option.id,
                                                        ),
                                                )
                                                .map((option) => (
                                                    <SelectItem
                                                        key={option.id}
                                                        value={String(
                                                            option.id,
                                                        )}
                                                    >
                                                        {option.name} — stok{' '}
                                                        {option.stock}
                                                    </SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={
                                            errors[
                                                `bundle_items.${index}.product_id` as keyof typeof errors
                                            ]
                                        }
                                    />
                                </div>
                                <div className="flex w-28 flex-col gap-1.5">
                                    <Label className="text-xs">Jumlah</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        value={item.quantity}
                                        onChange={(e) =>
                                            updateBundleItem(index, {
                                                quantity: Number(
                                                    e.target.value,
                                                ),
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            errors[
                                                `bundle_items.${index}.quantity` as keyof typeof errors
                                            ]
                                        }
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => removeBundleItem(index)}
                                    aria-label="Hapus produk dari paket"
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        ))}

                        <InputError message={errors.bundle_items} />

                        {data.bundle_items.length > 0 && (
                            <div className="grid gap-1 rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
                                <p>
                                    Stok paket yang bisa dijual:{' '}
                                    <span className="font-medium text-foreground">
                                        {derivedStock}
                                    </span>
                                </p>
                                <p>
                                    Berat kirim:{' '}
                                    <span className="font-medium text-foreground">
                                        {derived.weight} gram
                                    </span>
                                </p>
                                <p>
                                    Harga bila dibeli satuan:{' '}
                                    <span className="font-medium text-foreground">
                                        {formatRupiah(derived.worth)}
                                    </span>
                                </p>
                            </div>
                        )}
                    </div>
                )}

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
                    <Label>Gambar Produk</Label>
                    <FileUpload
                        multiple
                        maxFiles={5}
                        existingMedia={product?.images ?? []}
                        onChange={(files) => setData('images', files)}
                        onRemoveExisting={(ids) =>
                            setData('removed_images', ids)
                        }
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

ProductForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
