<div class="h-[calc(100vh-4rem)] flex flex-col bg-gray-100"
     x-data="builderShell()"
     x-on:schema-committed.window="pushHistory($event.detail.schema)"
     x-on:keydown.window.prevent.ctrl.z="undo()"
     x-on:keydown.window.prevent.ctrl.shift.z="redo()">

    {{-- ── Top bar ─────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 px-4 py-2 bg-white border-b shadow-sm z-10">
        <a href="{{ route('dashboard') }}" class="text-gray-400 hover:text-gray-600" title="Back to forms">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>

        <input type="text"
               wire:model.blur="schema.title"
               class="font-semibold text-gray-800 border-0 focus:ring-2 focus:ring-indigo-300 rounded px-2 py-1 w-72 bg-transparent hover:bg-gray-50"
               aria-label="Form title" />

        <span class="text-xs px-2 py-0.5 rounded-full
            {{ $form->status->value === 'published' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
            {{ ucfirst($form->status->value) }}
        </span>

        <div class="text-xs text-gray-400 min-w-24" aria-live="polite">
            @if ($saveState === 'saved') Saved {{ $savedAt }}
            @elseif ($saveState === 'error') <span class="text-red-500 font-medium">Schema has errors</span>
            @else Saving… @endif
        </div>

        <div class="flex-1"></div>

        <button type="button" x-on:click="undo()" x-bind:disabled="!canUndo" title="Undo (Ctrl+Z)"
                class="p-1.5 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 010 10H9M3 10l4-4M3 10l4 4"/></svg>
        </button>
        <button type="button" x-on:click="redo()" x-bind:disabled="!canRedo" title="Redo (Ctrl+Shift+Z)"
                class="p-1.5 rounded text-gray-500 hover:bg-gray-100 disabled:opacity-30">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10H11a5 5 0 000 10h4m6-10l-4-4m4 4l-4 4"/></svg>
        </button>

        <div class="w-px h-6 bg-gray-200"></div>

        <button type="button" wire:click="$toggle('showAiPanel')"
                class="px-3 py-1.5 text-sm rounded {{ $showAiPanel ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100' }}">
            ✨ AI
        </button>

        <button type="button" wire:click="$toggle('showJson')"
                class="px-3 py-1.5 text-sm rounded {{ $showJson ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100' }}">
            {} JSON
        </button>

        <a href="{{ route('forms.preview', $form) }}" target="_blank"
           class="px-3 py-1.5 text-sm text-gray-600 rounded hover:bg-gray-100">Preview</a>

        <a href="{{ route('forms.versions', $form) }}"
           class="px-3 py-1.5 text-sm text-gray-600 rounded hover:bg-gray-100">History</a>

        @if ($form->isPublished())
            <div x-data="{ open: false, copied: false }" class="relative">
                <button type="button" x-on:click="open = !open"
                        class="px-3 py-1.5 text-sm text-gray-600 rounded hover:bg-gray-100">
                    Share
                </button>
                <div x-show="open" x-cloak x-on:click.outside="open = false"
                     x-transition.opacity.duration.100ms
                     class="absolute right-0 top-10 z-30 w-64 bg-white border border-gray-200 rounded-lg shadow-lg p-4 space-y-3">
                    <p class="text-xs text-gray-400 break-all">{{ $form->publicUrl() }}</p>
                    <button type="button"
                            x-on:click="navigator.clipboard.writeText('{{ $form->publicUrl() }}'); copied = true; setTimeout(() => copied = false, 1500)"
                            class="w-full px-3 py-1.5 text-sm text-indigo-700 bg-indigo-50 rounded-md hover:bg-indigo-100">
                        <span x-show="!copied">Copy link</span>
                        <span x-show="copied" x-cloak class="text-green-600">Copied ✓</span>
                    </button>
                    <div class="flex justify-center pt-1"
                         x-init="$watch('open', v => v && renderShareQr($refs.qr, '{{ $form->publicUrl() }}'))">
                        <div x-ref="qr" aria-label="QR code for the public form link"></div>
                    </div>
                    <a href="{{ $form->publicUrl() }}" target="_blank"
                       class="block text-center text-xs text-indigo-600 hover:underline">Open public form ↗</a>
                </div>
            </div>
        @endif

        <button type="button" wire:click="publish"
                class="px-4 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-500 disabled:opacity-50"
                @disabled($schemaErrors !== [])>
            {{ $form->isPublished() ? 'Republish' : 'Publish' }}
        </button>
    </div>

    {{-- ── AI panel ────────────────────────────────────────────── --}}
    @if ($showAiPanel)
        <div class="bg-indigo-50/70 border-b border-indigo-100 px-4 py-3">
            <div class="max-w-3xl mx-auto">
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="aiPrompt"
                           wire:keydown.enter="queueAiChange('edit')"
                           placeholder="e.g. add an emergency contact section · make phone required · translate labels to Hindi"
                           class="flex-1 rounded-lg border-indigo-200 text-sm focus:border-indigo-400 focus:ring-indigo-300"
                           @if ($aiTaskUuid) disabled @endif />
                    <button type="button" wire:click="queueAiChange('edit')"
                            class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-500 disabled:opacity-50"
                            @if ($aiTaskUuid) disabled @endif>
                        Apply with AI
                    </button>
                    <button type="button" wire:click="queueAiChange('translate')"
                            title="Treat the instruction as a target language, e.g. 'Hindi'"
                            class="px-4 py-2 text-sm font-medium text-indigo-700 bg-white border border-indigo-200 rounded-lg hover:bg-indigo-50 disabled:opacity-50"
                            @if ($aiTaskUuid) disabled @endif>
                        Translate
                    </button>
                </div>
                @error('aiPrompt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @if ($aiTaskUuid)
                    <div class="mt-2 flex items-center gap-2 text-xs text-indigo-600" wire:poll.1500ms="checkAiTask">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                        The AI is updating your form — you can keep editing meanwhile.
                    </div>
                @endif
                @if ($aiError)
                    <p class="mt-2 text-xs text-red-600">{{ $aiError }}</p>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Schema errors banner ────────────────────────────────── --}}
    @if ($schemaErrors !== [])
        <div class="bg-red-50 border-b border-red-200 px-4 py-2 text-sm text-red-700">
            <strong>The schema is invalid — changes are not being saved:</strong>
            <ul class="list-disc ml-5 mt-1">
                @foreach (array_slice($schemaErrors, 0, 5) as $error)
                    <li><code class="text-xs">{{ $error['path'] }}</code> — {{ $error['message'] }}</li>
                @endforeach
                @if (count($schemaErrors) > 5)
                    <li>… and {{ count($schemaErrors) - 5 }} more.</li>
                @endif
            </ul>
        </div>
    @endif

    <div class="flex-1 flex overflow-hidden">
        {{-- ── Palette ─────────────────────────────────────────── --}}
        <aside class="w-56 bg-white border-r overflow-y-auto p-3 shrink-0">
            <h3 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Fields</h3>
            <div id="palette" class="grid grid-cols-2 gap-1.5">
                @foreach ($palette as $type)
                    <button type="button"
                            data-field-type="{{ $type->value }}"
                            wire:click="addField('{{ $type->value }}', '{{ $schema['sections'][count($schema['sections'])-1]['id'] }}')"
                            class="palette-item flex flex-col items-center gap-1 p-2 text-[11px] text-gray-600 border border-gray-200 rounded-md cursor-grab hover:border-indigo-400 hover:text-indigo-600 hover:bg-indigo-50/50"
                            title="Click to add, or drag onto the canvas">
                        <x-field-icon :type="$type" class="w-4 h-4" />
                        {{ $type->label() }}
                    </button>
                @endforeach
            </div>

            <button type="button" wire:click="addSection"
                    class="mt-4 w-full py-2 text-sm text-gray-600 border border-dashed border-gray-300 rounded-md hover:border-indigo-400 hover:text-indigo-600">
                + Add section
            </button>
        </aside>

        {{-- ── Canvas ──────────────────────────────────────────── --}}
        <main class="flex-1 overflow-y-auto p-6" wire:click.self="select(null)">
            <div class="max-w-2xl mx-auto space-y-4 pb-24">
                @foreach ($schema['sections'] as $si => $section)
                    <section wire:key="section-{{ $section['id'] }}"
                             class="bg-white rounded-lg shadow-sm border border-gray-200">
                        <div class="flex items-center gap-2 px-4 pt-3">
                            <span class="section-drag-handle cursor-grab text-gray-300 hover:text-gray-500" title="Drag section">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 5a1 1 0 110-2 1 1 0 010 2zm6 0a1 1 0 110-2 1 1 0 010 2zM7 11a1 1 0 110-2 1 1 0 010 2zm6 0a1 1 0 110-2 1 1 0 010 2zm-6 6a1 1 0 110-2 1 1 0 010 2zm6 0a1 1 0 110-2 1 1 0 010 2z"/></svg>
                            </span>
                            <input type="text"
                                   wire:model.blur="schema.sections.{{ $si }}.title"
                                   class="flex-1 font-medium text-gray-700 border-0 focus:ring-2 focus:ring-indigo-200 rounded px-2 py-1 bg-transparent hover:bg-gray-50"
                                   aria-label="Section title" />
                            @if (count($schema['sections']) > 1)
                                <button type="button" wire:click="removeSection('{{ $section['id'] }}')"
                                        wire:confirm="Delete this section and its fields?"
                                        class="text-gray-300 hover:text-red-500" title="Delete section">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            @endif
                        </div>

                        <div class="p-4 space-y-2 min-h-[3rem] field-container" data-section-id="{{ $section['id'] }}">
                            @forelse ($section['fields'] as $fi => $field)
                                @include('livewire.builder.partials.canvas-field')
                            @empty
                                <p class="text-sm text-gray-300 text-center py-3 pointer-events-none">
                                    Drop fields here or click one in the palette
                                </p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </main>

        {{-- ── Settings panel ──────────────────────────────────── --}}
        <aside class="w-80 bg-white border-l overflow-y-auto shrink-0">
            @if ($selected !== null)
                @include('livewire.builder.partials.settings-panel', [
                    'si' => $selected[0],
                    'fi' => $selected[1],
                    'field' => $schema['sections'][$selected[0]]['fields'][$selected[1]],
                ])
            @else
                @include('livewire.builder.partials.form-settings')
            @endif
        </aside>
    </div>

    {{-- ── JSON drawer ─────────────────────────────────────────── --}}
    @if ($showJson)
        <div class="h-72 bg-gray-900 border-t border-gray-700 flex flex-col">
            <div class="flex items-center gap-3 px-4 py-1.5 text-xs text-gray-400">
                <span class="font-semibold">Schema JSON — single source of truth</span>
                @if ($jsonError)
                    <span class="text-red-400">{{ $jsonError }}</span>
                @endif
                <div class="flex-1"></div>
                <button type="button" wire:click="applyJson"
                        class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-500">Apply JSON</button>
            </div>
            <textarea wire:model="jsonText" spellcheck="false"
                      class="flex-1 bg-gray-900 text-green-300 font-mono text-xs border-0 focus:ring-0 resize-none px-4"
                      aria-label="Schema JSON editor"></textarea>
        </div>
    @endif
</div>
