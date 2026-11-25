{{-- Sidebar menu search: filters the navigation as you type. "/" focuses, Enter opens the first match, Esc clears. --}}
<div
    x-data="{
        q: '',
        count: null,
        norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim() },
        apply() {
            const nav = this.$root.closest('.fi-sidebar-nav');
            if (! nav) return;
            const terms = this.norm(this.q).split(' ').filter(Boolean);
            nav.classList.toggle('fi-sb-searching', terms.length > 0);
            let total = 0;
            nav.querySelectorAll('.fi-sidebar-group').forEach((group) => {
                const groupLabel = this.norm(group.querySelector('.fi-sidebar-group-label')?.textContent);
                let shown = 0;
                group.querySelectorAll('.fi-sidebar-item').forEach((item) => {
                    const text = groupLabel + ' ' + this.norm(item.querySelector('.fi-sidebar-item-label')?.textContent);
                    const hit = terms.every((t) => text.includes(t));
                    item.style.display = terms.length && ! hit ? 'none' : '';
                    if (hit) shown++;
                });
                group.style.display = terms.length && shown === 0 ? 'none' : '';
                total += shown;
            });
            this.count = terms.length ? total : null;
        },
        first() {
            const link = [...this.$root.closest('.fi-sidebar-nav').querySelectorAll('.fi-sidebar-item a')].find((a) => a.closest('.fi-sidebar-item').style.display !== 'none');
            if (link) link.click();
        },
        clear() { this.q = ''; this.apply(); },
    }"
    x-init="$watch('q', () => apply())"
    x-on:keydown.window.slash="if (! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) && ! document.activeElement?.isContentEditable) { $event.preventDefault(); $store.sidebar.open?.(); $nextTick(() => $refs.input.focus()) }"
    x-show="$store.sidebar.isOpen"
    class="fi-sidebar-search px-1 pb-3"
>
    <label for="fi-sidebar-search-input" class="sr-only">Search the menu</label>
    <div class="relative">
        <x-filament::icon icon="heroicon-m-magnifying-glass" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-gray-500" />
        <input
            id="fi-sidebar-search-input"
            x-ref="input"
            x-model.debounce.80ms="q"
            x-on:keydown.escape.prevent="clear(); $el.blur()"
            x-on:keydown.enter.prevent="first()"
            type="search"
            autocomplete="off"
            spellcheck="false"
            placeholder="Search menu"
            class="block w-full rounded-lg border-0 bg-gray-950/5 py-2 ps-9 pe-10 text-sm text-gray-950 ring-1 ring-transparent placeholder:text-gray-500 focus:bg-white focus:ring-2 focus:ring-primary-500 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-400 dark:focus:bg-white/10"
        />
        <kbd x-show="q === ''" class="pointer-events-none absolute end-2 top-1/2 -translate-y-1/2 rounded border border-gray-300 px-1.5 text-[11px] font-medium text-gray-500 dark:border-white/15 dark:text-gray-400">/</kbd>
        <button x-show="q !== ''" x-on:click="clear(); $refs.input.focus()" type="button" aria-label="Clear search"
            class="absolute end-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
        </button>
    </div>
    <p x-show="count === 0" x-cloak class="mt-2 px-1 text-xs text-gray-500 dark:text-gray-400">No menu items match “<span x-text="q"></span>”.</p>
    <p x-show="count > 0" x-cloak class="sr-only" aria-live="polite" x-text="count + ' menu items found'"></p>
</div>

<style>
    /* While searching, open every group so matches inside collapsed groups are visible. */
    .fi-sidebar-nav.fi-sb-searching .fi-sidebar-group-items { display: flex !important; height: auto !important; overflow: visible !important; }
    .fi-sidebar-search input[type="search"]::-webkit-search-cancel-button { display: none; }
</style>
