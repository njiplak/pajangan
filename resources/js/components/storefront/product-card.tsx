import { Link } from '@inertiajs/react';
import { Card } from '@/components/ui/card';
import { formatRupiah } from '@/lib/utils';
import { show as productShow } from '@/routes/products';
import type { ProductSummary } from '@/types/product';

export function ProductCard({ product }: { product: ProductSummary }) {
    const hasDiscount = !!product.discount_percent && product.discount_percent > 0;

    return (
        <Link href={productShow(product.slug)} className="group block">
            <Card className="gap-0 overflow-hidden py-0">
                <div className="relative aspect-square w-full overflow-hidden bg-muted">
                    {product.image ? (
                        <img
                            src={product.image}
                            alt={product.name}
                            className="h-full w-full object-cover transition-transform group-hover:scale-105"
                        />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">
                            Tanpa gambar
                        </div>
                    )}
                    {hasDiscount && (
                        <span className="absolute top-2 left-2 rounded-full bg-destructive px-2 py-0.5 text-[10px] font-semibold text-destructive-foreground">
                            -{product.discount_percent}%
                        </span>
                    )}
                </div>
                <div className="flex flex-col gap-1 p-3">
                    <p className="line-clamp-2 text-sm font-medium text-foreground">
                        {product.name}
                    </p>
                    <div className="flex items-center gap-2">
                        <p className="text-sm font-semibold text-foreground">
                            {formatRupiah(product.effective_price)}
                        </p>
                        {hasDiscount && (
                            <p className="text-xs text-muted-foreground line-through">
                                {formatRupiah(product.price)}
                            </p>
                        )}
                    </div>
                    {product.producer_region && (
                        <p className="truncate text-xs text-muted-foreground">
                            {product.producer_region}
                        </p>
                    )}
                    {product.stock <= 0 && (
                        <span className="text-xs font-medium text-destructive">
                            Stok habis
                        </span>
                    )}
                </div>
            </Card>
        </Link>
    );
}
