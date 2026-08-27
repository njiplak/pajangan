import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
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
import { formatRupiah, getCsrfToken } from '@/lib/utils';
import {
    shippingAreas,
    shippingCancel,
    shippingCreate,
    shippingRates,
    updateShipping,
    updateStatus,
} from '@/routes/backoffice/order';
import type {
    Order,
    OrderStatus,
    ShippingArea,
    ShippingCollectionMethod,
    ShippingRateOption,
} from '@/types/order';

const STATUS_LABEL: Record<string, string> = {
    pending: 'Menunggu Pembayaran',
    paid: 'Dibayar',
    processing: 'Diproses',
    shipped: 'Dikirim',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

const COLLECTION_METHOD_LABEL: Record<ShippingCollectionMethod, string> = {
    pickup: 'Dijemput Kurir',
    drop_off: 'Antar Sendiri',
};

const CANCELLATION_REASON_LABEL: Record<string, string> = {
    change_courier: 'Ganti Kurir',
    pickup_delay: 'Penjemputan Terlambat',
    change_address: 'Ganti Alamat',
    others: 'Lainnya',
};

type Props = {
    order: Order;
    statuses: OrderStatus[];
    preferredCollectionMethod: ShippingCollectionMethod | null;
};

export default function OrderShow({
    order,
    statuses,
    preferredCollectionMethod,
}: Props) {
    const [status, setStatus] = useState<OrderStatus>(order.status);
    const [processing, setProcessing] = useState(false);

    const onStatusChange = (value: string) => {
        setStatus(value as OrderStatus);
        setProcessing(true);
        router.put(
            updateStatus(order.id).url,
            { status: value },
            {
                ...FormResponse,
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const [areaQuery, setAreaQuery] = useState(order.shipping_area_name ?? '');
    const [areas, setAreas] = useState<ShippingArea[]>([]);
    const [selectedArea, setSelectedArea] = useState<ShippingArea | null>(
        order.shipping_area_id && order.shipping_area_name
            ? {
                  id: order.shipping_area_id,
                  name: order.shipping_area_name,
                  postal_code: null,
              }
            : null,
    );
    const [weightGram, setWeightGram] = useState(1000);
    const [rates, setRates] = useState<ShippingRateOption[]>([]);
    const [selectedRate, setSelectedRate] = useState<ShippingRateOption | null>(
        null,
    );
    const [trackingNumber, setTrackingNumber] = useState(
        order.tracking_number ?? '',
    );
    const [loadingRates, setLoadingRates] = useState(false);
    const [savingShipping, setSavingShipping] = useState(false);
    const [shippingError, setShippingError] = useState<string | null>(null);

    useEffect(() => {
        if (selectedArea && areaQuery === selectedArea.name) return;
        if (areaQuery.trim().length < 3) {
            setAreas([]);
            return;
        }

        const timeout = setTimeout(async () => {
            const response = await window.fetch(
                shippingAreas({ query: { q: areaQuery } }).url,
            );
            const body = await response.json();
            setAreas(body.areas ?? []);
        }, 400);

        return () => clearTimeout(timeout);
    }, [areaQuery, selectedArea]);

    const onSelectArea = (area: ShippingArea) => {
        setSelectedArea(area);
        setAreaQuery(area.name);
        setAreas([]);
        setRates([]);
        setSelectedRate(null);
    };

    const onCheckRates = async () => {
        if (!selectedArea) return;

        setLoadingRates(true);
        setShippingError(null);
        setRates([]);
        setSelectedRate(null);

        try {
            const response = await window.fetch(shippingRates(order.id).url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    destination_area_id: selectedArea.id,
                    weight_gram: weightGram,
                }),
            });
            const body = await response.json();

            if (!response.ok) {
                setShippingError(
                    body.message ?? 'Gagal mengambil tarif pengiriman.',
                );
                return;
            }

            setRates(body.rates ?? []);
        } catch {
            setShippingError('Gagal mengambil tarif pengiriman.');
        } finally {
            setLoadingRates(false);
        }
    };

    const sortedRates = preferredCollectionMethod
        ? [...rates].sort((a, b) => {
              const aMatches = a.collection_methods.includes(
                  preferredCollectionMethod,
              )
                  ? 0
                  : 1;
              const bMatches = b.collection_methods.includes(
                  preferredCollectionMethod,
              )
                  ? 0
                  : 1;

              return aMatches - bMatches;
          })
        : rates;

    const [creatingShipment, setCreatingShipment] = useState(false);

    const onCreateShipment = () => {
        if (!selectedArea || !selectedRate) return;

        if (
            !window.confirm(
                `Buat pengiriman nyata di Biteship via ${selectedRate.courier_name} - ${selectedRate.courier_service_name}? Tindakan ini memakai saldo Biteship dan tidak bisa dibatalkan dari sini.`,
            )
        ) {
            return;
        }

        setCreatingShipment(true);
        router.post(
            shippingCreate(order.id).url,
            {
                destination_area_id: selectedArea.id,
                destination_area_name: selectedArea.name,
                weight_gram: weightGram,
                courier_code: selectedRate.courier_code,
                courier_service_code: selectedRate.courier_service_code,
            },
            {
                ...FormResponse,
                preserveScroll: true,
                onFinish: () => setCreatingShipment(false),
            },
        );
    };

    const [showCancelForm, setShowCancelForm] = useState(false);
    const [cancelReasonCode, setCancelReasonCode] = useState('change_courier');
    const [cancelReasonText, setCancelReasonText] = useState('');
    const [cancellingShipment, setCancellingShipment] = useState(false);

    const onCancelShipment = () => {
        if (
            !window.confirm(
                'Batalkan pengiriman ini di Biteship? Kurir yang sudah dialokasikan mungkin tidak langsung berhenti — ini hanya meminta pembatalan ke Biteship.',
            )
        ) {
            return;
        }

        setCancellingShipment(true);
        router.post(
            shippingCancel(order.id).url,
            {
                cancellation_reason_code: cancelReasonCode,
                cancellation_reason:
                    cancelReasonCode === 'others' ? cancelReasonText : null,
            },
            {
                ...FormResponse,
                preserveScroll: true,
                onSuccess: () => setShowCancelForm(false),
                onFinish: () => setCancellingShipment(false),
            },
        );
    };

    const courierCode = selectedRate?.courier_code ?? order.courier_code;
    const courierName = selectedRate?.courier_name ?? order.courier_name;
    const courierService =
        selectedRate?.courier_service_name ?? order.courier_service;
    const courierEtd = selectedRate?.duration ?? order.courier_etd;
    const shippingCost = selectedRate?.price ?? order.shipping_cost;
    const canSaveShipping = Boolean(
        courierCode && courierService && shippingCost != null,
    );

    const onSaveShipping = () => {
        if (!canSaveShipping) return;

        setSavingShipping(true);
        router.put(
            updateShipping(order.id).url,
            {
                shipping_cost: shippingCost,
                shipping_area_id: selectedArea?.id ?? order.shipping_area_id,
                shipping_area_name:
                    selectedArea?.name ?? order.shipping_area_name,
                courier_code: courierCode,
                courier_name: courierName,
                courier_service: courierService,
                courier_etd: courierEtd,
                tracking_number: trackingNumber || null,
            },
            {
                ...FormResponse,
                preserveScroll: true,
                onFinish: () => setSavingShipping(false),
            },
        );
    };

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold">
                        Pesanan {order.order_number}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Dibuat pada{' '}
                        {new Date(order.created_at).toLocaleString('id-ID')}
                    </p>
                </div>
                <Select
                    value={status}
                    onValueChange={onStatusChange}
                    disabled={processing}
                >
                    <SelectTrigger className="w-56">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {statuses.map((s) => (
                            <SelectItem key={s} value={s}>
                                {STATUS_LABEL[s] ?? s}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="rounded-xl border border-border bg-card p-5 lg:col-span-2">
                    <h2 className="font-semibold text-foreground">
                        Produk Dipesan
                    </h2>
                    <div className="mt-4 flex flex-col gap-3">
                        {order.items.map((item) => (
                            <div
                                key={item.id}
                                className="flex justify-between text-sm"
                            >
                                <span className="text-muted-foreground">
                                    {item.product_name} × {item.quantity}
                                </span>
                                <span className="font-medium text-foreground">
                                    {formatRupiah(item.subtotal)}
                                </span>
                            </div>
                        ))}
                    </div>
                    <div className="mt-4 flex justify-between border-t border-border pt-4 text-sm font-semibold text-foreground">
                        <span>Total</span>
                        <span>{formatRupiah(order.total)}</span>
                    </div>
                </div>

                <div className="rounded-xl border border-border bg-card p-5 text-sm">
                    <h2 className="font-semibold text-foreground">Pelanggan</h2>
                    <p className="mt-2 text-muted-foreground">
                        {order.customer_name}
                    </p>
                    <p className="text-muted-foreground">
                        {order.customer_email}
                    </p>
                    <p className="text-muted-foreground">
                        {order.customer_phone}
                    </p>

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
                                Catatan
                            </h2>
                            <p className="mt-2 text-muted-foreground">
                                {order.notes}
                            </p>
                        </>
                    )}
                </div>
            </div>

            <div className="rounded-xl border border-border bg-card p-5 text-sm">
                <h2 className="font-semibold text-foreground">
                    Pengiriman (Biteship)
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Cari tujuan dan cek tarif kurir, lalu simpan kurir yang
                    dipilih ke pesanan ini. Nomor resi diisi manual setelah
                    paket diserahkan ke kurir.
                </p>

                {order.courier_name && (
                    <div className="mt-4 rounded-lg border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
                        Tersimpan saat ini: {order.courier_name} -{' '}
                        {order.courier_service}
                        {order.courier_etd
                            ? ` (${order.courier_etd})`
                            : ''} · {formatRupiah(order.shipping_cost ?? 0)}
                        {order.tracking_number
                            ? ` · Resi: ${order.tracking_number}`
                            : ' · Menunggu nomor resi dari Biteship'}
                        {order.biteship_order_id && (
                            <span className="mt-1 block">
                                Pengiriman sudah dibuat di Biteship (order:{' '}
                                {order.biteship_order_id}). Resi akan terisi
                                otomatis begitu kurir dialokasikan.
                            </span>
                        )}
                    </div>
                )}

                {order.biteship_order_id && (
                    <div className="mt-3">
                        {!showCancelForm ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="destructive"
                                onClick={() => setShowCancelForm(true)}
                            >
                                Batalkan Pengiriman
                            </Button>
                        ) : (
                            <div className="flex flex-col gap-2 rounded-lg border border-destructive/30 p-3">
                                <div className="flex flex-col gap-1.5">
                                    <Label>Alasan Pembatalan</Label>
                                    <Select
                                        value={cancelReasonCode}
                                        onValueChange={setCancelReasonCode}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(
                                                CANCELLATION_REASON_LABEL,
                                            ).map(([code, label]) => (
                                                <SelectItem
                                                    key={code}
                                                    value={code}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                {cancelReasonCode === 'others' && (
                                    <Textarea
                                        rows={2}
                                        placeholder="Jelaskan alasan pembatalan"
                                        value={cancelReasonText}
                                        onChange={(e) =>
                                            setCancelReasonText(e.target.value)
                                        }
                                    />
                                )}
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="destructive"
                                        disabled={
                                            cancellingShipment ||
                                            (cancelReasonCode === 'others' &&
                                                !cancelReasonText.trim())
                                        }
                                        onClick={onCancelShipment}
                                    >
                                        {cancellingShipment
                                            ? 'Membatalkan...'
                                            : 'Konfirmasi Batalkan'}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setShowCancelForm(false)}
                                    >
                                        Batal
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {shippingError && (
                    <p className="mt-3 text-xs text-destructive">
                        {shippingError}
                    </p>
                )}

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <div className="relative flex flex-col gap-1.5">
                        <Label>Tujuan (kota/kecamatan/kode pos)</Label>
                        <Input
                            value={areaQuery}
                            onChange={(e) => {
                                setAreaQuery(e.target.value);
                                setSelectedArea(null);
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
                                            {area.postal_code
                                                ? ` (${area.postal_code})`
                                                : ''}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label>Berat Paket (gram)</Label>
                        <Input
                            type="number"
                            min={1}
                            value={weightGram}
                            onChange={(e) =>
                                setWeightGram(Number(e.target.value))
                            }
                        />
                    </div>
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-4"
                    disabled={!selectedArea || loadingRates}
                    onClick={onCheckRates}
                >
                    {loadingRates ? 'Mengecek tarif...' : 'Cek Tarif'}
                </Button>

                {rates.length > 0 && (
                    <div className="mt-4 flex flex-col gap-2">
                        {sortedRates.map((rate) => (
                            <button
                                type="button"
                                key={`${rate.courier_code}-${rate.courier_service_code}`}
                                onClick={() => setSelectedRate(rate)}
                                className={`flex items-center justify-between rounded-lg border p-3 text-left text-sm transition-colors ${
                                    selectedRate?.courier_service_code ===
                                        rate.courier_service_code &&
                                    selectedRate?.courier_code ===
                                        rate.courier_code
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border hover:bg-accent'
                                }`}
                            >
                                <span>
                                    <span className="font-medium text-foreground">
                                        {rate.courier_name} -{' '}
                                        {rate.courier_service_name}
                                    </span>
                                    <span className="mt-0.5 flex flex-wrap gap-1">
                                        {rate.duration && (
                                            <span className="text-xs text-muted-foreground">
                                                {rate.duration}
                                            </span>
                                        )}
                                        {rate.collection_methods.map(
                                            (method) => (
                                                <span
                                                    key={method}
                                                    className={`rounded-full px-1.5 py-0.5 text-[10px] ${
                                                        method ===
                                                        preferredCollectionMethod
                                                            ? 'bg-primary/10 text-primary'
                                                            : 'bg-muted text-muted-foreground'
                                                    }`}
                                                >
                                                    {
                                                        COLLECTION_METHOD_LABEL[
                                                            method
                                                        ]
                                                    }
                                                </span>
                                            ),
                                        )}
                                    </span>
                                </span>
                                <span className="font-semibold text-foreground">
                                    {formatRupiah(rate.price)}
                                </span>
                            </button>
                        ))}
                    </div>
                )}

                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    className="mt-4"
                    disabled={
                        !selectedArea ||
                        !selectedRate ||
                        Boolean(order.biteship_order_id) ||
                        creatingShipment
                    }
                    onClick={onCreateShipment}
                >
                    {order.biteship_order_id
                        ? 'Pengiriman Sudah Dibuat'
                        : creatingShipment
                          ? 'Membuat Pengiriman...'
                          : 'Buat Pengiriman di Biteship'}
                </Button>
                <p className="mt-1 text-xs text-muted-foreground">
                    Membuat pengiriman nyata (memakai saldo Biteship, bukan
                    sekadar cek tarif). Nomor resi terisi otomatis begitu kurir
                    dialokasikan.
                </p>

                <div className="mt-4 flex flex-col gap-1.5 sm:max-w-xs">
                    <Label>Nomor Resi (opsional)</Label>
                    <Input
                        value={trackingNumber}
                        onChange={(e) => setTrackingNumber(e.target.value)}
                        placeholder="Diisi setelah paket diserahkan ke kurir"
                    />
                </div>

                <Button
                    type="button"
                    size="sm"
                    className="mt-4"
                    disabled={!canSaveShipping || savingShipping}
                    onClick={onSaveShipping}
                >
                    {savingShipping ? 'Menyimpan...' : 'Simpan Info Pengiriman'}
                </Button>
            </div>
        </div>
    );
}

OrderShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
