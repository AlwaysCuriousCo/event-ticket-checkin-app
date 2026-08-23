@if ($phase === 'green')
    <native:pressable ref="result-green" class="w-full h-full items-center justify-center gap-3 p-8 bg-green-600" @tap="dismiss">
        <native:icon name="check-circle" :size="96" class="text-white" />
        <native:text class="text-4xl font-extrabold text-white text-center">{{ $attendeeName }}</native:text>
        <native:text class="text-xl font-semibold text-green-100 text-center">{{ $ticketName }}</native:text>
        <native:text class="text-lg text-green-100">Checked in ✓</native:text>
        @if ($eventLabel !== '')
            <native:text class="text-base text-green-100 text-center">{{ $eventLabel }}</native:text>
        @endif
    </native:pressable>
@elseif ($phase === 'amber')
    <native:pressable ref="result-amber" class="w-full h-full items-center justify-center gap-3 p-8 bg-amber-500" @tap="dismiss">
        <native:icon name="alert-triangle" :size="96" class="text-white" />
        <native:text class="text-4xl font-extrabold text-white text-center">{{ $attendeeName }}</native:text>
        <native:text class="text-xl font-semibold text-white text-center">{{ $checkedInInfo }}</native:text>
        @if ($eventLabel !== '')
            <native:text class="text-base text-amber-100 text-center">{{ $eventLabel }}</native:text>
        @endif
        <native:text class="text-base text-amber-100">Tap to continue scanning</native:text>
    </native:pressable>
@elseif ($phase === 'red')
    <native:pressable ref="result-red" class="w-full h-full items-center justify-center gap-3 p-8 bg-red-600" @tap="dismiss">
        <native:icon name="x-circle" :size="96" class="text-white" />
        <native:text class="text-4xl font-extrabold text-white text-center">NOT VALID</native:text>
        <native:text class="text-xl font-semibold text-red-100 text-center">{{ $reasonLabel }}</native:text>
        @if ($attendeeName !== '')
            <native:text class="text-base text-red-200">{{ $attendeeName }}</native:text>
        @endif
        <native:text class="text-base text-red-200">Tap to continue scanning</native:text>
    </native:pressable>
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
