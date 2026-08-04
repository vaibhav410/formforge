<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">✨ Generate a form with AI</h2>
    </x-slot>

    <div class="py-10 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Describe the form you need</span>
                <textarea wire:model="prompt" rows="4"
                          placeholder="e.g. {{ $examples[0] }}"
                          class="mt-2 w-full rounded-lg border-gray-300 focus:border-indigo-400 focus:ring-indigo-300"
                          @if ($taskUuid) disabled @endif></textarea>
            </label>
            @error('prompt') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($examples as $i => $example)
                    <button type="button" wire:click="useExample({{ $i }})"
                            class="text-xs px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-full text-gray-500 hover:border-indigo-300 hover:text-indigo-600">
                        {{ Str::limit($example, 48) }}
                    </button>
                @endforeach
            </div>

            @if ($error)
                <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                    {{ $error }}
                </div>
            @endif

            <div class="mt-5 flex items-center gap-4">
                <button type="button" wire:click="generate"
                        class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-500 disabled:opacity-50"
                        @if ($taskUuid) disabled @endif>
                    Generate form
                </button>

                @if ($task !== null && ! $task->status->isTerminal())
                    <div wire:key="ai-poller-{{ $task->uuid }}" class="flex items-center gap-2 text-sm text-gray-500" wire:poll.1500ms="checkTask">
                        <svg class="w-4 h-4 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        {{ $task->status->value === 'queued' ? 'Queued…' : 'The AI is designing your form…' }}
                    </div>
                @endif
            </div>
        </div>

        @if ($recentTasks->isNotEmpty())
            <div class="mt-8">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Recent generations</h3>
                <div class="space-y-2">
                    @foreach ($recentTasks as $recent)
                        <div class="flex items-center gap-3 bg-white border border-gray-200 rounded-lg px-4 py-3 text-sm">
                            <span class="w-2 h-2 rounded-full shrink-0
                                {{ $recent->status->value === 'completed' ? 'bg-green-500' : ($recent->status->value === 'failed' ? 'bg-red-500' : 'bg-amber-400 animate-pulse') }}"></span>
                            <span class="flex-1 text-gray-600 truncate">{{ $recent->prompt }}</span>
                            @if ($recent->model)
                                <span class="text-xs text-gray-400" title="{{ $recent->latency_ms }}ms, {{ $recent->prompt_tokens }}+{{ $recent->completion_tokens }} tokens">
                                    {{ $recent->model }}
                                </span>
                            @endif
                            @if ($recent->status->value === 'completed' && $recent->form_id)
                                <a href="{{ route('forms.builder', $recent->form) }}"
                                   class="text-indigo-600 hover:underline shrink-0">Open →</a>
                            @elseif ($recent->status->value === 'failed')
                                <span class="text-xs text-red-400 shrink-0" title="{{ $recent->error }}">failed</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
