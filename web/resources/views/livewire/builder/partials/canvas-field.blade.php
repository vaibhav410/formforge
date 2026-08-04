@php
    /** @var array $field */
    $type = \App\Enums\FieldType::from($field['type']);
    $isSelected = $selectedId === $field['id'];
@endphp

<div wire:key="field-{{ $field['id'] }}"
     data-field-id="{{ $field['id'] }}"
     wire:click.stop="select('{{ $field['id'] }}')"
     class="field-card group relative rounded-md border px-3 py-2.5 cursor-pointer transition
        {{ $isSelected ? 'border-indigo-400 ring-2 ring-indigo-100 bg-indigo-50/30' : 'border-gray-200 hover:border-gray-300 bg-white' }}">

    <div class="flex items-start gap-2">
        <span class="drag-handle mt-1 cursor-grab text-gray-300 group-hover:text-gray-400" title="Drag to reorder">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 5a1 1 0 110-2 1 1 0 010 2zm6 0a1 1 0 110-2 1 1 0 010 2zM7 11a1 1 0 110-2 1 1 0 010 2zm6 0a1 1 0 110-2 1 1 0 010 2zm-6 6a1 1 0 110-2 1 1 0 010 2zm6 0a1 1 0 110-2 1 1 0 010 2z"/></svg>
        </span>

        <div class="flex-1 min-w-0">
            @if ($type === \App\Enums\FieldType::Heading)
                <p class="font-semibold text-gray-800">{{ $field['label'] }}</p>
            @else
                <div class="flex items-center gap-1.5 text-sm font-medium text-gray-700">
                    <span class="truncate">{{ $field['label'] }}</span>
                    @if ($field['required']) <span class="text-red-500">*</span> @endif
                    @if ($field['logic'] !== null)
                        <span class="text-[10px] px-1.5 py-0.5 bg-purple-100 text-purple-600 rounded-full" title="Has conditional logic">logic</span>
                    @endif
                    @if ($field['hidden'])
                        <span class="text-[10px] px-1.5 py-0.5 bg-gray-100 text-gray-500 rounded-full">hidden</span>
                    @endif
                </div>

                {{-- Mini preview --}}
                <div class="mt-1.5 pointer-events-none">
                    @switch($type)
                        @case(\App\Enums\FieldType::Textarea)
                            <div class="h-12 rounded border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-400">{{ $field['placeholder'] ?? '' }}</div>
                            @break
                        @case(\App\Enums\FieldType::Dropdown)
                            <div class="h-8 rounded border border-gray-200 bg-gray-50 px-2 flex items-center justify-between text-xs text-gray-400">
                                <span>{{ $field['options'][0]['label'] ?? 'Select…' }}</span><span>▾</span>
                            </div>
                            @break
                        @case(\App\Enums\FieldType::Radio)
                        @case(\App\Enums\FieldType::Checkbox)
                            <div class="space-y-1">
                                @foreach (array_slice($field['options'] ?? [], 0, 3) as $option)
                                    <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                        <span class="w-3 h-3 border border-gray-300 {{ $type === \App\Enums\FieldType::Radio ? 'rounded-full' : 'rounded' }} bg-white"></span>
                                        {{ $option['label'] }}
                                    </div>
                                @endforeach
                                @if (count($field['options'] ?? []) > 3)
                                    <p class="text-[10px] text-gray-400">+{{ count($field['options']) - 3 }} more</p>
                                @endif
                            </div>
                            @break
                        @case(\App\Enums\FieldType::Rating)
                            <div class="text-sm text-amber-400 tracking-widest">{{ str_repeat('★', (int) ($field['meta']['rating_max'] ?? 5)) }}</div>
                            @break
                        @case(\App\Enums\FieldType::File)
                            <div class="h-8 rounded border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center text-xs text-gray-400">
                                Upload {{ implode('/', $field['validation']['mimes'] ?? []) }}
                            </div>
                            @break
                        @case(\App\Enums\FieldType::Address)
                            <div class="grid grid-cols-2 gap-1">
                                @foreach (['Street', 'City', 'Postal code', 'Country'] as $part)
                                    <div class="h-6 rounded border border-gray-200 bg-gray-50 px-1.5 flex items-center text-[10px] text-gray-400">{{ $part }}</div>
                                @endforeach
                            </div>
                            @break
                        @case(\App\Enums\FieldType::Signature)
                            <div class="h-10 rounded border border-gray-200 bg-gray-50 flex items-center justify-center text-xs italic text-gray-400">✍ sign here</div>
                            @break
                        @case(\App\Enums\FieldType::Hidden)
                            <div class="text-[11px] text-gray-400 font-mono">hidden • key={{ $field['key'] }}</div>
                            @break
                        @default
                            <div class="h-8 rounded border border-gray-200 bg-gray-50 px-2 flex items-center text-xs text-gray-400">
                                {{ $field['placeholder'] ?: $type->label() }}
                            </div>
                    @endswitch
                </div>
            @endif
        </div>

        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition">
            <button type="button" wire:click.stop="duplicateField('{{ $field['id'] }}')"
                    class="p-1 text-gray-400 hover:text-indigo-600" title="Duplicate">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </button>
            <button type="button" wire:click.stop="removeField('{{ $field['id'] }}')"
                    class="p-1 text-gray-400 hover:text-red-500" title="Delete">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
        </div>
    </div>
</div>
