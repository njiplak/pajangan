import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle, UserRound } from 'lucide-react';
import InputError from '@/components/input-error';
import { AccountNav } from '@/components/storefront/account-nav';
import { OrderSummaryCard } from '@/components/storefront/order-summary-card';
import type { AccountOrderSummary } from '@/components/storefront/order-summary-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StorefrontLayout from '@/layouts/storefront-layout';
import { orders as accountOrders, profile } from '@/routes/account';
import { index as productsIndex } from '@/routes/products';

type Props = {
    profile: {
        name: string;
        email: string;
        phone: string | null;
        avatar_url: string | null;
    };
    recentOrders: AccountOrderSummary[];
    orderCount: number;
};

export default function AccountIndex({
    profile: me,
    recentOrders,
    orderCount,
}: Props) {
    const { data, setData, put, processing, errors, recentlySuccessful } =
        useForm({ name: me.name, phone: me.phone ?? '' });

    return (
        <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
            <Head title="Akun Saya" />

            <div className="flex items-center gap-4">
                <div className="flex size-14 items-center justify-center overflow-hidden rounded-full bg-muted">
                    {me.avatar_url ? (
                        <img
                            src={me.avatar_url}
                            alt=""
                            referrerPolicy="no-referrer"
                            className="size-full object-cover"
                        />
                    ) : (
                        <UserRound className="size-6 text-muted-foreground" />
                    )}
                </div>
                <div>
                    <h1 className="text-xl font-semibold text-foreground">
                        Halo, {me.name}
                    </h1>
                    <p className="text-sm text-muted-foreground">{me.email}</p>
                </div>
            </div>

            <div className="mt-8">
                <AccountNav active="overview" />
            </div>

            <section className="mt-8">
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-foreground">
                        Pesanan Terbaru
                    </h2>
                    {orderCount > recentOrders.length && (
                        <Link
                            href={accountOrders()}
                            className="text-sm text-muted-foreground underline underline-offset-4"
                        >
                            Lihat semua ({orderCount})
                        </Link>
                    )}
                </div>
                <div className="mt-4 flex flex-col gap-3">
                    {recentOrders.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                            Belum ada pesanan.{' '}
                            <Link
                                href={productsIndex()}
                                className="font-medium text-foreground underline underline-offset-4"
                            >
                                Mulai belanja
                            </Link>
                        </div>
                    ) : (
                        recentOrders.map((order) => (
                            <OrderSummaryCard
                                key={order.order_number}
                                order={order}
                            />
                        ))
                    )}
                </div>
            </section>

            <section className="mt-10 rounded-xl border border-border p-5">
                <h2 className="font-semibold text-foreground">Profil</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Dipakai untuk mengisi data pemesan secara otomatis.
                </p>
                <form
                    className="mt-4 grid gap-4 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        put(profile().url, { preserveScroll: true });
                    }}
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="name">Nama</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="phone">No. HP</Label>
                        <Input
                            id="phone"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="08xxxxxxxxxx"
                        />
                        <InputError message={errors.phone} />
                    </div>
                    <div className="flex items-center gap-3 sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {processing && (
                                <LoaderCircle className="size-4 animate-spin" />
                            )}
                            Simpan
                        </Button>
                        {recentlySuccessful && (
                            <span className="text-sm text-muted-foreground">
                                Tersimpan.
                            </span>
                        )}
                    </div>
                </form>
            </section>
        </div>
    );
}

AccountIndex.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
