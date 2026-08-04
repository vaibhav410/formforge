@php
    /** @var array $field */
    $type = \App\Enums\FieldType::from($field['type']);
    $key = $field['key'];
    $old = old($key, $field['default'] ?? null);
    $inputClasses = 'w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-400 focus:ring-indigo-300 '
        .($field['css_class'] ?? '');
    $hasError = $errors->has($key) || $errors->has("$key.*") || $errors->has("$key.line1");
@endphp

@if ($type === \App\Enums\FieldType::Hidden)
    <input type="hidden" name="{{ $key }}" value="{{ old($key, request()->query($key, $field['default'] ?? '')) }}">
@elseif ($type === \App\Enums\FieldType::Heading)
    @php $level = $field['meta']['heading_level'] ?? 'h2'; @endphp
    <{{ $level }} class="text-{{ $level === 'h2' ? 'xl' : 'lg' }} font-semibold text-gray-700 pt-2 {{ $field['css_class'] ?? '' }}">
        {{ $field['label'] }}
    </{{ $level }}>
    @if ($field['description'])
        <p class="text-sm text-gray-400 -mt-3">{{ $field['description'] }}</p>
    @endif
@else
    <div x-show="visible('{{ $key }}')" x-cloak data-field-key="{{ $key }}" x-transition.opacity.duration.150ms>
        <label class="block">
            <span class="text-sm font-medium text-gray-700">
                {{ $field['label'] }}
                @if ($field['required']) <span class="text-red-500" aria-hidden="true">*</span> @endif
            </span>
            @if ($field['description'])
                <span class="block text-xs text-gray-400 mt-0.5">{{ $field['description'] }}</span>
            @endif

            @switch($type)
                @case(\App\Enums\FieldType::Textarea)
                    <textarea name="{{ $key }}" rows="{{ $field['meta']['rows'] ?? 4 }}"
                              placeholder="{{ $field['placeholder'] }}"
                              class="mt-1.5 {{ $inputClasses }}">{{ $old }}</textarea>
                    @break

                @case(\App\Enums\FieldType::Dropdown)
                    <select name="{{ $key }}" class="mt-1.5 {{ $inputClasses }}">
                        <option value="">{{ $field['placeholder'] ?: 'Select…' }}</option>
                        @foreach ($field['options'] ?? [] as $option)
                            <option value="{{ $option['value'] }}" @selected($old === $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @break

                @case(\App\Enums\FieldType::Radio)
                    <div class="mt-2 space-y-2">
                        @foreach ($field['options'] ?? [] as $option)
                            <label class="flex items-center gap-2.5 text-sm text-gray-600 cursor-pointer">
                                <input type="radio" name="{{ $key }}" value="{{ $option['value'] }}"
                                       @checked($old === $option['value'])
                                       class="border-gray-300 text-indigo-600 focus:ring-indigo-400">
                                {{ $option['label'] }}
                            </label>
                        @endforeach
                    </div>
                    @break

                @case(\App\Enums\FieldType::Checkbox)
                    <div class="mt-2 space-y-2">
                        @foreach ($field['options'] ?? [] as $option)
                            <label class="flex items-center gap-2.5 text-sm text-gray-600 cursor-pointer">
                                <input type="checkbox" name="{{ $key }}[]" value="{{ $option['value'] }}"
                                       @checked(in_array($option['value'], (array) ($old ?? []), true))
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-400">
                                {{ $option['label'] }}
                            </label>
                        @endforeach
                    </div>
                    @break

                @case(\App\Enums\FieldType::File)
                    <input type="file" name="{{ $key }}"
                           @if (!empty($field['validation']['mimes'])) accept="{{ implode(',', array_map(fn ($m) => '.'.$m, $field['validation']['mimes'])) }}" @endif
                           class="mt-1.5 block w-full text-sm text-gray-500 file:mr-3 file:px-4 file:py-2 file:rounded-lg file:border-0 file:bg-indigo-50 file:text-indigo-600 file:text-sm file:font-medium hover:file:bg-indigo-100">
                    @if (!empty($field['validation']['mimes']) || !empty($field['validation']['max_size_kb']))
                        <span class="block text-xs text-gray-400 mt-1">
                            @if (!empty($field['validation']['mimes'])) {{ strtoupper(implode(', ', $field['validation']['mimes'])) }}. @endif
                            @if (!empty($field['validation']['max_size_kb'])) Max {{ round($field['validation']['max_size_kb'] / 1024, 1) }} MB. @endif
                        </span>
                    @endif
                    @break

                @case(\App\Enums\FieldType::Rating)
                    @php $max = (int) ($field['meta']['rating_max'] ?? 5); @endphp
                    <div class="mt-2 flex flex-row-reverse justify-end gap-1 rating-group">
                        @for ($i = $max; $i >= 1; $i--)
                            <label class="cursor-pointer text-2xl text-gray-300 transition peer-checked:text-amber-400 rating-star" title="{{ $i }}/{{ $max }}">
                                <input type="radio" name="{{ $key }}" value="{{ $i }}" class="sr-only" @checked((int) $old === $i)>
                                <span aria-hidden="true">★</span>
                            </label>
                        @endfor
                    </div>
                    @break

                @case(\App\Enums\FieldType::Address)
                    @php $oldAddr = (array) ($old ?? []); @endphp
                    <div class="mt-1.5 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <input type="text" name="{{ $key }}[line1]" placeholder="Street address" value="{{ $oldAddr['line1'] ?? '' }}" class="sm:col-span-2 {{ $inputClasses }}">
                        <input type="text" name="{{ $key }}[line2]" placeholder="Apartment, suite… (optional)" value="{{ $oldAddr['line2'] ?? '' }}" class="sm:col-span-2 {{ $inputClasses }}">
                        <input type="text" name="{{ $key }}[city]" placeholder="City" value="{{ $oldAddr['city'] ?? '' }}" class="{{ $inputClasses }}">
                        <input type="text" name="{{ $key }}[state]" placeholder="State / Region" value="{{ $oldAddr['state'] ?? '' }}" class="{{ $inputClasses }}">
                        <input type="text" name="{{ $key }}[postal_code]" placeholder="Postal code" value="{{ $oldAddr['postal_code'] ?? '' }}" class="{{ $inputClasses }}">
                        <input type="text" name="{{ $key }}[country]" placeholder="Country" value="{{ $oldAddr['country'] ?? '' }}" class="{{ $inputClasses }}">
                    </div>
                    @break

                @case(\App\Enums\FieldType::Signature)
                    <div class="mt-1.5" x-data="signaturePad('{{ $key }}')">
                        <canvas x-ref="canvas"
                                class="w-full h-32 rounded-lg border border-gray-300 bg-white touch-none cursor-crosshair"
                                x-on:pointerdown="start($event)" x-on:pointermove="move($event)"
                                x-on:pointerup="stop()" x-on:pointerleave="stop()"></canvas>
                        <input type="hidden" name="{{ $key }}" x-ref="value">
                        <button type="button" x-on:click="clearPad()" class="mt-1 text-xs text-gray-400 hover:text-gray-600">Clear signature</button>
                    </div>
                    @break

                @case(\App\Enums\FieldType::Color)
                    <input type="color" name="{{ $key }}" value="{{ $old ?: '#4f46e5' }}"
                           class="mt-1.5 h-10 w-20 rounded border-gray-300 cursor-pointer">
                    @break

                @case(\App\Enums\FieldType::Number)
                    <input type="number" name="{{ $key }}" value="{{ $old }}"
                           placeholder="{{ $field['placeholder'] }}"
                           @if (isset($field['validation']['min'])) min="{{ $field['validation']['min'] }}" @endif
                           @if (isset($field['validation']['max'])) max="{{ $field['validation']['max'] }}" @endif
                           @if (isset($field['meta']['step'])) step="{{ $field['meta']['step'] }}" @endif
                           class="mt-1.5 {{ $inputClasses }}">
                    @break

                @default
                    @php
                        $htmlType = match ($type) {
                            \App\Enums\FieldType::Email => 'email',
                            \App\Enums\FieldType::Phone => 'tel',
                            \App\Enums\FieldType::Date => 'date',
                            \App\Enums\FieldType::Time => 'time',
                            \App\Enums\FieldType::Url => 'url',
                            \App\Enums\FieldType::Password => 'password',
                            default => 'text',
                        };
                    @endphp
                    <input type="{{ $htmlType }}" name="{{ $key }}"
                           value="{{ $type === \App\Enums\FieldType::Password ? '' : $old }}"
                           placeholder="{{ $field['placeholder'] }}"
                           class="mt-1.5 {{ $inputClasses }}">
            @endswitch
        </label>

        @error($key)
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
        @error($key.'.*')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
        @foreach (['line1', 'city', 'postal_code', 'country'] as $part)
            @error($key.'.'.$part)
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        @endforeach
    </div>
@endif
