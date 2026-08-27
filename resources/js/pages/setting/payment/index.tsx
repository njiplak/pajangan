import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { channels, update } from '@/routes/backoffice/setting/payment';

type Gateway = {
    key: string;
    label: string;
    configured: boolean;
};

type Channel = {
    code: string;
    name: string;
    fee_flat: number | null;
    fee_percent: number | null;
};

type ChannelFee = {
    flat: number;
    percent: number;
};

type ChannelFeeMap = Record<string, Record<string, ChannelFee>>;

type Props = {
    gateways: Gateway[];
    current: {
        payment_active_gateway: string | null;
        payment_default_channel: string | null;
        payment_admin_fee_borne_by: string;
        payment_admin_fee_flat: string;
        payment_channel_fees: ChannelFeeMap;
    };
};

function getChannelFee(
    map: ChannelFeeMap,
    gateway: string,
    channel: string,
): ChannelFee {
    return map[gateway]?.[channel] ?? { flat: 0, percent: 0 };
}

function withChannelFee(
    map: ChannelFeeMap,
    gateway: string,
    channel: string,
    entry: ChannelFee,
): ChannelFeeMap {
    return {
        ...map,
        [gateway]: {
            ...(map[gateway] ?? {}),
            [channel]: entry,
        },
    };
}

