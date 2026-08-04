@extends('public.layout')

@section('title', $schema->title())

@section('content')
    @if ($preview)
        <div class="bg-amber-400 text-amber-900 text-center text-sm font-medium py-2 sticky top-0 z-20">
            Preview — this is your current draft. Submissions are disabled.
        </div>
    @endif

    <div class="max-w-2xl mx-auto px-4 py-10"
         x-data="publicForm({
            logic: {{ json_encode(collect($schema->answerableFields())->mapWithKeys(fn ($f) => [$f['key'] => ['logic' => $f['logic'], 'hidden' => $f['hidden'] && $f['type'] !== 'hidden']]), JSON_HEX_APOS) }},
            eventUrl: '{{ $preview ? '' : route('forms.public.event', $form) }}',
            csrf: '{{ csrf_token() }}'
         })">

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="h-2 bg-indigo-500"></div>
            <div class="px-8 pt-8 pb-2">
                <h1 class="text-2xl font-semibold text-gray-800">{{ $schema->title() }}</h1>
                @if ($schema->description())
                    <p class="mt-2 text-gray-500">{{ $schema->description() }}</p>
                @endif
            </div>

            <form method="POST"
                  action="{{ $preview ? '#' : route('forms.public.submit', $form) }}"
                  enctype="multipart/form-data"
                  class="px-8 pb-8"
                  x-on:input="onInput($event)"
                  x-on:change="onInput($event)"
                  x-on:focusin="onFocus($event)"
                  @if ($preview) x-on:submit.prevent @endif>
                @csrf
                <input type="hidden" name="_rt" value="{{ $renderToken }}">
                {{-- Honeypot: hidden from humans, irresistible to bots. --}}
                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label>Website <input type="text" name="_website" tabindex="-1" autocomplete="off"></label>
                </div>

                @if ($errors->any())
                    <div class="mt-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
                        Please fix the {{ $errors->count() }} highlighted {{ Str::plural('problem', $errors->count()) }} below.
                    </div>
                @endif

                @foreach ($schema->sections() as $section)
                    <div class="mt-6">
                        @if (count($schema->sections()) > 1 || $section['title'] !== 'Section 1')
                            <h2 class="text-lg font-semibold text-gray-700 border-b border-gray-100 pb-2">{{ $section['title'] }}</h2>
                            @if ($section['description'])
                                <p class="text-sm text-gray-400 mt-1">{{ $section['description'] }}</p>
                            @endif
                        @endif

                        <div class="mt-4 space-y-5">
                            @foreach ($section['fields'] as $field)
                                @include('public.partials.field', ['field' => $field])
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <button type="submit"
                        class="mt-8 w-full py-3 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2 disabled:opacity-50"
                        @if ($preview) disabled @endif>
                    {{ $schema->settings()['submit_label'] }}
                </button>
            </form>
        </div>
    </div>
@endsection
