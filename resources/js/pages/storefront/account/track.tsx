import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StorefrontLayout from '@/layouts/storefront-layout';
import { login as customerLogin } from '@/routes/customer';
import { lookup } from '@/routes/track';
import type { SharedData } from '@/types';

export default function TrackOrder() {
    const { googleLoginEnabled, customer } = usePage<SharedData>().props;
    const { data, setData, post, processing, errors } = useForm({
        order_number: '',
        email: '',
    });

    return (
        <div className="mx-auto max-w-sm px-4 py-16 sm:px-6">
            <Head title="Lacak Pesanan" />

            <h1 className="text-center text-2xl font-semibold tracking-tight text-foreground">
                Lacak Pesanan
            </h1>
            <p className="mt-2 text-center text-sm text-muted-foreground">
                Masukkan nomor pesanan dan email yang Anda pakai saat memesan.
            </p>

            <form
                className="mt-8 flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post(lookup().url);
                }}
            >
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="order_number">Nomor Pesanan</Label>
                    <Input
                        id="order_number"
                        value={data.order_number}
                        onChange={(e) =>
                            setData('order_number', e.target.value)
                        }
                        placeholder="ORD-20260924-XXXXXX"
                        autoComplete="off"
                    />
                    <InputError message={errors.order_number} />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="nama@email.com"
                    />
                    <InputError message={errors.email} />
                </div>
                <Button type="submit" disabled={processing}>
                    {processing && (
                        <LoaderCircle className="size-4 animate-spin" />
                    )}
                    Lacak
                </Button>
            </form>

            {googleLoginEnabled && !customer && (
                <p className="mt-8 text-center text-sm text-muted-foreground">
                    Punya akun?{' '}
                    <Link
                        href={customerLogin()}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        Masuk
                    </Link>{' '}
                    untuk melihat semua pesanan Anda.
                </p>
            )}
        </div>
    );
}

TrackOrder.layout = (page: React.ReactNode) => (
    <StorefrontLayout>{page}</StorefrontLayout>
);
