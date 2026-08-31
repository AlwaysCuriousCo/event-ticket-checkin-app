<native:top-bar title="Profile" subtitle="{{ $deviceIdentity }}" display-mode="large" />

<native:scroll-view class="w-full h-full bg-white">
    <native:column class="w-full p-4 gap-4">
        @if ($notice !== '')
            <native:column class="w-full p-3 rounded-lg bg-blue-50 border border-blue-200">
                <native:text class="text-sm text-blue-800">{{ $notice }}</native:text>
            </native:column>
        @endif

        <native:column class="w-full gap-2">
            <native:text class="text-sm font-semibold text-zinc-500">CONNECTED SITES</native:text>

            @foreach ($sites as $site)
                <native:column native:key="site-{{ $site['id'] }}"
                               class="w-full gap-2 p-4 rounded-xl {{ $site['active'] ? 'bg-blue-50 border border-blue-300' : 'bg-zinc-50 border border-zinc-200' }}">
                    <native:pressable ref="site-{{ $site['id'] }}" a11y-label="Use {{ $site['name'] }}"
                                      class="w-full flex-row items-center justify-between gap-3"
                                      @tap="switchTo({{ $site['id'] }})">
                        <native:column class="flex-1 gap-1">
                            <native:text class="text-base font-semibold text-zinc-900">{{ $site['name'] }}</native:text>
                            <native:text class="text-sm text-zinc-500">{{ $site['host'] }} · {{ $site['username'] }}</native:text>
                            @if ($site['pending'] > 0)
                                <native:text class="text-sm font-semibold text-amber-600">{{ $site['pending'] }} check-ins waiting to sync</native:text>
                            @endif
                        </native:column>
                        @if ($site['active'])
                            <native:icon name="checkmark.circle.fill" :size="20" class="text-blue-600" />
                        @endif
                    </native:pressable>

                    <native:pressable ref="remove-site-{{ $site['id'] }}" a11y-label="Disconnect {{ $site['name'] }}"
                                      class="p-1" @tap="confirmRemove({{ $site['id'] }})">
                        <native:text class="text-sm font-semibold text-red-600">Disconnect</native:text>
                    </native:pressable>
                </native:column>
            @endforeach

            <native:pressable ref="add-site" a11y-label="Connect another site"
                              class="w-full flex-row items-center justify-center gap-2 p-4 rounded-xl bg-zinc-50 border border-zinc-200"
                              @tap="addSite">
                <native:icon name="plus" :size="18" class="text-blue-600" />
                <native:text class="text-base font-semibold text-blue-600">Connect another site</native:text>
            </native:pressable>
        </native:column>

        <native:divider />

        <native:column class="w-full gap-2">
            <native:text class="text-sm font-semibold text-zinc-500">THIS DEVICE</native:text>

            <native:outlined-text-input native:model="deviceName" label="Device name"
                                        placeholder="Front Gate iPhone" />
            <native:text class="text-xs text-zinc-500">
                Sent with every check-in so the site shows which door it came from. Currently recorded as “{{ $deviceIdentity }}”.
            </native:text>

            <native:pressable ref="save-device-name" a11y-label="Save device name"
                              class="w-full flex-row items-center justify-center p-4 rounded-xl bg-blue-600"
                              @tap="saveDeviceName">
                <native:text class="text-base font-semibold text-white">Save device name</native:text>
            </native:pressable>
        </native:column>
    </native:column>
</native:scroll-view>
