<native:refreshable class="w-full h-full bg-white" @refresh="refresh">
    <native:column class="w-full p-4 gap-3">
        <native:column class="w-full gap-0 pt-2">
            <native:text class="text-3xl font-extrabold text-zinc-900">Events</native:text>
            <native:text class="text-sm text-zinc-500">{{ $siteName }}</native:text>
        </native:column>
        <native:outlined-text-input native:model.debounce.300ms="query" label="Search"
                                    placeholder="Event or venue" />

        @if ($error !== '')
            <native:column class="w-full p-3 rounded-lg bg-amber-50 border border-amber-200">
                <native:text class="text-sm text-amber-800">{{ $error }}</native:text>
            </native:column>
        @endif

        @forelse ($events as $event)
            <native:pressable native:key="event-{{ $event['id'] }}" ref="event-{{ $event['id'] }}"
                              class="w-full gap-1 p-4 rounded-xl bg-zinc-50 border border-zinc-200"
                              @tap="open({{ $event['id'] }})">
                <native:text class="text-lg font-semibold text-zinc-900">{{ $event['title'] }}</native:text>
                <native:text class="text-sm text-zinc-500">{{ $event['date'] }}@if ($event['venue']) · {{ $event['venue'] }}@endif</native:text>
                <native:text class="text-sm text-zinc-600">{{ $event['checked_in_count'] }} / {{ $event['attendee_count'] }} checked in</native:text>
                <native:progress-bar :value="$event['attendee_count'] > 0 ? $event['checked_in_count'] / $event['attendee_count'] : 0"
                                     color="#16a34a" track-color="#e4e4e7" class="w-full" />
            </native:pressable>
        @empty
            <native:column class="w-full items-center p-8 gap-2">
                @if (trim($query) !== '')
                    <native:text class="text-base text-zinc-500 text-center">No upcoming events match "{{ $query }}".</native:text>
                    <native:text class="text-sm text-zinc-400 text-center">Past events matching it are below, if any.</native:text>
                @else
                    <native:text class="text-base text-zinc-500 text-center">No upcoming events on this site.</native:text>
                    <native:text class="text-sm text-zinc-400 text-center">Pull down to refresh.</native:text>
                @endif
            </native:column>
        @endforelse

        @if (count($pastEvents) > 0)
            <native:pressable ref="toggle-past" a11y-label="{{ $showPast ? 'Hide past events' : 'Show past events' }}"
                              class="w-full flex-row items-center justify-center gap-2 p-3"
                              @tap="togglePast">
                <native:text class="text-sm font-semibold text-blue-600">
                    {{ $showPast ? 'Hide past events' : 'Show past events ('.count($pastEvents).')' }}
                </native:text>
                <native:icon name="{{ $showPast ? 'chevron.up' : 'chevron.down' }}" :size="14" class="text-blue-600" />
            </native:pressable>

            @if ($showPast)
                @foreach ($pastEvents as $event)
                    <native:pressable native:key="past-event-{{ $event['id'] }}" ref="past-event-{{ $event['id'] }}"
                                      class="w-full gap-1 p-4 rounded-xl bg-white border border-zinc-200"
                                      @tap="open({{ $event['id'] }})">
                        <native:text class="text-lg font-semibold text-zinc-500">{{ $event['title'] }}</native:text>
                        <native:text class="text-sm text-zinc-400">{{ $event['date'] }}@if ($event['venue']) · {{ $event['venue'] }}@endif</native:text>
                        <native:text class="text-sm text-zinc-400">{{ $event['checked_in_count'] }} / {{ $event['attendee_count'] }} checked in</native:text>
                    </native:pressable>
                @endforeach
            @endif
        @endif
    </native:column>
</native:refreshable>
