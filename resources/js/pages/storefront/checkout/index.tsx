import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import StorefrontLayout from '@/layouts/storefront-layout';
import { FormResponse } from '@/lib/constant';
import { formatRupiah, getCsrfToken } from '@/lib/utils';
import { store as checkoutStore, shippingAreas, shippingRates } from '@/routes/checkout';
import type { CartSummary } from '@/types/cart';
import type { ShippingArea, ShippingRateOption } from '@/types/order';

type Props = {
    cart: CartSummary;
};

export default function CheckoutIndex({ cart }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        customer_name: '',
        customer_email: '',
        customer_phone: '',
        shipping_address: '',
        shipping_city: '',
        shipping_province: '',
        shipping_postal_code: '',
        destination_area_id: '',
        destination_area_name: '',
        courier_code: '',
        courier_service_code: '',
        notes: '',
    });

    const [areaQuery, setAreaQuery] = useState('');
    const [areas, setAreas] = useState<ShippingArea[]>([]);
    const [selectedArea, setSelectedArea] = useState<ShippingArea | null>(null);
    const [rates, setRates] = useState<ShippingRateOption[]>([]);
    const [selectedRate, setSelectedRate] = useState<ShippingRateOption | null>(null);
    const [loadingRates, setLoadingRates] = useState(false);
    const [ratesError, setRatesError] = useState<string | null>(null);

    useEffect(() => {
        if (selectedArea && areaQuery === selectedArea.name) return;
        if (areaQuery.trim().length < 3) {
            setAreas([]);
            return;
        }

        const timeout = setTimeout(async () => {
            const response = await window.fetch(shippingAreas({ query: { q: areaQuery } }).url);
            const body = await response.json();
            setAreas(body.areas ?? []);
        }, 400);

        return () => clearTimeout(timeout);
    }, [areaQuery, selectedArea]);

    const onSelectArea = async (area: ShippingArea) => {
        setSelectedArea(area);
        setAreaQuery(area.name);
        setAreas([]);
        setRates([]);
        setSelectedRate(null);
        setData((current) => ({
            ...current,
            destination_area_id: area.id,
            destination_area_name: area.name,
            courier_code: '',
            courier_service_code: '',
        }));

        setLoadingRates(true);
        setRatesError(null);

        try {
            const response = await window.fetch(shippingRates().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ destination_area_id: area.id }),
            });
            const body = await response.json();

            if (!response.ok) {
                setRatesError(body.message ?? 'Gagal mengambil tarif pengiriman.');
                return;
            }

            setRates(body.rates ?? []);
        } catch {
            setRatesError('Gagal mengambil tarif pengiriman.');
        } finally {
            setLoadingRates(false);
        }
    };

    const onSelectRate = (rate: ShippingRateOption) => {
        setSelectedRate(rate);
        setData((current) => ({
            ...current,
            courier_code: rate.courier_code,
            courier_service_code: rate.courier_service_code,
        }));
    };

    const shippingCost = selectedRate?.price ?? 0;
    const canSubmit = Boolean(selectedArea && selectedRate) && !processing;

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!canSubmit) return;
        post(checkoutStore().url, FormResponse);
    };

    const cartError = (errors as Record<string, string>).cart;

    return (
        <div className="mx-auto grid max-w-5xl gap-10 px-4 py-10 sm:px-6 lg:grid-cols-3">
            <Head title="Checkout">
                <meta name="robots" content="noindex,nofollow" />
            </Head>

            <form onSubmit={onSubmit} className="flex flex-col gap-4 lg:col-span-2">
                <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                    Checkout
                </h1>

                {cartError && (
                    <AlertError errors={[cartError]} title="Keranjang bermasalah" />
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-1.5">
                        <Label>Nama Lengkap</Label>
                        <Input
                            value={data.customer_name}
                            onChange={(e) => setData('customer_name', e.target.value)}
                        />
                        <InputError message={errors.customer_name} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Email</Label>
                        <Input
                            type="email"
                            value={data.customer_email}
                            onChange={(e) => setData('customer_email', e.target.value)}
                        />
                        <InputError message={errors.customer_email} />
                    </div>
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <Label>Nomor HP</Label>
                        <Input
                            value={data.customer_phone}
                            onChange={(e) => setData('customer_phone', e.target.value)}
                        />
                        <InputError message={errors.customer_phone} />
                    </div>
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <Label>Alamat Pengiriman</Label>
                        <Textarea
                            rows={3}
                            value={data.shipping_address}
                            onChange={(e) => setData('shipping_address', e.target.value)}
                        />
                        <InputError message={errors.shipping_address} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Kota/Kabupaten</Label>
                        <Input
                            value={data.shipping_city}
                            onChange={(e) => setData('shipping_city', e.target.value)}
                        />
                        <InputError message={errors.shipping_city} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Provinsi</Label>
                        <Input
                            value={data.shipping_province}
                            onChange={(e) => setData('shipping_province', e.target.value)}
                        />
                        <InputError message={errors.shipping_province} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label>Kode Pos (opsional)</Label>
                        <Input
                            value={data.shipping_postal_code}
                            onChange={(e) => setData('shipping_postal_code', e.target.value)}
                        />
                        <InputError message={errors.shipping_postal_code} />
                    </div>
                    <div className="relative flex flex-col gap-1.5 sm:col-span-2">
                        <Label>Tujuan Pengiriman (kota/kecamatan/kode pos)</Label>
                        <Input
                            value={areaQuery}
                            onChange={(e) => {
                                setAreaQuery(e.target.value);
                                setSelectedArea(null);
                                setRates([]);
                                setSelectedRate(null);
                                setData((current) => ({
                                    ...current,
                                    destination_area_id: '',
                                    destination_area_name: '',
                                    courier_code: '',
                                    courier_service_code: '',
                                }));
                            }}
                            placeholder="Ketik minimal 3 huruf, misal: Jayapura"
                        />
                        {areas.length > 0 && (
                            <ul className="absolute top-full z-10 mt-1 max-h-56 w-full overflow-auto rounded-md border border-border bg-popover shadow-md">
                                {areas.map((area) => (
                                    <li key={area.id}>
                                        <button
                                            type="button"
                                            className="w-full px-3 py-2 text-left text-sm hover:bg-accent"
                                            onClick={() => onSelectArea(area)}
                                        >
                                            {area.name}
                                            {area.postal_code ? ` (${area.postal_code})` : ''}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <InputError message={errors.destination_area_id} />
                    </div>
                    <div className="flex flex-col gap-1.5 sm:col-span-2">
                        <Label>Catatan (opsional)</Label>
                        <Textarea
                            rows={2}
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                        <InputError message={errors.notes} />
                    </div>
                </div>

                {selectedArea && (
                    <div className="flex flex-col gap-2">
                        <Label>Pilih Kurir</Label>
                        {ratesError && (
                            <p className="text-xs text-destructive">{ratesError}</p>
                        )}
                        {loadingRates && (
                            <p className="text-xs text-muted-foreground">
                                Mengecek tarif pengiriman...
                            </p>
                        )}
                        {!loadingRates && rates.length === 0 && !ratesError && (
                            <p className="text-xs text-muted-foreground">
                                Tidak ada tarif pengiriman untuk tujuan ini.
                            </p>
                        )}
                        <div className="flex flex-col gap-2">
                            {rates.map((rate) => (
                                <button
                                    type="button"
                                    key={`${rate.courier_code}-${rate.courier_service_code}`}
                                    onClick={() => onSelectRate(rate)}
                                    className={`flex items-center justify-between rounded-lg border p-3 text-left text-sm transition-colors ${
                                        selectedRate?.courier_service_code === rate.courier_service_code &&
                                        selectedRate?.courier_code === rate.courier_code
                                            ? 'border-primary bg-primary/5'
                                            : 'border-border hover:bg-accent'
                                    }`}
                                >
                                    <span>
                                        <span className="font-medium text-foreground">
                                            {rate.courier_name} - {rate.courier_service_name}
                                        </span>
                                        {rate.duration && (
                                            <span className="block text-xs text-muted-foreground">
                                                {rate.duration}
                                            </span>
                                        )}
                                    </span>
                                    <span className="font-semibold text-foreground">
                                        {formatRupiah(rate.price)}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.courier_code} />
                    </div>
                )}

                <Button type="submit" size="lg" disabled={!canSubmit} className="mt-2">
                    Buat Pesanan
                </Button>
            </form>

            <div className="h-fit rounded-xl border border-border p-5">
                <h2 className="font-semibold text-foreground">Ringkasan Pesanan</h2>
                <div className="mt-4 flex flex-col gap-3">
                    {cart.items.map((item) => (
                        <div key={item.product_id} className="flex justify-between text-sm">
                            <span className="text-muted-foreground">
                                {item.name} × {item.quantity}
                            </span>
                            <span className="font-medium text-foreground">
                                {formatRupiah(item.subtotal)}
                            </span>
                        </div>
                    ))}
                </div>
                <div className="mt-4 flex flex-col gap-2 border-t border-border pt-4 text-sm">
                    <div className="flex justify-between text-muted-foreground">
                        <span>Subtotal</span>
                        <span>{formatRupiah(cart.subtotal)}</span>
                    </div>
                    <div className="flex justify-between text-muted-foreground">
                        <span>Ongkos Kirim</span>
                        <span>
                            {selectedRate ? formatRupiah(shippingCost) : 'Pilih kurir dahulu'}
                        </span>
                    </div>
                    <div className="flex justify-between font-semibold text-foreground">
                        <span>Total</span>
                        <span>{formatRupiah(cart.subtotal + shippingCost)}</span>
                    </div>
                </div>
            </div>
        </div>
    );
}

CheckoutIndex.layout = (page: React.ReactNode) => <StorefrontLayout>{page}</StorefrontLayout>;
