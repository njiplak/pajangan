import { Link } from '@inertiajs/react';
import { AlertTriangle, Package, ShoppingCart, Wallet } from 'lucide-react';
import { CartesianGrid, Line, LineChart, XAxis } from 'recharts';
import { Badge } from '@/components/ui/badge';
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart';
import AppLayout from '@/layouts/app-layout';
import { formatRupiah } from '@/lib/utils';
import { show as productShow } from '@/routes/backoffice/product';
import { show as orderShow } from '@/routes/backoffice/order';
import type { Order, OrderStatus } from '@/types/order';

type Props = {
    revenueToday: number;
    revenueMonth: number;
    pendingOrdersCount: number;
    lowStockCount: number;
    ordersByStatus: Record<OrderStatus, number>;
    revenueTrend: { date: string; revenue: number }[];
    lowStockProducts: { id: number; name: string; stock: number }[];
    recentOrders: Pick<Order, 'id' | 'order_number' | 'customer_name' | 'total' | 'status' | 'created_at'>[];
};

const STATUS_LABEL: Record<string, string> = {
    pending: 'Menunggu Pembayaran',
    paid: 'Dibayar',
    processing: 'Diproses',
    shipped: 'Dikirim',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

const chartConfig: ChartConfig = {
    revenue: {
        label: 'Pendapatan',
        color: 'var(--primary)',
    },
};

function StatTile({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string;
    icon: React.ComponentType<{ className?: string }>;
}) {
    return (
        <div className="flex items-center gap-4 rounded-xl border border-border bg-card p-5 shadow-sm">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                <Icon className="size-5" />
            </div>
            <div>
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="text-lg font-semibold text-foreground">{value}</p>
            </div>
        </div>
    );
}

export default function Backoffice({
    revenueToday,
    revenueMonth,
    pendingOrdersCount,
    lowStockCount,
    ordersByStatus,
    revenueTrend,
    lowStockProducts,
    recentOrders,
}: Props) {
    return (
        <div className="flex flex-col gap-6">
            <h1 className="text-2xl font-bold">Dashboard</h1>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Pendapatan Hari Ini" value={formatRupiah(revenueToday)} icon={Wallet} />
                <StatTile label="Pendapatan Bulan Ini" value={formatRupiah(revenueMonth)} icon={Wallet} />
                <StatTile label="Pesanan Menunggu" value={String(pendingOrdersCount)} icon={ShoppingCart} />
                <StatTile label="Stok Menipis" value={String(lowStockCount)} icon={AlertTriangle} />
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="flex flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-sm lg:col-span-2">
                    <h2 className="font-semibold text-foreground">Tren Pendapatan (14 Hari Terakhir)</h2>
                    <ChartContainer config={chartConfig} className="aspect-auto h-64 w-full">
                        <LineChart data={revenueTrend} margin={{ left: 12, right: 12 }}>
                            <CartesianGrid vertical={false} />
                            <XAxis
                                dataKey="date"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                                tickFormatter={(value: string) =>
                                    new Date(value).toLocaleDateString('id-ID', {
                                        day: 'numeric',
                                        month: 'short',
                                    })
                                }
                            />
                            <ChartTooltip
                                content={
                                    <ChartTooltipContent
                                        formatter={(value) => formatRupiah(Number(value))}
                                    />
                                }
                            />
                            <Line
                                dataKey="revenue"
                                type="monotone"
                                stroke="var(--color-revenue)"
                                strokeWidth={2}
                                dot={false}
                            />
                        </LineChart>
                    </ChartContainer>
                </div>

                <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="font-semibold text-foreground">Pesanan per Status</h2>
                    <div className="flex flex-col gap-2">
                        {Object.entries(ordersByStatus).map(([status, count]) => (
                            <div key={status} className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    {STATUS_LABEL[status] ?? status}
                                </span>
                                <Badge variant="secondary">{count}</Badge>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="font-semibold text-foreground">Stok Menipis</h2>
                    {lowStockProducts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada produk dengan stok menipis.
                        </p>
                    ) : (
                        <div className="flex flex-col divide-y divide-border">
                            {lowStockProducts.map((product) => (
                                <Link
                                    key={product.id}
                                    href={productShow(product.id).url}
                                    className="flex items-center justify-between py-2 text-sm hover:text-primary"
                                >
                                    <span className="text-foreground">{product.name}</span>
                                    <Badge variant="destructive">Stok: {product.stock}</Badge>
                                </Link>
                            ))}
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-sm">
                    <h2 className="font-semibold text-foreground">Pesanan Terbaru</h2>
                    {recentOrders.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada pesanan.</p>
                    ) : (
                        <div className="flex flex-col divide-y divide-border">
                            {recentOrders.map((order) => (
                                <Link
                                    key={order.id}
                                    href={orderShow(order.id).url}
                                    className="flex items-center justify-between py-2 text-sm hover:text-primary"
                                >
                                    <div>
                                        <p className="font-medium text-foreground">
                                            {order.order_number}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {order.customer_name}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="font-medium text-foreground">
                                            {formatRupiah(order.total)}
                                        </p>
                                        <Badge variant="secondary" className="mt-1">
                                            {STATUS_LABEL[order.status] ?? order.status}
                                        </Badge>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-2 text-muted-foreground">
                <Package className="size-4" />
                <p className="text-xs">
                    Data diperbarui setiap kali halaman ini dibuka.
                </p>
            </div>
        </div>
    );
}

Backoffice.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
