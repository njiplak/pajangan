import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FormResponse } from '@/lib/constant';
import { formatRupiah } from '@/lib/utils';
import { paymentCheck, refund } from '@/routes/backoffice/order';
import type { Order } from '@/types/order';

const PAYMENT_STATUS_LABEL: Record<string, string> = {
    pending: 'Menunggu pembayaran',
    paid: 'Lunas',
    failed: 'Gagal',
    expired: 'Kedaluwarsa',
    cancelled: 'Dibatalkan',
    refunded: 'Sudah dikembalikan',
};

const formatDateTime = (value: string | null) =>
    value ? new Date(value).toLocaleString('id-ID') : '-';

type Props = {
    order: Order;
    canCheckPayment: boolean;
    canRefund: boolean;
    canUpdate: boolean;
};

export default function PaymentPanel({
    order,
    canCheckPayment,
    canRefund,
    canUpdate,
}: Props) {
    const [checking, setChecking] = useState(false);
    const [refundReference, setRefundReference] = useState('');
    const [refunding, setRefunding] = useState(false);

    const onCheck = () => {
        setChecking(true);
        router.post(
            paymentCheck(order.id).url,
            {},
            {
                ...FormResponse,
                preserveScroll: true,
                onFinish: () => setChecking(false),
            },
        );
    };

    const onRefund = () => {
        if (
            !window.confirm(
                `Catat bahwa ${formatRupiah(order.total)} sudah dikembalikan ke pelanggan? Pelanggan akan menerima email konfirmasi.`,
            )
        ) {
            return;
        }

        setRefunding(true);
        router.post(
            refund(order.id).url,
            { refund_reference: refundReference || null },
            {
                ...FormResponse,
                preserveScroll: true,
                onFinish: () => setRefunding(false),
            },
        );
    };

    return (
        <div className="rounded-xl border border-border bg-card p-5 text-sm">
            <h2 className="font-semibold text-foreground">Pembayaran</h2>
            <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                <dt className="text-muted-foreground">Metode</dt>
                <dd className="text-foreground">
                    {order.payment_gateway ?? '-'}
                    {order.payment_channel ? ` · ${order.payment_channel}` : ''}
                </dd>
                <dt className="text-muted-foreground">Status</dt>
                <dd className="text-foreground">
                    {order.payment_status
                        ? (PAYMENT_STATUS_LABEL[order.payment_status] ??
                          order.payment_status)
                        : '-'}
                </dd>
                <dt className="text-muted-foreground">Dibayar</dt>
                <dd className="text-foreground">
                    {formatDateTime(order.paid_at)}
                </dd>
                {order.refunded_at && (
                    <>
                        <dt className="text-muted-foreground">Dikembalikan</dt>
                        <dd className="text-foreground">
                            {formatDateTime(order.refunded_at)}
                            {order.refund_reference
                                ? ` · ${order.refund_reference}`
                                : ''}
                        </dd>
                    </>
                )}
            </dl>

            {canUpdate && canCheckPayment && (
                <div className="mt-4">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={checking}
                        onClick={onCheck}
                    >
                        {checking ? 'Mengecek...' : 'Cek ke Payment Gateway'}
                    </Button>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Tanyakan langsung ke gateway bila pelanggan mengaku
                        sudah membayar tetapi status belum berubah.
                    </p>
                </div>
            )}

            {canUpdate && canRefund && (
                <div className="mt-4 flex flex-col gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3">
                    <p className="text-foreground">
                        Pesanan ini dibatalkan setelah dibayar. Kembalikan{' '}
                        {formatRupiah(order.total)} ke pelanggan, lalu catat di
                        sini.
                    </p>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="refund-reference">
                            Referensi transfer (opsional)
                        </Label>
                        <Input
                            id="refund-reference"
                            value={refundReference}
                            onChange={(e) => setRefundReference(e.target.value)}
                            placeholder="Misal: TRF-BCA-0925"
                        />
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        className="self-start"
                        disabled={refunding}
                        onClick={onRefund}
                    >
                        {refunding ? 'Menyimpan...' : 'Catat Dana Dikembalikan'}
                    </Button>
                </div>
            )}
        </div>
    );
}
