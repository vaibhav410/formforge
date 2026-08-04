<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Import from Word / Excel</h2>
    </x-slot>

    <div class="py-10 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Step 1: upload --}}
        @if ($importUuid === null)
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8">
                <label for="import-upload"
                       class="block border-2 border-dashed border-gray-300 rounded-xl p-12 text-center cursor-pointer hover:border-indigo-400 transition"
                       x-data="{ dragging: false }"
                       x-on:dragover.prevent="dragging = true"
                       x-on:dragleave="dragging = false"
                       x-on:drop="dragging = false"
                       x-bind:class="dragging && 'border-indigo-400 bg-indigo-50/40'">
                    <svg class="mx-auto w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <p class="mt-3 text-gray-600 font-medium">Drop a .docx or .xlsx here, or click to browse</p>
                    <p class="mt-1 text-sm text-gray-400">
                        Word: headings become sections, questions become fields, bullet lists become options.<br>
                        Excel: a structured question sheet or a plain header-row sheet. Max 10 MB.
                    </p>
                    <input id="import-upload" type="file" wire:model="upload" accept=".docx,.xlsx" class="sr-only" />
                </label>
                @error('upload') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror
                @if ($error)
                    <p class="mt-3 text-sm text-red-600">{{ $error }}</p>
                @endif
                <div wire:loading wire:target="upload" class="mt-3 text-sm text-indigo-600">Uploading…</div>

                <div class="mt-6 text-xs text-gray-400">
                    Sample files to try live in <code class="bg-gray-100 px-1 rounded">samples/</code> in the repository.
                </div>
            </div>

        {{-- Step 2: parsing --}}
        @elseif ($import !== null && ! in_array($import->status->value, ['preview_ready', 'committed'], true))
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-12 text-center" wire:poll.1500ms="checkImport">
                <svg class="mx-auto w-8 h-8 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                <p class="mt-4 text-gray-600 font-medium">Parsing {{ $import->original_filename }}…</p>
                <p class="mt-1 text-sm text-gray-400">Deterministic structure first; AI only for ambiguous fields.</p>
                @if ($error) <p class="mt-3 text-sm text-red-600">{{ $error }}</p> @endif
            </div>

        {{-- Step 3: preview & mapping --}}
        @elseif ($import !== null && $mapping !== [])
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <input type="text" wire:model="formTitle"
                           class="text-lg font-semibold text-gray-800 rounded-md border-gray-300 focus:border-indigo-400 focus:ring-indigo-300 w-96" />
                    <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-500">{{ $import->original_filename }}</span>
                    @if ($import->ai_used)
                        <span class="text-xs px-2 py-1 rounded-full bg-purple-100 text-purple-600" title="AI refined ambiguous field types">✨ AI-assisted</span>
                    @endif
                    <div class="flex-1"></div>
                    <button type="button" wire:click="startOver" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Start over</button>
                    <button type="button" wire:click="commit"
                            class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-500">
                        Create form ({{ collect($mapping)->where('include', true)->count() }} fields)
                    </button>
                </div>

                @if ($error)
                    <div class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">{{ $error }}</div>
                @endif

                {{-- Unparseable blocks --}}
                @if (($import->issues ?? []) !== [])
                    <details class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-800">
                        <summary class="cursor-pointer font-medium">
                            {{ count($import->issues) }} block(s) could not be fully parsed — review before committing
                        </summary>
                        <ul class="mt-2 space-y-1 text-xs">
                            @foreach ($import->issues as $issue)
                                <li><code class="bg-amber-100 px-1 rounded">{{ $issue['block'] }}</code> — {{ $issue['reason'] }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                <th class="px-3 py-3">Use</th>
                                <th class="px-3 py-3">Section</th>
                                <th class="px-3 py-3">Label</th>
                                <th class="px-3 py-3">Key</th>
                                <th class="px-3 py-3">Type</th>
                                <th class="px-3 py-3">Req.</th>
                                <th class="px-3 py-3">Options</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($mapping as $i => $row)
                                <tr wire:key="map-{{ $i }}" class="{{ $row['include'] ? '' : 'opacity-40' }} {{ $row['confidence'] === 'low' ? 'bg-amber-50/60' : '' }}">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" wire:model.live="mapping.{{ $i }}.include"
                                               class="rounded border-gray-300 text-indigo-600" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" wire:model.blur="mapping.{{ $i }}.section"
                                               class="w-32 rounded border-gray-200 text-xs" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" wire:model.blur="mapping.{{ $i }}.label"
                                               class="w-48 rounded border-gray-200 text-xs" />
                                        @if ($row['confidence'] === 'low')
                                            <span class="block text-[10px] text-amber-600 mt-0.5">⚠ type was guessed — please check</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" wire:model.blur="mapping.{{ $i }}.key"
                                               class="w-32 rounded border-gray-200 text-xs font-mono" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <select wire:model.live="mapping.{{ $i }}.type" class="rounded border-gray-200 text-xs py-1">
                                            @foreach ($fieldTypes as $type)
                                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="checkbox" wire:model.live="mapping.{{ $i }}.required"
                                               class="rounded border-gray-300 text-indigo-600" />
                                    </td>
                                    <td class="px-3 py-2">
                                        @if (in_array($row['type'], ['dropdown', 'radio', 'checkbox'], true))
                                            <input type="text" wire:model.blur="mapping.{{ $i }}.options_text"
                                                   placeholder="Option A | Option B"
                                                   class="w-48 rounded border-gray-200 text-xs" />
                                        @else
                                            <span class="text-gray-300 text-xs">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
