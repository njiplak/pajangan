import { Link, usePage } from '@inertiajs/react';
import { ShoppingBag, Sprout } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { about, home } from '@/routes';
import { index as cartIndex } from '@/routes/cart';
import { index as productsIndex } from '@/routes/products';
import type { SharedData } from '@/types';

type StorefrontLayoutProps = {
    children: ReactNode;
};

export default function StorefrontLayout({ children }: StorefrontLayoutProps) {
    const { cart, settings } = usePage<SharedData>().props;
    const isDisplayMode = settings.storefront_mode === 'display';
    const title = settings.storefront_title ?? 'UMKM Papua.id';
    const subtitle = settings.storefront_subtitle ?? 'Perjuangan Ekonomi Bermartabat';
    const footerDescription =
        settings.storefront_footer_description ??
        'Jembatan yang menghubungkan produk lokal Papua dengan pasar nasional dan global — sebuah gerakan ekonomi lintas batas untuk martabat UMKM Papua.';
    const footerCopyright = settings.storefront_footer_copyright ?? 'Seluruh hak cipta dilindungi.';

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="sticky top-0 z-20 border-b border-border bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/80">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                    <Link href={home()} className="flex items-center gap-2">
                        <div className="flex size-9 items-center justify-center rounded-full bg-primary text-primary-foreground">
                            <Sprout className="size-5" />
                        </div>
                        <div className="leading-tight">
                            <p className="text-sm font-semibold tracking-tight text-foreground">
                                {title}
                            </p>
                            <p className="text-[11px] text-muted-foreground">
                                {subtitle}
                            </p>
                        </div>
                    </Link>

                    <nav className="hidden items-center gap-6 text-sm font-medium text-muted-foreground sm:flex">
                        <Link href={home()} className="transition-colors hover:text-foreground">
                            Beranda
                        </Link>
                        <Link href={productsIndex()} className="transition-colors hover:text-foreground">
                            Produk
                        </Link>
                        <Link href={about()} className="transition-colors hover:text-foreground">
                            Tentang Kami
                        </Link>
                    </nav>

                    <div className="flex items-center gap-3">
                        {!isDisplayMode && (
                            <Link
                                href={cartIndex()}
                                className="relative flex size-9 items-center justify-center rounded-full border border-border text-foreground transition-colors hover:bg-accent"
                            >
                                <ShoppingBag className="size-4" />
                                {cart.count > 0 && (
                                    <Badge className="absolute -top-1.5 -right-1.5 h-5 min-w-5 justify-center rounded-full px-1 text-[10px]">
                                        {cart.count}
                                    </Badge>
                                )}
                            </Link>
                        )}
                    </div>
                </div>

                <nav className="flex items-center gap-5 overflow-x-auto border-t border-border px-4 py-2 text-sm font-medium text-muted-foreground sm:hidden">
                    <Link href={home()} className="whitespace-nowrap">
                        Beranda
                    </Link>
                    <Link href={productsIndex()} className="whitespace-nowrap">
                        Produk
                    </Link>
                    <Link href={about()} className="whitespace-nowrap">
                        Tentang Kami
                    </Link>
                </nav>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-border bg-muted/30">
                <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
                    <div className="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                        <div className="max-w-sm">
                            <p className="text-sm font-semibold text-foreground">{title}</p>
                            <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                {footerDescription}
                            </p>
                        </div>
                        <div className="flex gap-10 text-sm">
                            <div className="flex flex-col gap-2">
                                <p className="font-medium text-foreground">Jelajahi</p>
                                <Link href={home()} className="text-muted-foreground hover:text-foreground">
                                    Beranda
                                </Link>
                                <Link href={productsIndex()} className="text-muted-foreground hover:text-foreground">
                                    Produk
                                </Link>
                                <Link href={about()} className="text-muted-foreground hover:text-foreground">
                                    Tentang Kami
                                </Link>
                            </div>
                        </div>
                    </div>
                    <p className="mt-8 text-xs text-muted-foreground">
                        &copy; {new Date().getFullYear()} {title}. {footerCopyright}
                    </p>
                </div>
            </footer>
        </div>
    );
}
