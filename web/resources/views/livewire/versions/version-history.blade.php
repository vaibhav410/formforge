<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $form->title }} — Versions</h2>
                <p class="text-sm text-gray-400">Every schema change is a snapshot. Rollback publishes a new version — history is never rewritten.</p>
            </div>
            <a href="{{ route('forms.builder', $form) }}" class="px-3 py-2 text-sm text-gray-600 bg-white border border-gray-300 rounded-md hover:bg-gray-50">← Builder</a>
        </div>
    </x-slot>

    <div class="py-8 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-4 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        <ol class="relative border-s-2 border-gray-200 space-y-6 ml-3">
            @foreach ($versions as $version)
                @php
                    $previous = $ordered->firstWhere('version', $version->version - 1);
                    $diff = $this->diff($version, $previous);
                    $schema = \App\Schema\FormSchema::fromArray($version->schema_json);
                    $isLive = $form->published_version_id === $version->id;
                @endphp
                <li class="ms-6" wire:key="version-{{ $version->id }}">
                    <span class="absolute -start-[9px] mt-1.5 w-4 h-4 rounded-full border-2 border-white
                        {{ $isLive ? 'bg-green-500' : ($version->status->value === 'draft' ? 'bg-amber-400' : 'bg-gray-300') }}"></span>

                    <div class="bg-white rounded-lg border {{ $isLive ? 'border-green-200 ring-1 ring-green-100' : 'border-gray-200' }} shadow-sm p-4">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-semibold text-gray-800">v{{ $version->version }}</span>

                            @if ($isLive)
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">Live</span>
                            @elseif ($version->status->value === 'draft')
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Draft</span>
                            @else
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">Superseded</span>
                            @endif

                            <span class="text-[11px] px-2 py-0.5 rounded-full
                                {{ match($version->source->value) {
                                    'ai' => 'bg-purple-100 text-purple-600',
                                    'import' => 'bg-blue-100 text-blue-600',
                                    'rollback' => 'bg-orange-100 text-orange-600',
                                    default => 'bg-gray-100 text-gray-500',
                                } }}">
                                {{ $version->source->value === 'ai' ? '✨ AI' : ucfirst($version->source->value) }}
                            </span>

                            <span class="text-xs text-gray-400">{{ $version->created_at->format('M j, Y H:i') }}</span>

                            <div class="flex-1"></div>

                            @if (! $isLive)
                                <button type="button"
                                        wire:click="rollback({{ $version->id }})"
                                        wire:confirm="Publish v{{ $version->version }}'s schema as a new live version?"
                                        class="text-xs px-3 py-1.5 text-orange-600 border border-orange-200 rounded-md hover:bg-orange-50 font-medium">
                                    Roll back to this
                                </button>
                            @endif
                        </div>

                        @if ($version->label)
                            <p class="mt-1.5 text-sm text-gray-600">{{ $version->label }}</p>
                        @endif

                        <div class="mt-2 flex items-center gap-3 text-xs text-gray-400 flex-wrap">
                            <span>{{ count($schema->fields()) }} fields · {{ count($schema->sections()) }} sections</span>
                            @if ($diff['added'] !== [])
                                <span class="text-green-600" title="{{ implode(', ', $diff['added']) }}">+{{ count($diff['added']) }} added</span>
                            @endif
                            @if ($diff['removed'] !== [])
                                <span class="text-red-500" title="{{ implode(', ', $diff['removed']) }}">−{{ count($diff['removed']) }} removed</span>
                            @endif
                            @if ($diff['changed'] !== [])
                                <span class="text-amber-600" title="{{ implode(', ', $diff['changed']) }}">~{{ count($diff['changed']) }} changed</span>
                            @endif
                            <span class="ml-auto">{{ $version->submissions()->count() }} submissions on this version</span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</div>
