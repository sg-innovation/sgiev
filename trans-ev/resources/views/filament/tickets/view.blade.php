<x-filament-panels::page>
    @php
        $d = $this->getThread();
        $t = $d['ticket'];
        $tz = \App\Models\Station::TIMEZONE;
        $statusColor = ['open' => 'info', 'in_progress' => 'primary', 'waiting_on_customer' => 'warning', 'resolved' => 'success', 'closed' => 'gray'][$t->status] ?? 'gray';
        $sla = $t->slaState();
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Conversation --}}
        <div class="space-y-4 lg:col-span-2">
            @foreach ($d['messages'] as $m)
                @if ($m['event'])
                    <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
                        <span>{{ $m['body'] }} · {{ $m['author'] }} · {{ $m['at'] }}</span>
                        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
                    </div>
                @else
                    <div @class([
                        'rounded-xl p-4 ring-1',
                        'bg-warning-50 ring-warning-200 dark:bg-warning-500/10 dark:ring-warning-400/20' => $m['internal'],
                        'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => ! $m['internal'] && $m['fromRequester'],
                        'bg-primary-50 ring-primary-200 dark:bg-primary-500/10 dark:ring-primary-400/20' => ! $m['internal'] && ! $m['fromRequester'],
                    ])>
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                            <span class="font-semibold text-gray-950 dark:text-white">
                                {{ $m['author'] }}
                                @if ($m['fromRequester']) <span class="font-normal text-gray-500">· requester</span> @endif
                                @if ($m['internal']) <x-filament::badge color="warning" size="sm" class="ms-1 inline-flex">Internal note</x-filament::badge> @endif
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">{{ $m['at'] }}</span>
                        </div>
                        <div class="mt-2 whitespace-pre-line text-sm text-gray-800 dark:text-gray-100">{{ $m['body'] }}</div>
                        @if (count($m['files']))
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($m['files'] as $f)
                                    <a href="{{ $f['url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200">
                                        <x-filament::icon icon="heroicon-m-paper-clip" class="h-3.5 w-3.5" /> {{ $f['name'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>

        {{-- Details --}}
        <div class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">Details</x-slot>
                <dl class="grid grid-cols-3 gap-x-3 gap-y-3 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="col-span-2"><x-filament::badge :color="$statusColor">{{ \App\Models\Ticket::STATUSES[$t->status] }}</x-filament::badge></dd>
                    <dt class="text-gray-500 dark:text-gray-400">Priority</dt>
                    <dd class="col-span-2"><x-filament::badge :color="$t->priority?->color ?? 'gray'">{{ $t->priority?->name }}</x-filament::badge></dd>
                    <dt class="text-gray-500 dark:text-gray-400">SLA</dt>
                    <dd class="col-span-2 text-gray-800 dark:text-gray-100">
                        @if ($sla)
                            <x-filament::badge :color="['overdue' => 'danger', 'due_soon' => 'warning', 'on_track' => 'success'][$sla]">{{ ['overdue' => 'Overdue', 'due_soon' => 'Due soon', 'on_track' => 'On track'][$sla] }}</x-filament::badge>
                        @endif
                        <div class="mt-1 text-xs text-gray-500">First reply by {{ $t->first_response_due_at?->setTimezone($tz)->format('d M, h:i A') }}
                            @if ($t->first_responded_at) (replied {{ $t->first_responded_at->setTimezone($tz)->format('d M, h:i A') }}) @endif
                            <br>Resolve by {{ $t->resolution_due_at?->setTimezone($tz)->format('d M, h:i A') }}</div>
                    </dd>
                    <dt class="text-gray-500 dark:text-gray-400">Topic</dt>
                    <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ $t->category?->name }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Assigned</dt>
                    <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ $t->assignee?->name ?? 'Unassigned' }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Handled by</dt>
                    <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ $t->company?->trade_name }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Opened</dt>
                    <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ $t->created_at->setTimezone($tz)->format('d M Y, h:i A') }}</dd>
                    @if ($t->rating)
                        <dt class="text-gray-500 dark:text-gray-400">Rating</dt>
                        <dd class="col-span-2 text-gray-800 dark:text-gray-100">{{ str_repeat('★', $t->rating) }}{{ str_repeat('☆', 5 - $t->rating) }}</dd>
                    @endif
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Requester</x-slot>
                <div class="text-sm">
                    <p class="font-semibold text-gray-950 dark:text-white">{{ $t->requester?->name }}</p>
                    <p class="text-gray-500 dark:text-gray-400">{{ $t->requester?->email }}</p>
                    @if ($t->requester?->phone) <p class="text-gray-500 dark:text-gray-400">+91 {{ $t->requester->phone }}</p> @endif
                    <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $t->requesterCompany?->trade_name ?? 'Customer' }}</p>
                    @if ($t->booking)
                        <a href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $t->booking]) }}" class="mt-3 inline-flex items-center gap-1 text-primary-600 hover:underline dark:text-primary-400">
                            <x-filament::icon icon="heroicon-m-calendar-days" class="h-4 w-4" /> {{ $t->booking->code }} · {{ $t->booking->station?->name }}
                        </a>
                    @endif
                </div>
            </x-filament::section>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
