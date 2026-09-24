import { router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, Star } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    destroy as destroyReview,
    store as storeReview,
} from '@/routes/account/reviews';
import type { ProductReview } from '@/types/product';

type Props = {
    productId: number;
    ratingAvg: number | null;
    ratingCount: number;
    reviews: ProductReview[];
    myReview: {
        rating: number;
        body: string | null;
        is_visible: boolean;
    } | null;
    canReview: boolean;
};

export function ProductReviews({
    productId,
    ratingAvg,
    ratingCount,
    reviews,
    myReview,
    canReview,
}: Props) {
    const [editing, setEditing] = useState(false);
    const { errors: pageErrors } = usePage<{ errors: Record<string, string> }>()
        .props;

    return (
        <section className="flex flex-col gap-4 border-t border-border pt-8 lg:col-span-2">
            <div className="flex flex-wrap items-baseline gap-3">
                <h2 className="text-lg font-semibold text-foreground">
                    Ulasan
                </h2>
                {ratingCount > 0 && ratingAvg != null ? (
                    <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Stars value={Math.round(ratingAvg)} />
                        <span className="font-medium text-foreground">
                            {ratingAvg.toFixed(1)}
                        </span>
                        dari {ratingCount} ulasan
                    </span>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        Belum ada ulasan.
                    </span>
                )}
            </div>

            {pageErrors.review && (
                <p className="text-sm text-destructive">{pageErrors.review}</p>
            )}

            {canReview && (!myReview || editing) && (
                <ReviewForm
                    productId={productId}
                    initial={myReview}
                    onDone={() => setEditing(false)}
                />
            )}

            {myReview && !editing && (
                <div className="rounded-lg border border-primary/30 bg-primary/5 p-4 text-sm">
                    <div className="flex items-center justify-between gap-2">
                        <span className="font-medium text-foreground">
                            Ulasan Anda
                        </span>
                        <div className="flex gap-2">
                            {canReview && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEditing(true)}
                                >
                                    Ubah
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(
                                        destroyReview(productId).url,
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                Hapus
                            </Button>
                        </div>
                    </div>
                    <Stars value={myReview.rating} className="mt-2" />
                    {myReview.body && (
                        <p className="mt-2 whitespace-pre-line text-foreground">
                            {myReview.body}
                        </p>
                    )}
                    {!myReview.is_visible && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Ulasan ini sedang tidak ditampilkan untuk umum.
                        </p>
                    )}
                </div>
            )}

            <ul className="flex flex-col divide-y divide-border">
                {reviews.map((review) => (
                    <li key={review.id} className="py-4 text-sm">
                        <div className="flex items-center gap-2">
                            <Stars value={review.rating} />
                            <span className="font-medium text-foreground">
                                {review.author}
                            </span>
                            {review.created_at && (
                                <span className="text-xs text-muted-foreground">
                                    ·{' '}
                                    {new Date(
                                        review.created_at,
                                    ).toLocaleDateString('id-ID', {
                                        month: 'long',
                                        year: 'numeric',
                                    })}
                                </span>
                            )}
                        </div>
                        {review.body && (
                            <p className="mt-1.5 whitespace-pre-line text-muted-foreground">
                                {review.body}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function ReviewForm({
    productId,
    initial,
    onDone,
}: {
    productId: number;
    initial: { rating: number; body: string | null } | null;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        rating: initial?.rating ?? 0,
        body: initial?.body ?? '',
    });

    return (
        <form
            className="flex flex-col gap-3 rounded-lg border border-border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                post(storeReview(productId).url, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <p className="text-sm font-medium text-foreground">
                {initial
                    ? 'Ubah ulasan Anda'
                    : 'Bagaimana produk ini menurut Anda?'}
            </p>
            <div className="flex gap-1" role="radiogroup" aria-label="Nilai">
                {[1, 2, 3, 4, 5].map((n) => (
                    <button
                        key={n}
                        type="button"
                        role="radio"
                        aria-checked={data.rating === n}
                        aria-label={`${n} bintang`}
                        onClick={() => setData('rating', n)}
                    >
                        <Star
                            className={cn(
                                'size-6',
                                n <= data.rating
                                    ? 'fill-amber-400 text-amber-400'
                                    : 'text-muted-foreground/40',
                            )}
                        />
                    </button>
                ))}
            </div>
            <InputError message={errors.rating} />
            <Textarea
                rows={3}
                value={data.body}
                onChange={(e) => setData('body', e.target.value)}
                placeholder="Ceritakan pengalaman Anda (opsional)"
            />
            <InputError message={errors.body} />
            <div className="flex gap-2">
                <Button
                    type="submit"
                    disabled={processing || data.rating === 0}
                >
                    {processing && (
                        <LoaderCircle className="size-4 animate-spin" />
                    )}
                    Kirim Ulasan
                </Button>
                {initial && (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Batal
                    </Button>
                )}
            </div>
        </form>
    );
}

function Stars({ value, className }: { value: number; className?: string }) {
    return (
        <span className={cn('flex items-center gap-0.5', className)}>
            {[1, 2, 3, 4, 5].map((n) => (
                <Star
                    key={n}
                    className={cn(
                        'size-3.5',
                        n <= value
                            ? 'fill-amber-400 text-amber-400'
                            : 'text-muted-foreground/40',
                    )}
                />
            ))}
        </span>
    );
}
