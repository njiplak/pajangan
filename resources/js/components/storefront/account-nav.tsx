import { Link, router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    index as accountIndex,
    orders as accountOrders,
} from '@/routes/account';
import { index as addressesIndex } from '@/routes/account/addresses';
import { index as wishlistIndex } from '@/routes/account/wishlist';
import { logout } from '@/routes/customer';

type Tab = 'overview' | 'orders' | 'addresses' | 'wishlist';

const TABS: { key: Tab; label: string; href: string }[] = [
    { key: 'overview', label: 'Ringkasan', href: accountIndex().url },
    { key: 'orders', label: 'Pesanan', href: accountOrders().url },
    { key: 'addresses', label: 'Alamat', href: addressesIndex().url },
    { key: 'wishlist', label: 'Wishlist', href: wishlistIndex().url },
];

export function AccountNav({ active }: { active: Tab }) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-border">
            <nav className="-mb-px flex gap-6 overflow-x-auto text-sm font-medium">
                {TABS.map((tab) => (
                    <Link
                        key={tab.key}
                        href={tab.href}
                        className={cn(
                            'border-b-2 pb-3 whitespace-nowrap transition-colors',
                            tab.key === active
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>
            <button
                type="button"
                onClick={() => router.post(logout().url)}
                className="flex items-center gap-1.5 pb-3 text-sm text-muted-foreground hover:text-foreground"
            >
                <LogOut className="size-4" />
                Keluar
            </button>
        </div>
    );
}
