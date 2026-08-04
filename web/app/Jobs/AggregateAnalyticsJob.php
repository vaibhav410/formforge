<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Form;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rolls the raw form_events stream up into form_analytics_daily and
 * prunes aggregated events past the retention window. Scheduled
 * nightly; idempotent (upserts by form+date), so re-running a day is
 * always safe.
 */
class AggregateAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    private const RETENTION_DAYS = 90;

    public function __construct(private readonly ?Carbon $day = null)
    {
    }

    public function handle(): void
    {
        $day = ($this->day ?? now()->subDay())->toDateString();

        Form::query()
            ->whereHas('events', fn ($q) => $q->whereDate('created_at', $day))
            ->each(function (Form $form) use ($day) {
                $counts = $form->events()
                    ->selectRaw('event, COUNT(*) as total, COUNT(DISTINCT visitor_id) as visitors')
                    ->whereDate('created_at', $day)
                    ->groupBy('event')
                    ->get()
                    ->keyBy('event');

                $dropOff = $form->events()
                    ->select('field_key', DB::raw('COUNT(*) as total'))
                    ->where('event', 'abandon')
                    ->whereDate('created_at', $day)
                    ->whereNotNull('field_key')
                    ->groupBy('field_key')
                    ->pluck('total', 'field_key');

                $avgDuration = $form->submissions()
                    ->whereDate('submitted_at', $day)
                    ->avg('duration_seconds');

                $form->dailyAnalytics()->updateOrCreate(
                    ['date' => $day],
                    [
                        'views' => (int) ($counts->get('view')?->total ?? 0),
                        'starts' => (int) ($counts->get('start')?->total ?? 0),
                        'submissions' => (int) ($counts->get('submit')?->total ?? 0),
                        'unique_visitors' => (int) ($counts->get('view')?->visitors ?? 0),
                        'avg_duration_seconds' => $avgDuration !== null ? (int) round((float) $avgDuration) : null,
                        'drop_off' => $dropOff->isEmpty() ? null : $dropOff->all(),
                    ],
                );
            });

        // Aggregated events past retention are safe to drop.
        DB::table('form_events')
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }
}
