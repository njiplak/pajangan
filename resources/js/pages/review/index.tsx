import { Link, router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index, visibility } from '@/routes/backoffice/review';

type Review = {
    id: number;
    rating: number;
    body: string | null;
    is_visible: boolean;
    created_at: string | null;
    product: { id: number; name: string; slug: string } | null;
    customer: { name: string; email: string } | null;
};

type PaginationLink = { url: string | null; label: string; active: boolean };

type Props = {
    reviews: { data: Review[]; links: PaginationLink[] };
    filter: 'visible' | 'hidden' | null;
};

const FILTERS: { key: Props['filter']; label: string }[] = [
    { key: null, label: 'Semua' },
    { key: 'visible', label: 'Tampil' },
    { key: 'hidden', label: 'Disembunyikan' },
];

export default function ReviewIndex({ reviews, filter }: Props) {
    const setVisible = (review: Review, isVisible: boolean) =>
        router.put(
            visibility(review.id).url,
            { is_visible: isVisible },
            { preserveScroll: true },
        );

    return (
        <div className="flex flex-col gap-4">
            <div>
                <h1 className="text-xl font-semibold">Ulasan Produk</h1>
                <p className="text-sm text-muted-foreground">
                    Ulasan dari pembeli yang pesanannya sudah sampai.
                    Sembunyikan ulasan yang tidak pantas; pembeli tidak bisa
                    menampilkannya kembali.
                </p>
            </div>

            <div className="flex gap-2">
                {FILTERS.map((f) => (
                    <Link
                        key={f.label}
                        href={
                            index({ query: f.key ? { status: f.key } : {} }).url
                        }
                        className={cn(
                            'rounded-md px-3 py-1.5 text-sm',
                            filter === f.key
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {f.label}
                    </Link>
                ))}
            </div>

            <div className="flex flex-col gap-3">
                {reviews.data.length === 0 && (
                    <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                        Belum ada ulasan.
                    </p>
                )}

                {reviews.data.map((review) => (
                    <div
                        key={review.id}
                        className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div className="min-w-0 flex-1 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">
                                    {review.product?.name ?? 'Produk dihapus'}
                                </span>
                                <Badge
                                    variant={
                                        review.is_visible
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {review.is_visible
                                        ? 'Tampil'
                                        : 'Disembunyikan'}
                                </Badge>
                            </div>
                            <div className="mt-1 flex items-center gap-0.5">
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <Star
                                        key={n}
                                        className={cn(
                                            'size-3.5',
                                            n <= review.rating
                                                ? 'fill-amber-400 text-amber-400'
                                                : 'text-muted-foreground/40',
                                        )}
                                    />
                                ))}
                            </div>
                            {review.body && (
                                <p className="mt-2 whitespace-pre-line text-foreground">
                                    {review.body}
                                </p>
                            )}
                            <p className="mt-2 text-xs text-muted-foreground">
                                {review.customer?.name} ·{' '}
                                {review.customer?.email} ·{' '}
                                {review.created_at
                                    ? new Date(
                                          review.created_at,
                                      ).toLocaleDateString('id-ID')
                                    : ''}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant={review.is_visible ? 'outline' : 'default'}
                            onClick={() =>
                                setVisible(review, !review.is_visible)
                            }
                        >
                            {review.is_visible ? 'Sembunyikan' : 'Tampilkan'}
                        </Button>
                    </div>
                ))}
            </div>

            {reviews.links.length > 3 && (
                <nav className="flex flex-wrap gap-1">
                    {reviews.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                preserveScroll
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm',
                                    link.active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent',
                                )}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span
                                key={i}
                                className="px-3 py-1.5 text-sm text-muted-foreground/50"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ),
                    )}
                </nav>
            )}
        </div>
    );
}

ReviewIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
