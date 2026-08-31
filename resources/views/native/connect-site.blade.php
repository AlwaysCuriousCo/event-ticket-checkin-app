<native:top-bar title="Connect a Site" display-mode="large" :back="$canCancel" />

<native:scroll-view class="w-full h-full bg-white">
    <native:column class="w-full p-6 gap-4">
        <native:text class="text-base text-zinc-600">
            Connect the WordPress site running Event Tickets and the TEC Scanner companion plugin.
        </native:text>

        @unless ($scannerUnavailable)
            <native:pressable ref="scan-pair-btn" a11y-label="Scan pairing code"
                              class="w-full flex-row items-center justify-center gap-2 p-4 rounded-xl bg-blue-600"
                              @tap="scanPairingCode">
                <native:icon name="qrcode" :size="22" class="text-white" />
                <native:text class="text-base font-semibold text-white">Scan pairing code</native:text>
            </native:pressable>
            <native:text class="text-xs text-zinc-500 text-center">
                In wp-admin go to Tickets → Scanner App and scan the QR shown there.
            </native:text>

            <native:row class="w-full items-center gap-3">
                <native:divider class="flex-1" />
                <native:text class="text-xs text-zinc-400">or enter details manually</native:text>
                <native:divider class="flex-1" />
            </native:row>
        @endunless

        <native:outlined-text-input native:model="siteUrl" label="Site address"
                                    placeholder="https://example.com" keyboard="url" :disabled="$busy" />

        <native:outlined-text-input native:model="username" label="WordPress username"
                                    placeholder="username" :disabled="$busy" />

        <native:column class="w-full gap-2">
            <native:outlined-text-input native:model="password" label="Application password"
                                        placeholder="xxxx xxxx xxxx xxxx" secure :disabled="$busy" />
            <native:text class="text-xs text-zinc-500">
                Create one in wp-admin under Users → Profile → Application Passwords. It is stored only in this device's secure storage.
            </native:text>
        </native:column>

        @if ($error !== '')
            <native:column class="w-full p-3 rounded-lg bg-red-50 border border-red-200">
                <native:text class="text-sm text-red-700">{{ $error }}</native:text>
            </native:column>
        @endif

        <native:pressable ref="connect-btn" a11y-label="Connect site"
                          class="w-full flex-row items-center justify-center gap-2 p-4 rounded-xl {{ $busy ? 'bg-zinc-300' : 'bg-zinc-800' }}"
                          @tap="connect">
            @if ($busy)
                <native:activity-indicator size="small" color="#ffffff" />
                <native:text class="text-base font-semibold text-white">Connecting…</native:text>
            @else
                <native:text class="text-base font-semibold text-white">Connect</native:text>
            @endif
        </native:pressable>
    </native:column>
</native:scroll-view>
