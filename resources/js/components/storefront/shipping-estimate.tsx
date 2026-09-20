import { router, usePage } from '@inertiajs/react';
import { LoaderCircle, Truck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatRupiah } from '@/lib/utils';
import { shippingAreas } from '@/routes/checkout';
import {
    destination as setDestination,
    estimate as estimateRoute,
} from '@/routes/shipping';
import type { SharedData } from '@/types';
import type { ShippingArea } from '@/types/order';
import type { ShippingEstimate as Estimate } from '@/types/shipping';

type Props = {
    /** Omit to estimate the whole cart instead of a single product. */
    productId?: number;
    quantity?: number;
};

export function ShippingEstimate({ productId, quantity = 1 }: Props) {
    const { shippingDestination } = usePage<SharedData>().props;

    const [picking, setPicking] = useState(false);
    const [query, setQuery] = useState('');
    const [areas, setAreas] = useState<ShippingArea[]>([]);
    const [searching, setSearching] = useState(false);
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const [loading, setLoading] = useState(false);

    // Guards against a slow earlier request overwriting a newer answer.
    const requestId = useRef(0);

    const loadEstimate = useCallback(async () => {
        if (!shippingDestination) {
            setEstimate(null);

            return;
        }

        const ticket = ++requestId.current;

        setLoading(true);

        try {
            const url = estimateRoute({
                query: productId ? { product_id: productId, quantity } : {},
            }).url;

            const response = await window.fetch(url, {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();

            if (ticket === requestId.current) {
                setEstimate(body.estimate ?? null);
            }
        } catch {
            if (ticket === requestId.current) {
                setEstimate(null);
            }
        } finally {
            if (ticket === requestId.current) {
                setLoading(false);
            }
        }
    }, [shippingDestination, productId, quantity]);

    useEffect(() => {
        loadEstimate();
    }, [loadEstimate]);

    useEffect(() => {
        if (query.trim().length < 3) {
            setAreas([]);

            return;
        }

        const timer = setTimeout(async () => {
            setSearching(true);

            try {
                const response = await window.fetch(
                    shippingAreas({ query: { q: query } }).url,
                );
                const body = await response.json();
                setAreas(body.areas ?? []);
            } catch {
                setAreas([]);
            } finally {
                setSearching(false);
            }
        }, 350);

        return () => clearTimeout(timer);
    }, [query]);

    const chooseArea = (area: ShippingArea) => {
        router.post(
            setDestination().url,
            { destination_area_id: area.id, destination_area_name: area.name },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPicking(false);
                    setQuery('');
                    setAreas([]);
                },
            },
        );
    };

    return (
        <div className="rounded-lg border border-border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <Truck className="size-4 shrink-0 text-muted-foreground" />

                {!shippingDestination ? (
                    <>
                        <span className="text-muted-foreground">
                            Ongkir belum dihitung.
                        </span>
                        <button
                            type="button"
                            onClick={() => setPicking(true)}
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            Cek ongkir ke daerahmu
                        </button>
                    </>
                ) : (
                    <>
                        <span className="text-muted-foreground">Kirim ke</span>
                        <span className="font-medium text-foreground">
                            {shippingDestination.name}
                        </span>
                        <button
                            type="button"
                            onClick={() => setPicking((open) => !open)}
                            className="text-muted-foreground underline underline-offset-4"
                        >
                            Ubah
                        </button>
                    </>
                )}
            </div>

            {shippingDestination && (
                <p className="mt-1 pl-6">
                    {loading ? (
                        <span className="flex items-center gap-2 text-muted-foreground">
                            <LoaderCircle className="size-3 animate-spin" />
                            Menghitung ongkir…
                        </span>
                    ) : estimate ? (
                        <span className="text-foreground">
                            Ongkir mulai{' '}
                            <span className="font-semibold">
                                {formatRupiah(estimate.price)}
                            </span>{' '}
                            <span className="text-muted-foreground">
                                ({estimate.courier_name}
                                {estimate.duration
                                    ? `, ${estimate.duration}`
                                    : ''}
                                )
                            </span>
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            Ongkir ke daerah ini belum bisa dihitung. Tarif
                            pastinya muncul di halaman checkout.
                        </span>
                    )}
                </p>
            )}

            {picking && (
                <div className="mt-3 flex flex-col gap-2">
                    <Input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Ketik kota atau kecamatan, mis. Bandung"
                    />

                    {searching && (
                        <p className="text-xs text-muted-foreground">
                            Mencari…
                        </p>
                    )}

                    {!searching &&
                        query.trim().length >= 3 &&
                        areas.length === 0 && (
                            <p className="text-xs text-muted-foreground">
                                Daerah tidak ditemukan.
                            </p>
                        )}

                    {areas.length > 0 && (
                        <ul className="max-h-52 divide-y divide-border overflow-auto rounded-md border border-border">
                            {areas.map((area) => (
                                <li key={area.id}>
                                    <button
                                        type="button"
                                        onClick={() => chooseArea(area)}
                                        className="w-full px-3 py-2 text-left text-sm hover:bg-muted"
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

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="self-start"
                        onClick={() => setPicking(false)}
                    >
                        Tutup
                    </Button>
                </div>
            )}
        </div>
    );
}
