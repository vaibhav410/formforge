<div class="p-4 space-y-4">
    <h3 class="text-sm font-semibold text-gray-700">Form settings</h3>
    <p class="text-xs text-gray-400">Select a field on the canvas to edit it, or adjust form-level settings here.</p>

    <label class="block">
        <span class="text-xs font-medium text-gray-500">Description</span>
        <textarea wire:model.live.debounce.800ms="schema.description" rows="3"
                  class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300"></textarea>
    </label>

    <label class="block">
        <span class="text-xs font-medium text-gray-500">Submit button label</span>
        <input type="text" wire:model.blur="schema.settings.submit_label"
               class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300" />
    </label>

    <label class="block">
        <span class="text-xs font-medium text-gray-500">Success message</span>
        <textarea wire:model.blur="schema.settings.success_message" rows="2"
                  class="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-indigo-400 focus:ring-indigo-300"></textarea>
    </label>

    @if ($form->isPublished())
        <div class="border-t pt-3 space-y-1">
            <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Share</span>
            <p class="text-xs text-gray-500 break-all">
                <a href="{{ $form->publicUrl() }}" target="_blank" class="text-indigo-600 hover:underline">{{ $form->publicUrl() }}</a>
            </p>
        </div>
    @endif
</div>
