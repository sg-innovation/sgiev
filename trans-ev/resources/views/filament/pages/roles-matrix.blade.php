<x-filament-panels::page>
    @php
        $roles = $this->getRoles();
        $areas = $this->getAreas();
        $canManage = $this->canManage();
        $totalPerms = collect($areas)->sum(fn ($a) => count($a['perms']));
    @endphp

    <div class="flex items-start gap-3 rounded-xl bg-primary-50 p-4 text-sm text-primary-900 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-100 dark:ring-primary-400/20">
        <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400" />
        <p>
            A <strong>role</strong> is a set of permissions. Platform super admins run the whole marketplace; every other role works only inside its own
            company, and station managers only see the stations assigned to them.
            @if ($canManage)
                Create a custom role or change what a role can do. Changes apply immediately and are written to the audit log.
            @else
                Only platform super admins can change roles.
            @endif
        </p>
    </div>

    {{-- Role cards --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($roles as $role)
            @php $pct = $role['name'] === 'super_admin' ? 100 : (int) round(count($role['perms']) / max(1, $totalPerms) * 100); @endphp
            <div class="flex flex-col rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $role['system'] ? 'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' : 'bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-400' }}">
                            <x-filament::icon :icon="$role['name'] === 'super_admin' ? 'heroicon-o-star' : ($role['system'] ? 'heroicon-o-identification' : 'heroicon-o-sparkles')" class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="font-semibold text-gray-950 dark:text-white">{{ $role['label'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $role['users'] }} {{ Str::plural('person', $role['users']) }}</p>
                        </div>
                    </div>
                    <div class="shrink-0">
                        <x-filament::badge :color="$role['system'] ? 'gray' : 'warning'" size="sm">{{ $role['system'] ? 'Built-in' : 'Custom' }}</x-filament::badge>
                    </div>
                </div>
                <p class="mt-3 flex-1 text-sm text-gray-600 dark:text-gray-300">{{ $role['description'] ?: 'No description.' }}</p>
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Permissions</span>
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $role['name'] === 'super_admin' ? 'All' : count($role['perms']).' of '.$totalPerms }}</span>
                    </div>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-full rounded-full {{ $role['system'] ? 'bg-primary-500' : 'bg-warning-500' }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
                @if ($canManage && ! $role['locked'])
                    <div class="mt-4 flex items-center gap-4 border-t border-gray-100 pt-3 dark:border-white/5">
                        {{ ($this->editRoleAction)(['role' => $role['name']]) }}
                        @unless ($role['system'])
                            {{ ($this->deleteRoleAction)(['role' => $role['name']]) }}
                        @endunless
                    </div>
                @elseif ($role['locked'])
                    <p class="mt-4 flex items-center gap-1.5 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-3.5 w-3.5" /> Always has every permission
                    </p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Permission matrix --}}
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-5 py-4 dark:border-white/10">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Permission matrix</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">What each role can do, area by area.</p>
            </div>
            <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-1.5"><x-filament::icon icon="heroicon-s-check-circle" class="h-4 w-4 text-success-500" /> Allowed</span>
                <span class="flex items-center gap-1.5"><span class="inline-block h-1.5 w-3 rounded-full bg-gray-200 dark:bg-white/15"></span> Not allowed</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th scope="col" class="sticky left-0 z-10 bg-gray-50 px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400" style="min-width: 16rem">Permission</th>
                        @foreach ($roles as $role)
                            <th scope="col" class="px-3 py-3 text-center text-xs font-semibold text-gray-700 dark:text-gray-200" style="min-width: 6.5rem">{{ $role['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($areas as $key => $area)
                        <tr class="bg-gray-50/60 dark:bg-white/[0.02]">
                            <th scope="rowgroup" colspan="{{ count($roles) + 1 }}" class="sticky left-0 px-5 py-2 text-start">
                                <span class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                    <x-filament::icon :icon="$area['icon']" class="h-4 w-4 text-primary-500" /> {{ $area['label'] }}
                                </span>
                            </th>
                        </tr>
                        @foreach ($area['perms'] as $perm => $label)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/5">
                                <th scope="row" class="sticky left-0 bg-white px-5 py-2.5 text-start font-normal dark:bg-gray-900">
                                    <span class="text-gray-800 dark:text-gray-100">{{ $label }}</span>
                                    <span class="ms-1 font-mono text-[11px] text-gray-400 dark:text-gray-500">{{ $perm }}</span>
                                </th>
                                @foreach ($roles as $role)
                                    @php $allowed = in_array($perm, $role['perms'], true); @endphp
                                    <td class="px-3 py-2.5 text-center">
                                        @if ($allowed)
                                            <span role="img" aria-label="{{ $role['label'] }}: allowed" title="Allowed" class="inline-flex">
                                                <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-success-500" />
                                            </span>
                                        @else
                                            <span role="img" aria-label="{{ $role['label'] }}: not allowed" title="Not allowed" class="inline-block h-1.5 w-3 rounded-full bg-gray-200 dark:bg-white/15"></span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
