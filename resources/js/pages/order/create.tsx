import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, Trash2 } from 'lucide-react';
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
import { index, store } from '@/routes/backoffice/order';

type ProductOption = {
    id: number;
    name: string;
    price: number;
    available: number;
    is_bundle: boolean;
};

type Line = { product_id: number | null; quantity: number };

type Props = {
    productOptions: ProductOption[];
    manualHoldHours: number;
};

export default function OrderCreate({
    productOptions,
    manualHoldHours,
}: Props) {
    const { data, setData, post, errors, processing } = useForm({
        customer_name: '',
        customer_email: '',
        customer_phone: '',
        shipping_address: '',
        shipping_city: '',
        shipping_province: 'Papua',
        shipping_postal_code: '',
        notes: '',
        items: [{ product_id: null, quantity: 1 }] as Line[],
        shipping_cost: 0,
        courier_name: '',
        paid: false,
        payment_note: '',
    });

    const errorFor = (key: string) =>
        (errors as Record<string, string | undefined>)[key];

    const productById = (id: number | null) =>
        productOptions.find((p) => p.id === id);

    const setLine = (index: number, patch: Partial<Line>) =>
        setData(
            'items',
            data.items.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );

    const subtotal = data.items.reduce(
        (sum, line) =>
            sum + (productById(line.product_id)?.price ?? 0) * line.quantity,
        0,
    );

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        post(store().url, FormResponse);
    };

    const chosen = new Set(data.items.map((line) => line.product_id));

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <div>
                <h1 className="text-xl font-semibold">Catat Pesanan Manual</h1>
                <p className="text-sm text-muted-foreground">
                    Untuk pesanan lewat WhatsApp, telepon, atau langsung. Stok
                    langsung dipotong saat disimpan.
                </p>
            </div>

            <form onSubmit={onSubmit} className="space-y-6">
                <section className="space-y-3">
                    <h2 className="font-semibold">Produk</h2>
                    {data.items.map((line, i) => {
                        const product = productById(line.product_id);

                        return (
                            <div
                                key={i}
                                className="grid gap-2 sm:grid-cols-[1fr_120px_auto] sm:items-end"
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label>Produk {i + 1}</Label>
                                    <Select
                                        value={
                                            line.product_id
                                                ? String(line.product_id)
                                                : undefined
                                        }
                                        onValueChange={(v) =>
                                            setLine(i, {
                                                product_id: Number(v),
                                            })
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih produk" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {productOptions.map((p) => (
                                                <SelectItem
                                                    key={p.id}
                                                    value={String(p.id)}
                                                    disabled={
                                                        p.available < 1 ||
                                                        (chosen.has(p.id) &&
                                                            p.id !==
                                                                line.product_id)
                                                    }
                                                >
                                                    {p.name} ·{' '}
                                                    {formatRupiah(p.price)} ·
                                                    stok {p.available}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={errorFor(
                                            `items.${i}.product_id`,
                                        )}
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label>Jumlah</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        max={product?.available}
                                        value={line.quantity}
                                        onChange={(e) =>
                                            setLine(i, {
                                                quantity: Math.max(
                                                    1,
                                                    Number(e.target.value),
                                                ),
                                            })
                                        }
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Hapus baris"
                                    disabled={data.items.length === 1}
                                    onClick={() =>
                                        setData(
                                            'items',
                                            data.items.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        );
                    })}
                    <InputError message={errors.items} />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setData('items', [
                                ...data.items,
                                { product_id: null, quantity: 1 },
                            ])
                        }
                    >
                        <Plus className="size-4" />
                        Tambah Produk
                    </Button>
                </section>

                <section className="grid gap-4 sm:grid-cols-2">
                    <h2 className="font-semibold sm:col-span-2">Pelanggan</h2>
                    <Field label="Nama" error={errors.customer_name}>
                        <Input
                            value={data.customer_name}
                            onChange={(e) =>
                                setData('customer_name', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Telepon / WhatsApp"
                        error={errors.customer_phone}
                    >
                        <Input
                            value={data.customer_phone}
                            onChange={(e) =>
                                setData('customer_phone', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Email (opsional — untuk kirim bukti & status)"
                        error={errors.customer_email}
                    >
                        <Input
                            type="email"
                            value={data.customer_email}
                            onChange={(e) =>
                                setData('customer_email', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Alamat" error={errors.shipping_address}>
                        <Textarea
                            rows={2}
                            value={data.shipping_address}
                            onChange={(e) =>
                                setData('shipping_address', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Kota" error={errors.shipping_city}>
                        <Input
                            value={data.shipping_city}
                            onChange={(e) =>
                                setData('shipping_city', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Provinsi" error={errors.shipping_province}>
                        <Input
                            value={data.shipping_province}
                            onChange={(e) =>
                                setData('shipping_province', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Kode Pos" error={errors.shipping_postal_code}>
                        <Input
                            value={data.shipping_postal_code}
                            onChange={(e) =>
                                setData('shipping_postal_code', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Catatan" error={errors.notes}>
                        <Textarea
                            rows={2}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                    </Field>
                </section>

                <section className="grid gap-4 sm:grid-cols-2">
                    <h2 className="font-semibold sm:col-span-2">
                        Pengiriman &amp; Pembayaran
                    </h2>
                    <Field label="Ongkir (Rp)" error={errors.shipping_cost}>
                        <Input
                            type="number"
                            min={0}
                            value={data.shipping_cost}
                            onChange={(e) =>
                                setData('shipping_cost', Number(e.target.value))
                            }
                        />
                    </Field>
                    <Field label="Kurir (opsional)" error={errors.courier_name}>
                        <Input
                            value={data.courier_name}
                            onChange={(e) =>
                                setData('courier_name', e.target.value)
                            }
                            placeholder="Misal: JNE, antar sendiri"
                        />
                    </Field>
                    <div className="flex items-center gap-2 sm:col-span-2">
                        <Checkbox
                            id="paid"
                            checked={data.paid}
                            onCheckedChange={(checked) =>
                                setData('paid', checked === true)
                            }
                        />
                        <Label htmlFor="paid">Sudah dibayar</Label>
                    </div>
                    {data.paid && (
                        <Field
                            label="Dibayar lewat (opsional)"
                            error={errors.payment_note}
                        >
                            <Input
                                value={data.payment_note}
                                onChange={(e) =>
                                    setData('payment_note', e.target.value)
                                }
                                placeholder="Misal: Transfer BCA, tunai"
                            />
                        </Field>
                    )}
                </section>

                <div className="rounded-lg bg-muted/40 p-4 text-sm">
                    <div className="flex justify-between">
                        <span>Subtotal</span>
                        <span>{formatRupiah(subtotal)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>Ongkir</span>
                        <span>{formatRupiah(data.shipping_cost || 0)}</span>
                    </div>
                    <div className="mt-2 flex justify-between border-t border-border pt-2 font-semibold">
                        <span>Total</span>
                        <span>
                            {formatRupiah(subtotal + (data.shipping_cost || 0))}
                        </span>
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Harga diambil dari katalog saat disimpan.
                        {!data.paid &&
                            (manualHoldHours > 0
                                ? ` Bila belum dibayar dalam ${manualHoldHours} jam, pesanan dibatalkan otomatis dan stok dikembalikan.`
                                : ' Pesanan yang belum dibayar tidak dibatalkan otomatis; batalkan sendiri bila pelanggan tidak jadi.')}
                    </p>
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
                        Simpan Pesanan
                    </Button>
                </div>
            </form>
        </div>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

OrderCreate.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
