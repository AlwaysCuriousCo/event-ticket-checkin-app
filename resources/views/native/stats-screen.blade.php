<native:top-bar title="Stats" back />

<native:refreshable class="w-full h-full bg-white" @refresh="refresh">
    <native:column class="w-full p-4 gap-4">
        <native:row class="w-full gap-3">
            <native:column class="flex-1 items-center gap-2 p-4 rounded-xl bg-zinc-50 border border-zinc-200">
                <native:text class="text-3xl font-extrabold text-zinc-900">{{ $checkedIn }}</native:text>
                <native:text class="text-sm text-zinc-500">checked in</native:text>
                <native:progress-bar :value="$total > 0 ? $checkedIn / $total : 0" color="#16a34a" track-color="#e4e4e7" class="w-full" />
            </native:column>
            <native:column class="flex-1 items-center p-4 rounded-xl bg-zinc-50 border border-zinc-200">
                <native:text class="text-3xl font-extrabold text-zinc-900">{{ $total }}</native:text>
                <native:text class="text-sm text-zinc-500">total</native:text>
            </native:column>
        </native:row>

        <native:column class="w-full gap-2">
            <native:text class="text-sm font-semibold text-zinc-700">By ticket type</native:text>
            @foreach ($byTicketType as $type)
                <native:column native:key="type-{{ $type['name'] }}" class="w-full gap-2 p-3 rounded-lg bg-zinc-50 border border-zinc-100">
                    <native:row class="w-full items-center justify-between">
                        <native:text class="text-base text-zinc-900">{{ $type['name'] }}</native:text>
                        <native:text class="text-base font-semibold text-zinc-700">{{ $type['checked_in'] }} / {{ $type['total'] }}</native:text>
                    </native:row>
                    <native:progress-bar :value="$type['total'] > 0 ? $type['checked_in'] / $type['total'] : 0" color="#16a34a" track-color="#e4e4e7" class="w-full" />
                </native:column>
            @endforeach
        </native:column>

        @if ($source === 'server')
            <native:text class="text-xs text-zinc-400 text-center">Live server counts · pull to refresh</native:text>
        @else
            <native:text class="text-xs text-amber-600 text-center">Offline — local counts, last synced {{ $lastSynced }}</native:text>
        @endif
    </native:column>
</native:refreshable>
