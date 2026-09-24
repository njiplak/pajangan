import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { ORDER_STATUS_LABEL } from '@/lib/order-status';
import { formatRupiah } from '@/lib/utils';
import type { OrderStatus } from '@/types/order';

export type AccountOrderSummary = {
    order_number: string;
    status: OrderStatus;
    created_at: string | null;
    total: number;
    item_count: number;
    line_count: number;
    item_names: string[];
    url: string;
};

export function OrderSummaryCard({ order }: { order: AccountOrderSummary }) {
    const date = order.created_at
        ? new Date(order.created_at).toLocaleDateString('id-ID', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          })
        : '';

    return (
        <Link
            href={order.url}
            className="flex items-center gap-4 rounded-xl border border-border p-4 transition-colors hover:bg-accent/50"
        >
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-foreground">
                        {order.order_number}
                    </span>
                    <Badge
                        variant={
                            order.status === 'pending' ? 'default' : 'secondary'
                        }
                    >
                        {ORDER_STATUS_LABEL[order.status] ?? order.status}
                    </Badge>
                </div>
                <p className="mt-1 truncate text-sm text-muted-foreground">
                    {order.item_names.join(', ')}
                    {order.line_count > order.item_names.length ? ', …' : ''}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    {date} · {order.item_count} barang ·{' '}
                    <span className="font-medium text-foreground">
                        {formatRupiah(order.total)}
                    </span>
                </p>
            </div>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
        </Link>
    );
}
