import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { FormResponse } from '@/lib/constant';
import { notes } from '@/routes/backoffice/order';
import type { OrderActivity } from '@/types/order';

type Props = {
    orderId: number;
    activities: OrderActivity[];
    canUpdate: boolean;
};

export default function ActivityPanel({
    orderId,
    activities,
    canUpdate,
}: Props) {
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);

    const onAddNote = () => {
        if (!body.trim()) return;

        setSaving(true);
        router.post(
            notes(orderId).url,
            { body },
            {
                ...FormResponse,
                preserveScroll: true,
                onSuccess: () => setBody(''),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <div className="rounded-xl border border-border bg-card p-5 text-sm">
            <h2 className="font-semibold text-foreground">
                Riwayat &amp; Catatan Internal
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
                Catatan hanya terlihat oleh staf.
            </p>

            {canUpdate && (
                <div className="mt-3 flex flex-col gap-2">
                    <Textarea
                        rows={2}
                        value={body}
                        maxLength={2000}
                        onChange={(e) => setBody(e.target.value)}
                        placeholder="Tulis catatan, misal: pelanggan minta dikirim sore."
                    />
                    <Button
                        type="button"
                        size="sm"
                        className="self-start"
                        disabled={saving || !body.trim()}
                        onClick={onAddNote}
                    >
                        {saving ? 'Menyimpan...' : 'Tambah Catatan'}
                    </Button>
                </div>
            )}

            {activities.length === 0 ? (
                <p className="mt-4 text-muted-foreground">Belum ada riwayat.</p>
            ) : (
                <ol className="mt-4 flex flex-col gap-3">
                    {activities.map((activity) => (
                        <li
                            key={activity.id}
                            className={`rounded-lg border p-3 ${
                                activity.action === 'note'
                                    ? 'border-primary/30 bg-primary/5'
                                    : 'border-border'
                            }`}
                        >
                            <p className="break-words whitespace-pre-line text-foreground">
                                {activity.description}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {activity.user_name ?? 'Sistem'}
                                {activity.created_at
                                    ? ` · ${new Date(activity.created_at).toLocaleString('id-ID')}`
                                    : ''}
                            </p>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
