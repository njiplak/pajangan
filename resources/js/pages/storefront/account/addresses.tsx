import { Head, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, MapPin, Plus } from 'lucide-react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { AccountNav } from '@/components/storefront/account-nav';
import { AreaSearch } from '@/components/storefront/area-search';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import StorefrontLayout from '@/layouts/storefront-layout';
import {
    defaultMethod as makeDefault,
    destroy,
    store,
    update,
} from '@/routes/account/addresses';
import type { SavedAddress } from '@/types/customer';

type Props = {
    addresses: SavedAddress[];
    max: number;
};

const EMPTY = {
    label: '',
    recipient_name: '',
    phone: '',
    address: '',
    city: '',
    province: '',
    postal_code: '',
    destination_area_id: '',
    destination_area_name: '',
    is_default: false,
};

export default function Addresses({ addresses, max }: Props) {
    const { errors: pageErrors } = usePage<{
        errors: Record<string, string>;
    }>().props;
    const [editing, setEditing] = useState<SavedAddress | 'new' | null>(null);

    const remove = (address: SavedAddress) => {
        if (
            window.confirm(
                `Hapus alamat "${address.label ?? address.address}"?`,
            )
        ) {
            router.delete(destroy(address.id).url, { preserveScroll: true });
        }
    };

    return (
        <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
            <Head title="Alamat Saya" />

            <h1 className="text-xl font-semibold text-foreground">
                Alamat Saya
            </h1>

            <div className="mt-6">
                <AccountNav active="addresses" />
            </div>

            {pageErrors.address && (
                <div className="mt-6">
                    <AlertError
                        errors={[pageErrors.address]}
                        title="Tidak bisa menambah"
                    />
                </div>
            )}

            <div className="mt-6 flex flex-col gap-3">
                {addresses.length === 0 && editing === null && (
                    <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                        Belum ada alamat tersimpan. Simpan alamat agar checkout
                        tinggal pilih.
                    </div>
                )}

                {addresses.map((address) =>
                    editing !== 'new' && editing?.id === address.id ? (
                        <AddressForm
                            key={address.id}
                            initial={address}
                            onDone={() => setEditing(null)}
                        />
                    ) : (
                        <div
                            key={address.id}
                            className="rounded-xl border border-border p-4 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <MapPin className="size-4 text-muted-foreground" />
                                <span className="font-medium text-foreground">
                                    {address.label || 'Alamat'}
                                </span>
                                {address.is_default && <Badge>Utama</Badge>}
                            </div>
                            <p className="mt-2 text-foreground">
                                {address.recipient_name} · {address.phone}
                            </p>
                            <p className="mt-1 text-muted-foreground">
                                {address.address}, {address.city},{' '}
                                {address.province} {address.postal_code ?? ''}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Area kurir: {address.destination_area_name}
                            </p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEditing(address)}
                                >
                                    Ubah
                                </Button>
                                {!address.is_default && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                makeDefault(address.id).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Jadikan utama
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive"
                                    onClick={() => remove(address)}
                                >
                                    Hapus
                                </Button>
                            </div>
                        </div>
                    ),
                )}

                {editing === 'new' ? (
                    <AddressForm onDone={() => setEditing(null)} />
                ) : (
                    addresses.length < max && (
                        <Button
                            variant="outline"
                            className="gap-2 self-start"
                            onClick={() => setEditing('new')}
                        >
                            <Plus className="size-4" />
                            Tambah Alamat
                        </Button>
                    )
                )}
            </div>
        </div>
    );
}

function AddressForm({
    initial,
    onDone,
}: {
    initial?: SavedAddress;
    onDone: () => void;
}) {
    const { data, setData, post, put, processing, errors } = useForm(
        initial
            ? {
                  ...EMPTY,
                  ...initial,
                  label: initial.label ?? '',
                  postal_code: initial.postal_code ?? '',
              }
            : EMPTY,
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (initial) {
            put(update(initial.id).url, options);
        } else {
            post(store().url, options);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-4 rounded-xl border border-primary/40 p-4 sm:grid-cols-2"
        >
            <Field label="Label (opsional)" error={errors.label}>
                <Input
                    value={data.label}
                    onChange={(e) => setData('label', e.target.value)}
                    placeholder="Rumah, Kantor, Kos…"
                />
            </Field>
            <Field label="Nama penerima" error={errors.recipient_name}>
                <Input
                    value={data.recipient_name}
                    onChange={(e) => setData('recipient_name', e.target.value)}
                />
            </Field>
            <Field label="No. HP" error={errors.phone}>
                <Input
                    value={data.phone}
                    onChange={(e) => setData('phone', e.target.value)}
                />
            </Field>
            <Field label="Kode pos" error={errors.postal_code}>
                <Input
                    value={data.postal_code}
                    onChange={(e) => setData('postal_code', e.target.value)}
                />
            </Field>
            <div className="sm:col-span-2">
                <Field label="Alamat lengkap" error={errors.address}>
                    <Textarea
                        rows={3}
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                        placeholder="Nama jalan, nomor rumah, RT/RW, patokan"
                    />
                </Field>
            </div>
            <Field label="Kota/Kabupaten" error={errors.city}>
                <Input
                    value={data.city}
                    onChange={(e) => setData('city', e.target.value)}
                />
            </Field>
            <Field label="Provinsi" error={errors.province}>
                <Input
                    value={data.province}
                    onChange={(e) => setData('province', e.target.value)}
                />
            </Field>
            <div className="sm:col-span-2">
                <Field
                    label="Area kurir (untuk hitung ongkir)"
                    error={errors.destination_area_id}
                >
                    <AreaSearch
                        value={data.destination_area_name}
                        onSelect={(area) =>
                            setData((current) => ({
                                ...current,
                                destination_area_id: area.id,
                                destination_area_name: area.name,
                            }))
                        }
                    />
                </Field>
            </div>
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                <Checkbox
                    checked={data.is_default}
                    onCheckedChange={(checked) =>
                        setData('is_default', checked === true)
                    }
                />
                Jadikan alamat utama
            </label>
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit" disabled={processing}>
                    {processing && (
                        <LoaderCircle className="size-4 animate-spin" />
                    )}
                    Simpan
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Batal
                </Button>
            </div>
        </form>
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

Addresses.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
