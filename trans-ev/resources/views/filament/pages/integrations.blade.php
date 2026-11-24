<x-filament-panels::page>
    <div class="rounded-xl border border-amber-300/50 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100">
        Keys and passwords are encrypted before they are stored and are never shown again. Every change is recorded in the audit log (which settings changed, by whom and when, never the secret value).
    </div>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-m-check">Save settings</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
