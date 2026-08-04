<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $form->title }} — Analytics</h2>
                <p class="text-sm text-gray-400">Last 14 days</p>
            </div>
            <a href="{{ route('forms.builder', $form) }}" class="px-3 py-2 text-sm text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50">← Builder</a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        {{-- Stat tiles --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            @foreach ([
                ['label' => 'Views', 'value' => number_format($totals['views'])],
                ['label' => 'Starts', 'value' => number_format($totals['starts'])],
                ['label' => 'Submissions', 'value' => number_format($totals['submissions'])],
                ['label' => 'Completion', 'value' => $totals['completion_rate'] !== null ? $totals['completion_rate'].'%' : '—'],
                ['label' => 'Avg. time', 'value' => $totals['avg_duration'] !== null ? gmdate('i:s', $totals['avg_duration']) : '—'],
            ] as $stat)
                <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-4">
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">{{ $stat['label'] }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-800">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Daily bars --}}
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-5">
            <div class="flex items-center gap-4 text-xs text-gray-500 mb-4">
                <span class="font-semibold text-gray-600 text-sm">Daily activity</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-indigo-200"></span> Views</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-indigo-400"></span> Starts</span>
                <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-indigo-600"></span> Submissions</span>
            </div>
            @php $peak = max(1, $series->max('views')); @endphp
            <div class="flex items-end gap-1.5 h-40">
                @foreach ($series as $day)
                    <div class="flex-1 flex items-end justify-center gap-px group relative">
                        <div class="w-1/3 bg-indigo-200 rounded-t" style="height: {{ round($day['views'] / $peak * 100) }}%"></div>
                        <div class="w-1/3 bg-indigo-400 rounded-t" style="height: {{ round($day['starts'] / $peak * 100) }}%"></div>
                        <div class="w-1/3 bg-indigo-600 rounded-t" style="height: {{ round($day['submissions'] / $peak * 100) }}%"></div>
                        <div class="hidden group-hover:block absolute -top-14 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-[10px] rounded px-2 py-1 whitespace-nowrap z-10">
                            {{ \Illuminate\Support\Carbon::parse($day['date'])->format('M j') }}:
                            {{ $day['views'] }}v · {{ $day['starts'] }}s · {{ $day['submissions'] }}✓
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-[10px] text-gray-400 mt-1">
                <span>{{ \Illuminate\Support\Carbon::parse($series->first()['date'])->format('M j') }}</span>
                <span>{{ \Illuminate\Support\Carbon::parse($series->last()['date'])->format('M j') }}</span>
            </div>
        </div>

        {{-- Drop-off --}}
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-5">
            <p class="font-semibold text-gray-600 text-sm mb-1">Drop-off points</p>
            <p class="text-xs text-gray-400 mb-4">The last field visitors touched before abandoning the form.</p>
            @forelse ($dropOff as $row)
                <div class="flex items-center gap-3 py-1.5">
                    <span class="w-48 truncate text-sm text-gray-600">{{ $row['label'] }}</span>
                    <div class="flex-1 bg-gray-100 rounded-full h-2.5 overflow-hidden">
                        <div class="bg-rose-400 h-full rounded-full" style="width: {{ $row['share'] }}%"></div>
                    </div>
                    <span class="w-20 text-right text-xs text-gray-500">{{ $row['count'] }} ({{ $row['share'] }}%)</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">No abandonment data yet.</p>
            @endforelse
        </div>
    </div>
</div>
