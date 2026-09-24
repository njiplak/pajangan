import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { shippingAreas } from '@/routes/checkout';
import type { ShippingArea } from '@/types/order';

type Props = {
    id?: string;
    /** The currently chosen area's name, shown until the user searches. */
    value: string;
    onSelect: (area: ShippingArea) => void;
};

/**
 * Courier-area search, the same lookup checkout uses, so a saved address
 * carries an area id that can be quoted without searching again.
 */
export function AreaSearch({ id, value, onSelect }: Props) {
    const [query, setQuery] = useState(value);
    const [areas, setAreas] = useState<ShippingArea[]>([]);
    const [searching, setSearching] = useState(false);

    useEffect(() => {
        if (query === value || query.trim().length < 3) {
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
    }, [query, value]);

    return (
        <div className="flex flex-col gap-2">
            <Input
                id={id}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Ketik kecamatan atau kota, mis. Abepura"
                autoComplete="off"
            />
            {searching && (
                <p className="text-xs text-muted-foreground">Mencari…</p>
            )}
            {areas.length > 0 && (
                <ul className="max-h-52 divide-y divide-border overflow-auto rounded-md border border-border">
                    {areas.map((area) => (
                        <li key={area.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onSelect(area);
                                    setQuery(area.name);
                                    setAreas([]);
                                }}
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
        </div>
    );
}
