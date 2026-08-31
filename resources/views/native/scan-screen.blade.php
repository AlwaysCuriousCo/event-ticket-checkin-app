@if ($phase === 'green' || $phase === 'amber')
    @php
        $bg = $phase === 'green' ? 'bg-green-600' : 'bg-amber-500';
        $dim = $phase === 'green' ? 'bg-green-950' : 'bg-amber-950';
        $tint = $phase === 'green' ? 'text-green-100' : 'text-amber-100';
        $dark = $phase === 'green' ? 'text-green-700' : 'text-amber-700';
    @endphp
    <native:column class="w-full h-full items-center justify-center p-4 {{ $dim }}">
        <native:pressable ref="result-{{ $phase }}" class="w-full gap-3 p-6 rounded-3xl {{ $bg }}" @tap="dismiss">
            <native:row class="w-full items-center justify-between">
                <native:column class="px-3 py-1 rounded-full bg-white/25">
                    <native:text class="text-xs font-bold text-white">TICKET</native:text>
                </native:column>
                @if (count($groupMates) > 0)
                    <native:column class="px-3 py-1 rounded-full bg-white/25">
                        <native:text class="text-xs font-bold text-white">GROUP OF {{ count($groupMates) + 1 }}</native:text>
                    </native:column>
                @endif
            </native:row>

            <native:column class="w-full items-center gap-2 py-2">
                <native:icon name="{{ $phase === 'green' ? 'checkmark.circle.fill' : 'exclamationmark.triangle.fill' }}" :size="72" class="text-white" />
                <native:text class="text-2xl font-extrabold text-white text-center">
                    {{ $phase === 'green' ? 'Valid Ticket' : 'Already Scanned' }}
                </native:text>
                <native:text class="text-xl font-bold text-white text-center">{{ $attendeeName }}</native:text>
                <native:text class="text-base font-semibold {{ $tint }} text-center">{{ $ticketName }}</native:text>
                @if ($phase === 'amber' && $checkedInInfo !== '')
                    <native:text class="text-sm {{ $tint }} text-center">{{ $checkedInInfo }}</native:text>
                @endif
                @if ($eventLabel !== '')
                    <native:text class="text-sm {{ $tint }} text-center">{{ $eventLabel }}</native:text>
                @endif
                <native:pressable ref="view-details" class="p-1" @tap="viewDetails">
                    <native:text class="text-sm font-semibold text-white underline">View details</native:text>
                </native:pressable>
            </native:column>

                @if (count($groupMates) > 0)
                    <native:divider class="w-full" />
                    <native:row class="w-full items-center justify-between">
                        <native:text class="text-sm font-bold {{ $tint }}">Linked tickets ({{ count($groupMates) }})</native:text>
                        @if (collect($groupMates)->contains('eligible', true))
                            <native:pressable ref="group-checkin-all" class="px-3 py-1 rounded-full bg-white/25" @tap="checkinGroup">
                                <native:text class="text-sm font-bold text-white">Check in all</native:text>
                            </native:pressable>
                        @endif
                    </native:row>
                    @foreach ($groupMates as $mate)
                        <native:row native:key="mate-{{ $mate['id'] }}" class="w-full items-center justify-between">
                            <native:column class="flex-1 gap-0">
                                <native:text class="text-base font-semibold text-white">{{ $mate['name'] }}</native:text>
                                <native:text class="text-xs {{ $tint }}">{{ $mate['ticket'] }}</native:text>
                            </native:column>
                            @if ($mate['checked_in'])
                                <native:text class="text-sm font-bold text-white">IN ✓</native:text>
                            @elseif ($mate['eligible'])
                                <native:pressable ref="mate-in-{{ $mate['id'] }}" class="px-3 py-1 rounded-full bg-white"
                                                  @tap="checkinMate({{ $mate['id'] }})">
                                    <native:text class="text-sm font-bold {{ $dark }}">Check in</native:text>
                                </native:pressable>
                            @else
                                <native:text class="text-xs {{ $tint }}">not eligible</native:text>
                            @endif
                        </native:row>
                    @endforeach
                @endif

            <native:text class="text-sm {{ $tint }} text-center w-full">Tap card to continue scanning</native:text>
        </native:pressable>
    </native:column>
@elseif ($phase === 'red')
    <native:column class="w-full h-full items-center justify-center p-4 bg-red-950">
        <native:pressable ref="result-red" class="w-full gap-3 p-6 rounded-3xl bg-red-600" @tap="dismiss">
            <native:row class="w-full">
                <native:column class="px-3 py-1 rounded-full bg-white/25">
                    <native:text class="text-xs font-bold text-white">TICKET</native:text>
                </native:column>
            </native:row>
            <native:column class="w-full items-center gap-2 py-4">
                <native:icon name="xmark.circle.fill" :size="72" class="text-white" />
                <native:text class="text-2xl font-extrabold text-white text-center">NOT VALID</native:text>
                <native:text class="text-lg font-semibold text-red-100 text-center">{{ $reasonLabel }}</native:text>
                @if ($attendeeName !== '')
                    <native:text class="text-base text-red-100 text-center">{{ $attendeeName }}</native:text>
                @endif
            </native:column>
            <native:text class="text-sm text-red-100 text-center w-full">Tap card to continue scanning</native:text>
        </native:pressable>
    </native:column>
@elseif ($phase === 'unavailable')
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-white">
        <native:top-bar title="Scan" subtitle="{{ $contextLabel }}" :back="! $anyEvent" />
        <native:icon name="qrcode" :size="48" class="text-zinc-300" />
        <native:text class="text-lg font-semibold text-zinc-700 text-center">Scanner unavailable</native:text>
        <native:text class="text-sm text-zinc-500 text-center">This build was compiled without the NativePHP Scanner plugin.</native:text>
    </native:column>
@else
    <native:column class="w-full h-full items-center justify-center gap-3 p-6 bg-zinc-900">
        <native:top-bar title="Scanning" subtitle="{{ $contextLabel }}" :back="! $anyEvent" />
        <native:activity-indicator size="large" color="#ffffff" />
        <native:text class="text-lg font-semibold text-white text-center">Ready to scan</native:text>
        <native:text class="text-sm text-zinc-400 text-center">
            @if ($anyEvent)
                Point the camera at any ticket QR code — the event is read from the ticket.
            @else
                Point the camera at a ticket QR code.
            @endif
        </native:text>
        <native:text class="text-sm text-zinc-500">{{ $sessionScans }} scans this session</native:text>
    </native:column>
@endif
