@php
    /** @var array $field */
    /** @var int $si */
    /** @var int $fi */
    $type = \App\Enums\FieldType::from($field['type']);
    $base = "schema.sections.$si.fields.$fi";
    $operators = ['equals', 'not_equals', 'contains', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'];
@endphp

<div class="p-4 space-y-4" wire:key="settings-{{ $field['id'] }}">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700 flex items-center gap-2">
            <x-field-icon :type="$type" class="w-4 h-4 text-indigo-500" />
            {{ $type->label() }}
        </h3>
        <button type="button" wire:click="select(null)" class="text-gray-400 hover:text-gray-600" title="Close">✕</button>
    </div>

    <label class="block">
        <span class="text-xs font-medium text-gray-500">Label</span>
        <input type="text" wire:model.live.debounce.600ms="{{ $base }}.label"
               class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300" />
    </label>

    <label class="block">
        <span class="text-xs font-medium text-gray-500">Key <span class="text-gray-400">(answer column)</span></span>
        <input type="text" wire:model.blur="{{ $base }}.key"
               class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono focus:border-indigo-400 focus:ring-indigo-300" />
    </label>

    @if ($type !== \App\Enums\FieldType::Heading)
        <label class="block">
            <span class="text-xs font-medium text-gray-500">Help text</span>
            <input type="text" wire:model.live.debounce.600ms="{{ $base }}.description"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300" />
        </label>

        @if (! in_array($type, [\App\Enums\FieldType::Checkbox, \App\Enums\FieldType::Radio, \App\Enums\FieldType::File, \App\Enums\FieldType::Signature, \App\Enums\FieldType::Rating], true))
            <label class="block">
                <span class="text-xs font-medium text-gray-500">Placeholder</span>
                <input type="text" wire:model.live.debounce.600ms="{{ $base }}.placeholder"
                       class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300" />
            </label>
        @endif

        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" wire:model.live="{{ $base }}.required"
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-300" />
                Required
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" wire:model.live="{{ $base }}.hidden"
                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-300" />
                Hidden
            </label>
        </div>

        <label class="block">
            <span class="text-xs font-medium text-gray-500">Default value</span>
            <input type="text" wire:model.blur="{{ $base }}.default"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300" />
        </label>

        <label class="block">
            <span class="text-xs font-medium text-gray-500">CSS class</span>
            <input type="text" wire:model.blur="{{ $base }}.css_class"
                   class="mt-1 w-full rounded-md border-gray-300 text-sm font-mono focus:border-indigo-400 focus:ring-indigo-300" />
        </label>
    @endif

    {{-- ── Options ─────────────────────────────────────────────── --}}
    @if ($type->hasOptions())
        <div class="border-t pt-3">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Options</span>
                <button type="button" wire:click="addOption('{{ $field['id'] }}')"
                        class="text-xs text-indigo-600 hover:text-indigo-500 font-medium">+ Add</button>
            </div>
            <div class="space-y-1.5">
                @foreach ($field['options'] ?? [] as $oi => $option)
                    <div class="flex items-center gap-1.5" wire:key="opt-{{ $field['id'] }}-{{ $oi }}">
                        <input type="text" wire:model.blur="{{ $base }}.options.{{ $oi }}.label"
                               placeholder="Label"
                               class="flex-1 rounded border-gray-300 text-xs focus:border-indigo-400 focus:ring-indigo-300" />
                        <input type="text" wire:model.blur="{{ $base }}.options.{{ $oi }}.value"
                               placeholder="value"
                               class="w-24 rounded border-gray-300 text-xs font-mono focus:border-indigo-400 focus:ring-indigo-300" />
                        <button type="button" wire:click="removeOption('{{ $field['id'] }}', {{ $oi }})"
                                class="text-gray-300 hover:text-red-500">✕</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Validation ──────────────────────────────────────────── --}}
    @if ($type->collectsAnswer() && $type !== \App\Enums\FieldType::Hidden)
        <div class="border-t pt-3">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Validation</span>
            <div class="grid grid-cols-2 gap-2 mt-2">
                @if (in_array($type, [\App\Enums\FieldType::Number, \App\Enums\FieldType::Rating], true))
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Min</span>
                        <input type="number" wire:model.blur="{{ $base }}.validation.min"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Max</span>
                        <input type="number" wire:model.blur="{{ $base }}.validation.max"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                @endif
                @if (in_array($type, [\App\Enums\FieldType::Text, \App\Enums\FieldType::Textarea, \App\Enums\FieldType::Password], true))
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Min length</span>
                        <input type="number" min="0" wire:model.blur="{{ $base }}.validation.min_length"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Max length</span>
                        <input type="number" min="0" wire:model.blur="{{ $base }}.validation.max_length"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                    <label class="block col-span-2">
                        <span class="text-[11px] text-gray-400">Regex pattern</span>
                        <input type="text" wire:model.blur="{{ $base }}.validation.regex"
                               placeholder="^[A-Z]{2}-\d{4}$"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs font-mono" />
                    </label>
                @endif
                @if ($type === \App\Enums\FieldType::Date)
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Earliest</span>
                        <input type="date" wire:model.blur="{{ $base }}.validation.min"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                    <label class="block">
                        <span class="text-[11px] text-gray-400">Latest</span>
                        <input type="date" wire:model.blur="{{ $base }}.validation.max"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                @endif
                @if ($type === \App\Enums\FieldType::File)
                    <label class="block col-span-2">
                        <span class="text-[11px] text-gray-400">Allowed extensions (comma separated)</span>
                        <input type="text" value="{{ implode(', ', $field['validation']['mimes'] ?? []) }}"
                               x-on:change="$wire.set('{{ $base }}.validation.mimes', $event.target.value.split(',').map(s => s.trim()).filter(Boolean))"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs font-mono" />
                    </label>
                    <label class="block col-span-2">
                        <span class="text-[11px] text-gray-400">Max size (KB)</span>
                        <input type="number" min="1" wire:model.blur="{{ $base }}.validation.max_size_kb"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                @endif
                @if ($type === \App\Enums\FieldType::Rating)
                    <label class="block col-span-2">
                        <span class="text-[11px] text-gray-400">Stars (2–10)</span>
                        <input type="number" min="2" max="10" wire:model.blur="{{ $base }}.meta.rating_max"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                @endif
                @if ($type === \App\Enums\FieldType::Textarea)
                    <label class="block col-span-2">
                        <span class="text-[11px] text-gray-400">Rows</span>
                        <input type="number" min="2" max="20" wire:model.blur="{{ $base }}.meta.rows"
                               class="mt-0.5 w-full rounded border-gray-300 text-xs" />
                    </label>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Conditional logic ───────────────────────────────────── --}}
    @if ($type->collectsAnswer())
        <div class="border-t pt-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Conditional logic</span>
                <button type="button" wire:click="toggleLogic('{{ $field['id'] }}')"
                        class="text-xs font-medium {{ $field['logic'] !== null ? 'text-red-500 hover:text-red-400' : 'text-indigo-600 hover:text-indigo-500' }}">
                    {{ $field['logic'] !== null ? 'Remove' : '+ Enable' }}
                </button>
            </div>

            @if ($field['logic'] !== null)
                <div class="mt-2 space-y-2 text-xs">
                    <div class="flex items-center gap-1.5 text-gray-600">
                        <select wire:model.live="{{ $base }}.logic.action" class="rounded border-gray-300 text-xs py-1">
                            <option value="show">Show</option>
                            <option value="hide">Hide</option>
                        </select>
                        this field when
                        <select wire:model.live="{{ $base }}.logic.match" class="rounded border-gray-300 text-xs py-1">
                            <option value="all">all</option>
                            <option value="any">any</option>
                        </select>
                        match:
                    </div>

                    @foreach ($field['logic']['conditions'] as $ci => $condition)
                        <div class="flex items-center gap-1" wire:key="cond-{{ $field['id'] }}-{{ $ci }}">
                            <select wire:model.live="{{ $base }}.logic.conditions.{{ $ci }}.field"
                                    class="flex-1 rounded border-gray-300 text-xs py-1 font-mono">
                                @foreach ($fieldKeys as $key)
                                    @if ($key !== $field['key'])
                                        <option value="{{ $key }}">{{ $key }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <select wire:model.live="{{ $base }}.logic.conditions.{{ $ci }}.operator"
                                    class="rounded border-gray-300 text-xs py-1">
                                @foreach ($operators as $op)
                                    <option value="{{ $op }}">{{ str_replace('_', ' ', $op) }}</option>
                                @endforeach
                            </select>
                            @if (! in_array($condition['operator'], ['is_empty', 'is_not_empty'], true))
                                <input type="text" wire:model.blur="{{ $base }}.logic.conditions.{{ $ci }}.value"
                                       class="w-20 rounded border-gray-300 text-xs py-1" />
                            @endif
                            <button type="button" wire:click="removeCondition('{{ $field['id'] }}', {{ $ci }})"
                                    class="text-gray-300 hover:text-red-500">✕</button>
                        </div>
                    @endforeach

                    <button type="button" wire:click="addCondition('{{ $field['id'] }}')"
                            class="text-indigo-600 hover:text-indigo-500 font-medium">+ Add condition</button>
                </div>
            @endif
        </div>
    @endif
</div>
