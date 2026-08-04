<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $form->title }}</h2>
                <p class="text-sm text-gray-400">{{ number_format($form->submissions_count) }} submissions</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('forms.builder', $form) }}" class="px-3 py-2 text-sm text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50">← Builder</a>
                <button type="button" wire:click="requestExport"
                        class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-500">
                    Export CSV
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

        {{-- Export status strip (polls while a job is running) --}}
        @if ($exports->isNotEmpty())
            <div class="flex flex-wrap gap-2" @if ($exports->contains(fn ($e) => ! $e->status->isTerminal())) wire:poll.2s @endif>
                @foreach ($exports as $export)
                    <div class="flex items-center gap-2 text-xs px-3 py-1.5 rounded-full border
                        {{ $export->status->value === 'completed' ? 'bg-green-50 border-green-200 text-green-700' : ($export->status->value === 'failed' ? 'bg-red-50 border-red-200 text-red-600' : 'bg-amber-50 border-amber-200 text-amber-700') }}">
                        @if ($export->status->value === 'completed')
                            CSV ready ({{ number_format($export->row_count) }} rows) —
                            <a href="{{ route('exports.download', $export) }}" class="font-semibold underline">Download</a>
                        @elseif ($export->status->value === 'failed')
                            Export failed
                        @else
                            <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            Exporting…
                        @endif
                        <span class="text-gray-400">{{ $export->created_at->diffForHumans(short: true) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex items-center gap-3">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search answers…"
                   class="w-72 rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300" />
        </div>

        <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Submitted</th>
                        @foreach ($columns as $column)
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ $column['label'] }}</th>
                        @endforeach
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($submissions as $submission)
                        <tr wire:key="sub-{{ $submission->id }}"
                            class="hover:bg-gray-50 cursor-pointer {{ $expandedId === $submission->id ? 'bg-indigo-50/40' : '' }}"
                            wire:click="toggleExpand({{ $submission->id }})">
                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                {{ $submission->submitted_at->format('M j, H:i') }}
                            </td>
                            @foreach ($columns as $column)
                                <td class="px-4 py-3 max-w-[16rem] truncate text-gray-700">
                                    {{ $this->displayValue($column, $submission->answers->firstWhere('field_key', $column['key'])?->value()) }}
                                </td>
                            @endforeach
                            <td class="px-4 py-3 text-right text-gray-300">
                                {{ $expandedId === $submission->id ? '▴' : '▾' }}
                            </td>
                        </tr>
                        @if ($expandedId === $submission->id)
                            <tr wire:key="sub-detail-{{ $submission->id }}" class="bg-gray-50/70">
                                <td colspan="{{ count($columns) + 2 }}" class="px-6 py-4">
                                    @php
                                        $versionSchema = \App\Schema\FormSchema::fromArray($submission->formVersion->schema_json);
                                    @endphp
                                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3">
                                        @foreach ($versionSchema->answerableFields() as $field)
                                            <div>
                                                <dt class="text-xs font-medium text-gray-400">{{ $field['label'] }}</dt>
                                                <dd class="text-sm text-gray-700 break-words">
                                                    @php $answer = $submission->answers->firstWhere('field_key', $field['key']); @endphp
                                                    @if ($field['type'] === 'signature' && $answer?->value_text)
                                                        <img src="{{ $answer->value_text }}" alt="Signature" class="h-16 border rounded bg-white mt-1">
                                                    @else
                                                        {{ $this->displayValue($field, $answer?->value()) }}
                                                    @endif
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                    <div class="mt-4 flex items-center gap-4 text-xs text-gray-400 border-t border-gray-200 pt-3">
                                        <span>Answered against schema v{{ $submission->formVersion->version }}</span>
                                        <span>Duration: {{ $submission->duration_seconds !== null ? $submission->duration_seconds.'s' : '—' }}</span>
                                        <button type="button"
                                                wire:click.stop="deleteSubmission({{ $submission->id }})"
                                                wire:confirm="Delete this submission permanently?"
                                                class="ml-auto text-red-400 hover:text-red-600">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 2 }}" class="px-4 py-16 text-center text-gray-400">
                                @if ($search !== '') No submissions match “{{ $search }}”. @else No submissions yet. @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $submissions->links() }}</div>
    </div>
</div>
