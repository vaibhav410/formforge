<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Form;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side analytics. Queries the raw event stream directly — at demo
 * scale that's instant; at real scale the same shapes come from the
 * form_analytics_daily rollups (see AggregateAnalyticsJob) and this
 * class is where the swap happens.
 */
class AnalyticsService
{
    /** @return array{views: int, starts: int, submissions: int, completion_rate: ?float, avg_duration: ?int} */
    public function totals(Form $form): array
    {
        $starts = $form->events()->where('event', 'start')->count();
        $submissions = $form->submissions_count;

        return [
            'views' => $form->views_count,
            'starts' => $starts,
            'submissions' => $submissions,
            'completion_rate' => $starts > 0 ? round($submissions / $starts * 100, 1) : null,
            'avg_duration' => ($avg = $form->submissions()->avg('duration_seconds')) !== null
                ? (int) round((float) $avg)
                : null,
        ];
    }

    /**
     * Daily view/start/submit counts for the last N days.
     *
     * @return Collection<int, array{date: string, views: int, starts: int, submissions: int}>
     */
    public function dailySeries(Form $form, int $days = 14): Collection
    {
        $rows = $form->events()
            ->selectRaw('DATE(created_at) as day, event, COUNT(*) as total')
            ->whereIn('event', ['view', 'start', 'submit'])
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->groupBy('day', 'event')
            ->get()
            ->groupBy('day');

        return collect(range($days - 1, 0))->map(function (int $daysAgo) use ($rows) {
            $date = now()->subDays($daysAgo)->toDateString();
            $day = $rows->get($date, collect());

            return [
                'date' => $date,
                'views' => (int) $day->firstWhere('event', 'view')?->total,
                'starts' => (int) $day->firstWhere('event', 'start')?->total,
                'submissions' => (int) $day->firstWhere('event', 'submit')?->total,
            ];
        });
    }

    /**
     * Where respondents give up: abandon events per last-touched field.
     *
     * @return Collection<int, array{field_key: string, label: string, count: int, share: float}>
     */
    public function dropOff(Form $form): Collection
    {
        $abandons = $form->events()
            ->select('field_key', DB::raw('COUNT(*) as total'))
            ->where('event', 'abandon')
            ->whereNotNull('field_key')
            ->groupBy('field_key')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $sum = max(1, $abandons->sum('total'));
        $labels = [];
        $version = $form->publishedVersion ?? $form->latestVersion();
        if ($version !== null) {
            foreach (\App\Schema\FormSchema::fromArray($version->schema_json)->fields() as $field) {
                $labels[$field['key']] = $field['label'];
            }
        }

        return $abandons->map(fn ($row) => [
            'field_key' => $row->field_key,
            'label' => $labels[$row->field_key] ?? $row->field_key,
            'count' => (int) $row->total,
            'share' => round($row->total / $sum * 100, 1),
        ]);
    }
}