export default function PaymentSettingIndex({ gateways, current }: Props) {
    const { data, setData, put, errors, processing } = useForm({
        payment_active_gateway: current.payment_active_gateway ?? '',
        payment_default_channel: current.payment_default_channel ?? '',
        payment_admin_fee_borne_by: current.payment_admin_fee_borne_by,
        payment_admin_fee_flat: current.payment_admin_fee_flat,
        payment_channel_fees: current.payment_channel_fees ?? {},
    });

    // Keyed by gateway so the loading/stale state can be derived at render
    // time by comparing against the currently selected gateway, rather
    // than tracked with a separate "loading" flag set synchronously in
    // the effect (which risks a cascading extra render).
    const [channelResult, setChannelResult] = useState<{
        gateway: string;
        channels: Channel[];
        error: string | null;
    } | null>(null);

    useEffect(() => {
        if (!data.payment_active_gateway) {
            return;
        }

        let cancelled = false;
        const gateway = data.payment_active_gateway;

        fetch(channels(gateway).url, {
            headers: { Accept: 'application/json' },
        })
            .then((res) => res.json())
            .then((body: { channels: Channel[]; error: string | null }) => {
                if (cancelled) return;
                setChannelResult({
                    gateway,
                    channels: body.channels,
                    error: body.error,
                });
            })
            .catch(() => {
                if (cancelled) return;
                setChannelResult({
                    gateway,
                    channels: [],
                    error: 'Gagal memuat daftar kanal pembayaran.',
                });
            });

        return () => {
            cancelled = true;
        };
    }, [data.payment_active_gateway]);

    const isCurrentResult =
        channelResult?.gateway === data.payment_active_gateway;
    const loadingChannels = !!data.payment_active_gateway && !isCurrentResult;
    const availableChannels = isCurrentResult ? channelResult.channels : [];
    const channelsError = isCurrentResult ? channelResult.error : null;

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        put(update().url, FormResponse);
    };

    const usesHostedChannelPicker =
        !!data.payment_active_gateway &&
        !loadingChannels &&
        !channelsError &&
        availableChannels.length === 0;

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <div>
                <h1 className="text-xl font-semibold">Pengaturan Pembayaran</h1>
                <p className="text-sm text-muted-foreground">
                    Pilih payment gateway yang aktif dan atur kebijakan biaya
                    admin.
                </p>
            </div>

            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <Label>Payment Gateway Aktif</Label>
                    <Select
                        value={data.payment_active_gateway}
                        onValueChange={(value) => {
                            setData('payment_active_gateway', value);
                            setData('payment_default_channel', '');
                        }}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Pilih payment gateway" />
                        </SelectTrigger>
                        <SelectContent>
                            {gateways.map((gateway) => (
                                <SelectItem
                                    key={gateway.key}
                                    value={gateway.key}
                                >
                                    <span className="flex items-center gap-2">
                                        {gateway.label}
                                        {!gateway.configured && (
                                            <Badge
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                belum dikonfigurasi
                                            </Badge>
                                        )}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors?.payment_active_gateway} />
                    {data.payment_active_gateway &&
                        !gateways.find(
                            (g) => g.key === data.payment_active_gateway,
                        )?.configured && (
                            <p className="text-sm text-amber-600 dark:text-amber-400">
                                Gateway ini belum diisi kredensialnya (lihat
                                konfigurasi .env). Pembayaran akan gagal sampai
                                kredensial diisi.
                            </p>
                        )}
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Kanal Pembayaran Default</Label>
                    {!data.payment_active_gateway ? (
                        <p className="text-sm text-muted-foreground">
                            Pilih payment gateway terlebih dahulu.
                        </p>
                    ) : usesHostedChannelPicker ? (
                        <p className="text-sm text-muted-foreground">
                            Gateway ini menampilkan halaman pembayaran sendiri
                            di mana pelanggan memilih kanal (VA, QRIS, dll)
                            langsung di sana — tidak perlu diatur di sini.
                        </p>
                    ) : channelsError ? (
                        <>
                            <p className="text-sm text-amber-600 dark:text-amber-400">
                                Tidak bisa memuat daftar kanal dari gateway (
                                {channelsError}). Anda bisa mengisi kode kanal
                                secara manual di bawah ini.
                            </p>
                            <Input
                                placeholder="Kode kanal, mis. BRIVA, QRIS"
                                value={data.payment_default_channel}
                                onChange={(e) =>
                                    setData(
                                        'payment_default_channel',
                                        e.target.value,
                                    )
                                }
                            />
                        </>
                    ) : (
                        <Select
                            value={data.payment_default_channel}
                            onValueChange={(value) => {
                                setData('payment_default_channel', value);

                                // Seed a per-channel fee entry from the
                                // channel's own real fee data the first
                                // time it's picked, without clobbering
                                // anything the admin already configured
                                // for it.
                                const gateway = data.payment_active_gateway;
                                const existing =
                                    data.payment_channel_fees[gateway]?.[value];
                                if (!existing) {
                                    const picked = availableChannels.find(
                                        (c) => c.code === value,
                                    );
                                    setData(
                                        'payment_channel_fees',
                                        withChannelFee(
                                            data.payment_channel_fees,
                                            gateway,
                                            value,
                                            {
                                                flat: picked?.fee_flat ?? 0,
                                                percent:
                                                    picked?.fee_percent ?? 0,
                                            },
                                        ),
                                    );
                                }
                            }}
                            disabled={loadingChannels}
                        >
                            <SelectTrigger>
                                <SelectValue
                                    placeholder={
                                        loadingChannels
                                            ? 'Memuat kanal...'
                                            : 'Pilih kanal pembayaran'
                                    }
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {availableChannels.map((channel) => (
                                    <SelectItem
                                        key={channel.code}
                                        value={channel.code}
                                    >
                                        {channel.name} ({channel.code})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <InputError message={errors?.payment_default_channel} />
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label>Biaya Admin Dibebankan ke</Label>
                    <Select
                        value={data.payment_admin_fee_borne_by}
                        onValueChange={(value) =>
                            setData('payment_admin_fee_borne_by', value)
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="merchant">
                                Toko (pelanggan tidak dikenakan biaya tambahan)
                            </SelectItem>
                            <SelectItem value="customer">
                                Pelanggan (ditambahkan ke total pembayaran)
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors?.payment_admin_fee_borne_by} />
                </div>

                {data.payment_admin_fee_borne_by === 'customer' &&
                    (usesHostedChannelPicker ? (
                        <div className="flex flex-col gap-1.5">
                            <Label>Nominal Biaya Admin (Rp)</Label>
                            <Input
                                type="number"
                                min={0}
                                value={data.payment_admin_fee_flat}
                                onChange={(e) =>
                                    setData(
                                        'payment_admin_fee_flat',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={errors?.payment_admin_fee_flat}
                            />
                        </div>
                    ) : (
                        data.payment_default_channel && (
                            <div className="flex flex-col gap-1.5">
                                <Label>
                                    Biaya Admin untuk Kanal{' '}
                                    {data.payment_default_channel}
                                </Label>
                                <div className="flex gap-3">
                                    <div className="flex-1">
                                        <Input
                                            type="number"
                                            min={0}
                                            placeholder="Biaya tetap (Rp)"
                                            value={
                                                getChannelFee(
                                                    data.payment_channel_fees,
                                                    data.payment_active_gateway,
                                                    data.payment_default_channel,
                                                ).flat
                                            }
                                            onChange={(e) =>
                                                setData(
                                                    'payment_channel_fees',
                                                    withChannelFee(
                                                        data.payment_channel_fees,
                                                        data.payment_active_gateway,
                                                        data.payment_default_channel,
                                                        {
                                                            ...getChannelFee(
                                                                data.payment_channel_fees,
                                                                data.payment_active_gateway,
                                                                data.payment_default_channel,
                                                            ),
                                                            flat: Number(
                                                                e.target.value,
                                                            ),
                                                        },
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors?.[
                                                    `payment_channel_fees.${data.payment_active_gateway}.${data.payment_default_channel}.flat`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="flex-1">
                                        <Input
                                            type="number"
                                            min={0}
                                            max={100}
                                            step="0.01"
                                            placeholder="Biaya persentase (%)"
                                            value={
                                                getChannelFee(
                                                    data.payment_channel_fees,
                                                    data.payment_active_gateway,
                                                    data.payment_default_channel,
                                                ).percent
                                            }
                                            onChange={(e) =>
                                                setData(
                                                    'payment_channel_fees',
                                                    withChannelFee(
                                                        data.payment_channel_fees,
                                                        data.payment_active_gateway,
                                                        data.payment_default_channel,
                                                        {
                                                            ...getChannelFee(
                                                                data.payment_channel_fees,
                                                                data.payment_active_gateway,
                                                                data.payment_default_channel,
                                                            ),
                                                            percent: Number(
                                                                e.target.value,
                                                            ),
                                                        },
                                                    ),
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors?.[
                                                    `payment_channel_fees.${data.payment_active_gateway}.${data.payment_default_channel}.percent`
                                                ]
                                            }
                                        />
                                    </div>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Total biaya = tetap + (persentase × subtotal
                                    pesanan). Nilai awal diisi otomatis dari
                                    data kanal jika tersedia, dan bisa diubah.
                                    Tersimpan per kanal — kanal lain tidak
                                    berubah.
                                </p>
                            </div>
                        )
                    ))}

                <div className="flex flex-col gap-2 sm:flex-row">
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

PaymentSettingIndex.layout = (page: React.ReactNode) => (
    <AppLayout>{page}</AppLayout>
);
