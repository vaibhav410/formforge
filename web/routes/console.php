<?php

use App\Jobs\AggregateAnalyticsJob;
use Illuminate\Support\Facades\Schedule;

// Nightly rollup of raw form_events into form_analytics_daily,
// plus pruning of aggregated events past the retention window.
Schedule::job(new AggregateAnalyticsJob())->dailyAt('01:10');
