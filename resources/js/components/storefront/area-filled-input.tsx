import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { ShippingArea } from '@/types/order';

export type AreaLocks = {
    city: boolean;
    province: boolean;
    postal_code: boolean;
};

export const NO_LOCKS: AreaLocks = {
    city: false,
    province: false,
    postal_code: false,
};

/**
 * A field is locked only when the courier's area actually supplied it, so
 * a missing value never leaves the customer stuck with an empty, read-only
 * box — they type it instead, exactly as before.
 */
export function locksFor(
    area: Pick<ShippingArea, 'city' | 'province' | 'postal_code'>,
): AreaLocks {
    return {
        city: Boolean(area.city),
        province: Boolean(area.province),
        postal_code: Boolean(area.postal_code),
    };
}

type Props = {
    id?: string;
    value: string;
    locked: boolean;
    onChange: (value: string) => void;
    placeholder?: string;
};

/**
 * Keeps the address in step with the courier area it will be shipped to:
 * once filled from the area, the value cannot be edited into something the
 * courier does not agree with. Picking another area changes it.
 */
export function AreaFilledInput({
    id,
    value,
    locked,
    onChange,
    placeholder,
}: Props) {
    return (
        <Input
            id={id}
            value={value}
            readOnly={locked}
            aria-readonly={locked}
            tabIndex={locked ? -1 : undefined}
            title={locked ? 'Diisi otomatis dari area kurir' : undefined}
            onChange={(e) => onChange(e.target.value)}
            placeholder={placeholder}
            className={cn(
                locked &&
                    'cursor-default bg-muted text-muted-foreground focus-visible:ring-0',
            )}
        />
    );
}
