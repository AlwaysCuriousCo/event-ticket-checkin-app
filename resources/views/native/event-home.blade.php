<native:top-bar title="{{ $title }}" subtitle="{{ $date }}" back display-mode="large" />

<native:column class="w-full h-full p-4 gap-4 bg-white">
    @if ($error !== '')
        <native:column class="w-full p-3 rounded-lg bg-red-50 border border-red-200">
            <native:text class="text-sm text-red-700">{{ $error }}</native:text>
        </native:column>
    @endif

    <native:row class="w-full gap-3">
        <native:column class="flex-1 items-center gap-2 p-4 rounded-xl bg-zinc-50 border border-zinc-200">
            <native:text class="text-3xl font-extrabold text-zinc-900">{{ $checkedIn }}</native:text>
            <native:text class="text-sm text-zinc-500">checked in</native:text>
            <native:progress-bar :value="$total > 0 ? $checkedIn / $total : 0" color="#16a34a" track-color="#e4e4e7" class="w-full" />
        </native:column>
        <native:column class="flex-1 items-center p-4 rounded-xl bg-zinc-50 border border-zinc-200">
            <native:text class="text-3xl font-extrabold text-zinc-900">{{ $total }}</native:text>
            <native:text class="text-sm text-zinc-500">attendees</native:text>
        </native:column>
    </native:row>

    <native:pressable ref="scan-btn" a11y-label="Start scanning"
                      class="w-full flex-row items-center justify-center gap-3 p-6 rounded-2xl bg-blue-600"
                      @tap="startScanning">
        <native:icon name="qrcode" :size="28" class="text-white" />
        <native:text class="text-xl font-bold text-white">Start Scanning</native:text>
    </native:pressable>

    @if ($canRegisterWalkUp)
        <native:pressable ref="walkup-btn" a11y-label="Register a walk-up attendee"
                          class="w-full items-center p-4 rounded-xl bg-zinc-50 border border-zinc-200"
                          @tap="registerWalkUp">
            <native:text class="text-base font-semibold text-zinc-900">Register walk-up</native:text>
        </native:pressable>
    @endif

    <native:pressable ref="attendees-btn" class="w-full items-center p-4 rounded-xl bg-zinc-50 border border-zinc-200"
                      @tap="openAttendees">
        <native:text class="text-base font-semibold text-zinc-900">Attendees</native:text>
    </native:pressable>

    <native:pressable ref="stats-btn" class="w-full items-center p-4 rounded-xl bg-zinc-50 border border-zinc-200"
                      @tap="openStats">
        <native:text class="text-base font-semibold text-zinc-900">Stats</native:text>
    </native:pressable>

    <native:spacer />

    <native:column class="w-full items-center gap-1">
        @if ($syncing)
            <native:row class="items-center gap-2">
                <native:activity-indicator size="small" />
                <native:text class="text-sm text-zinc-500">Syncing attendees…</native:text>
            </native:row>
        @else
            <native:text class="text-sm text-zinc-500">Last synced {{ $lastSynced }}</native:text>
        @endif
        @if ($pendingOps > 0)
            <native:text class="text-sm font-semibold text-amber-600">{{ $pendingOps }} check-ins waiting to sync</native:text>
        @endif
        <native:pressable ref="sync-btn" class="p-2" @tap="syncNow">
            <native:text class="text-sm font-semibold text-blue-600">Sync now</native:text>
        </native:pressable>
    </native:column>
</native:column>
