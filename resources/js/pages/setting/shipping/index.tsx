import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { FormResponse } from '@/lib/constant';
import { update } from '@/routes/backoffice/setting/shipping';

type Provider = {
    key: string;
    label: string;
    configured: boolean;
};

type Props = {
    providers: Provider[];
    current: {
        shipping_preferred_collection_method: string | null;
    };
};

export default function ShippingSettingIndex({ providers, current }: Props) {
    const { data, setData, put, errors, processing } = useForm({
        shipping_preferred_collection_method:
            current.shipping_preferred_collection_method ?? '',
    });

    const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        put(update().url, FormResponse);
    };

    return (
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <div>
                <h1 className="text-xl font-semibold">Pengaturan Pengiriman</h1>
                <p className="text-sm text-muted-foreground">
                    Status penyedia ongkir dan preferensi metode pengambilan
                    paket.
                </p>
            </div>

            <div className="flex flex-col gap-2">
                {providers.map((provider) => (
                    <div
                        key={provider.key}
                        className="flex items-center justify-between rounded-lg border border-border p-3 text-sm"
                    >
                        <span className="font-medium text-foreground">
                            {provider.label}
                        </span>
                        {provider.configured ? (
                            <Badge variant="secondary" className="text-xs">
                                Terkonfigurasi
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="text-xs">
                                belum dikonfigurasi
                            </Badge>
                        )}
                    </div>
                ))}
                <p className="text-xs text-muted-foreground">
                    Kredensial (API key, alamat pengirim, dll) diisi lewat
                    konfigurasi .env server, bukan di sini.
                </p>
            </div>

            <form onSubmit={onSubmit} className="space-y-4">
                <div className="flex flex-col gap-1.5">
                    <label className="text-sm font-medium text-foreground">
                        Metode Pengambilan Paket Pilihan
                    </label>
                    <Select
                        value={data.shipping_preferred_collection_method}
                        onValueChange={(value) =>
                            setData(
                                'shipping_preferred_collection_method',
                                value,
                            )
                        }
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Tidak ada preferensi" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pickup">
                                Dijemput Kurir
                            </SelectItem>
                            <SelectItem value="drop_off">
                                Antar Sendiri ke Counter
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError
                        message={errors?.shipping_preferred_collection_method}
                    />
                    <p className="text-xs text-muted-foreground">
                        Dipakai untuk mengurutkan opsi tarif kurir di halaman
                        detail pesanan — kurir yang tidak mendukung metode ini
                        tetap tampil, hanya diurutkan lebih bawah.
                    </p>
                </div>

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

ShippingSettingIndex.layout = (page: React.ReactNode) => (
    <AppLayout>{page}</AppLayout>
);
