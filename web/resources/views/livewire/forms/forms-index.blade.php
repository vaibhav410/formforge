<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Forms</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('forms.import') }}"
                   class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                    Import Word/Excel
                </a>
                <a href="{{ route('forms.ai-create') }}"
                   class="px-4 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-md hover:bg-indigo-100">
                    ✨ Generate with AI
                </a>
                <button type="button" wire:click="createForm"
                        class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-500">
                    + New form
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3 mb-6">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search forms…"
                   class="w-72 rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300" />
            <select wire:model.live="status" class="rounded-md border-gray-300 text-sm">
                <option value="all">All statuses</option>
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="archived">Archived</option>
            </select>
        </div>

        @if ($forms->isEmpty())
            <div class="text-center py-20 bg-white rounded-lg border border-dashed border-gray-300">
                <p class="text-gray-500 mb-3">No forms yet.</p>
                <button type="button" wire:click="createForm"
                        class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-500">
                    Create your first form
                </button>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($forms as $form)
                    <div wire:key="form-{{ $form->id }}"
                         class="bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow transition p-4 flex flex-col">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('forms.builder', $form) }}" class="font-medium text-gray-800 hover:text-indigo-600 truncate">
                                {{ $form->title }}
                            </a>
                            <span class="shrink-0 text-[11px] px-2 py-0.5 rounded-full
                                {{ $form->status->value === 'published' ? 'bg-green-100 text-green-700' : ($form->status->value === 'draft' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500') }}">
                                {{ ucfirst($form->status->value) }}
                            </span>
                        </div>

                        <p class="text-xs text-gray-400 mt-1 line-clamp-2 flex-1">{{ $form->description }}</p>

                        <div class="flex items-center gap-4 mt-3 text-xs text-gray-500">
                            <span title="Views">👁 {{ number_format($form->views_count) }}</span>
                            <span title="Submissions">📥 {{ number_format($form->submissions_count) }}</span>
                            <span class="ml-auto text-gray-400">{{ $form->updated_at->diffForHumans(short: true) }}</span>
                        </div>

                        <div class="flex items-center gap-1 mt-3 pt-3 border-t border-gray-100 text-xs">
                            <a href="{{ route('forms.builder', $form) }}" class="px-2 py-1 rounded text-gray-600 hover:bg-gray-100">Edit</a>
                            <a href="{{ route('forms.submissions', $form) }}" class="px-2 py-1 rounded text-gray-600 hover:bg-gray-100">Submissions</a>
                            <a href="{{ route('forms.analytics', $form) }}" class="px-2 py-1 rounded text-gray-600 hover:bg-gray-100">Analytics</a>
                            @if ($form->isPublished())
                                <a href="{{ $form->publicUrl() }}" target="_blank" class="px-2 py-1 rounded text-indigo-600 hover:bg-indigo-50">Open ↗</a>
                            @endif
                            <button type="button"
                                    wire:click="deleteForm({{ $form->id }})"
                                    wire:confirm="Delete '{{ $form->title }}' and all its submissions?"
                                    class="ml-auto px-2 py-1 rounded text-red-400 hover:bg-red-50 hover:text-red-600">
                                Delete
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6">{{ $forms->links() }}</div>
        @endif
    </div>
</div>
