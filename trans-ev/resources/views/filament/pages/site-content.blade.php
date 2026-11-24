<x-filament-panels::page>
    <div class="flex items-start gap-3 rounded-xl bg-primary-50 p-4 text-sm text-primary-900 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-100 dark:ring-primary-400/20">
        <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400" />
        <p>
            Edit the wording on the homepage and information pages. Live numbers can be inserted with
            <code>{stations}</code> <code>{online}</code> <code>{cities}</code> <code>{chargers}</code> <code>{max_kw}</code>
            <code>{from_rate}</code> <code>{session_fee}</code> <code>{gst_rate}</code> <code>{idle_fee}</code> <code>{idle_grace}</code>.
            Terms, Privacy and Refund policy are edited under Website pages.
        </p>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <div>
            <x-filament::button type="submit" icon="heroicon-m-check">Save content</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
