import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { FormResponse } from '@/lib/constant';
import { updateDetails } from '@/routes/backoffice/order';
import type { Order } from '@/types/order';

type Props = {
    order: Order;
    canUpdate: boolean;
};

const EDITABLE_STATUSES = ['pending', 'paid', 'processing'];

export default function DetailsEditor({ order, canUpdate }: Props) {
    const editable =
        canUpdate &&
        EDITABLE_STATUSES.includes(order.status) &&
        !order.biteship_order_id;

    const initial = {
        customer_name: order.customer_name,
        customer_email: order.customer_email ?? '',
        customer_phone: order.customer_phone,
        shipping_address: order.shipping_address,
        shipping_city: order.shipping_city,
        shipping_province: order.shipping_province,
        shipping_postal_code: order.shipping_postal_code ?? '',
    };

    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState(initial);
    const [saving, setSaving] = useState(false);

    const set = (field: keyof typeof initial) => (value: string) =>
        setForm((prev) => ({ ...prev, [field]: value }));

    const onSave = () => {
        setSaving(true);
        router.put(
            updateDetails(order.id).url,
            {
                ...form,
                customer_email: form.customer_email || null,
                shipping_postal_code: form.shipping_postal_code || null,
            },
            {
                ...FormResponse,
                preserveScroll: true,
                onSuccess: () => setEditing(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    if (!editing) {
        return (
            <div className="rounded-xl border border-border bg-card p-5 text-sm">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="font-semibold text-foreground">Pelanggan</h2>
                    {editable && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setForm(initial);
                                setEditing(true);
                            }}
                        >
                            Ubah
                        </Button>
                    )}
                </div>
                <p className="mt-2 text-muted-foreground">
                    {order.customer_name}
                </p>
                <p className="break-all text-muted-foreground">
                    {order.customer_email ?? 'Tanpa email'}
                </p>
                <p className="text-muted-foreground">{order.customer_phone}</p>

                <h2 className="mt-4 font-semibold text-foreground">
                    Alamat Pengiriman
                </h2>
                <p className="mt-2 text-muted-foreground">
                    {order.shipping_address}, {order.shipping_city},{' '}
                    {order.shipping_province}
                    {order.shipping_postal_code
                        ? ` ${order.shipping_postal_code}`
                        : ''}
                </p>

                {order.notes && (
                    <>
                        <h2 className="mt-4 font-semibold text-foreground">
                            Catatan Pelanggan
                        </h2>
                        <p className="mt-2 text-muted-foreground">
                            {order.notes}
                        </p>
                    </>
                )}

                {canUpdate && !editable && (
                    <p className="mt-4 text-xs text-muted-foreground">
                        {order.biteship_order_id
                            ? 'Data tidak bisa diubah karena pengiriman sudah dibuat di Biteship.'
                            : 'Data tidak bisa diubah pada status ini.'}
                    </p>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 text-sm">
            <h2 className="font-semibold text-foreground">Ubah Data Pesanan</h2>
            <Field label="Nama" id="d-name">
                <Input
                    id="d-name"
                    value={form.customer_name}
                    onChange={(e) => set('customer_name')(e.target.value)}
                />
            </Field>
            <Field label="Email (opsional)" id="d-email">
                <Input
                    id="d-email"
                    type="email"
                    value={form.customer_email}
                    onChange={(e) => set('customer_email')(e.target.value)}
                />
            </Field>
            <Field label="Telepon" id="d-phone">
                <Input
                    id="d-phone"
                    value={form.customer_phone}
                    onChange={(e) => set('customer_phone')(e.target.value)}
                />
            </Field>
            <Field label="Alamat" id="d-address">
                <Textarea
                    id="d-address"
                    rows={2}
                    value={form.shipping_address}
                    onChange={(e) => set('shipping_address')(e.target.value)}
                />
            </Field>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Kota" id="d-city">
                    <Input
                        id="d-city"
                        value={form.shipping_city}
                        onChange={(e) => set('shipping_city')(e.target.value)}
                    />
                </Field>
                <Field label="Provinsi" id="d-province">
                    <Input
                        id="d-province"
                        value={form.shipping_province}
                        onChange={(e) =>
                            set('shipping_province')(e.target.value)
                        }
                    />
                </Field>
                <Field label="Kode Pos" id="d-postal">
                    <Input
                        id="d-postal"
                        value={form.shipping_postal_code}
                        onChange={(e) =>
                            set('shipping_postal_code')(e.target.value)
                        }
                    />
                </Field>
            </div>
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    disabled={saving}
                    onClick={onSave}
                >
                    {saving ? 'Menyimpan...' : 'Simpan'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing(false)}
                >
                    Batal
                </Button>
            </div>
        </div>
    );
}

function Field({
    label,
    id,
    children,
}: {
    label: string;
    id: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
        </div>
    );
}
