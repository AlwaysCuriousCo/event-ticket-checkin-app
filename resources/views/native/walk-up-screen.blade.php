<native:scroll-view class="w-full h-full bg-white">
    <native:column class="w-full p-4 gap-4">

        @if ($phase === 'offline')
            <native:column class="w-full items-center p-8 gap-3">
                <native:icon name="wifi.slash" :size="40" class="text-zinc-400" />
                <native:text class="text-base text-zinc-600 text-center">Walk-up registration needs a connection — the ticket is created on the site so it exists everywhere.</native:text>
                <native:pressable ref="retry-btn" class="p-3" @tap="loadTickets">
                    <native:text class="text-sm font-semibold text-blue-600">Try again</native:text>
                </native:pressable>
            </native:column>

        @elseif ($phase === 'done')
            <native:column class="w-full items-center gap-2 p-6 rounded-xl bg-green-50 border border-green-200">
                <native:icon name="checkmark.circle.fill" :size="48" class="text-green-600" />
                <native:text class="text-xl font-bold text-green-900">{{ $registered['name'] }}</native:text>
                <native:text class="text-sm text-green-800">{{ $registered['ticket'] }} · registered and checked in</native:text>
            </native:column>
            <native:pressable ref="another-btn" class="w-full items-center p-4 rounded-xl bg-blue-600"
                              @tap="registerAnother">
                <native:text class="text-base font-semibold text-white">Register another</native:text>
            </native:pressable>
            <native:pressable ref="done-btn" class="w-full items-center p-3" @tap="back">
                <native:text class="text-sm font-semibold text-blue-600">Done</native:text>
            </native:pressable>

        @else
            <native:text class="text-sm text-zinc-500">{{ $eventTitle }}</native:text>

            @if ($error !== '')
                <native:column class="w-full p-3 rounded-lg bg-red-50 border border-red-200">
                    <native:text class="text-sm text-red-800">{{ $error }}</native:text>
                </native:column>
            @endif

            <native:column class="w-full gap-2">
                <native:text class="text-sm font-semibold text-zinc-700">Ticket</native:text>
                @foreach ($tickets as $ticket)
                    @if ($ticket['id'] === $ticketId)
                        <native:pressable native:key="ticket-{{ $ticket['id'] }}" ref="ticket-{{ $ticket['id'] }}"
                                          a11y-label="{{ $ticket['name'] }}, selected"
                                          class="w-full flex-row items-center justify-between p-3 rounded-xl bg-blue-50 border border-blue-500"
                                          @tap="selectTicket({{ $ticket['id'] }})">
                            <native:text class="text-base font-semibold text-blue-900">{{ $ticket['name'] }}</native:text>
                            <native:text class="text-sm text-blue-700">{{ $ticket['price'] > 0 ? '$'.number_format($ticket['price'], 2) : 'Free' }}</native:text>
                        </native:pressable>
                    @else
                        <native:pressable native:key="ticket-{{ $ticket['id'] }}" ref="ticket-{{ $ticket['id'] }}"
                                          a11y-label="{{ $ticket['name'] }}"
                                          class="w-full flex-row items-center justify-between p-3 rounded-xl bg-zinc-50 border border-zinc-200"
                                          @tap="selectTicket({{ $ticket['id'] }})">
                            <native:text class="text-base text-zinc-800">{{ $ticket['name'] }}</native:text>
                            <native:text class="text-sm text-zinc-500">{{ $ticket['price'] > 0 ? '$'.number_format($ticket['price'], 2) : 'Free' }}</native:text>
                        </native:pressable>
                    @endif
                @endforeach
            </native:column>

            <native:outlined-text-input native:model="name" label="Attendee name" placeholder="Full name" />
            <native:outlined-text-input native:model="email" label="Email (optional)" placeholder="name@example.com" />

            <native:column class="w-full gap-2">
                <native:text class="text-sm font-semibold text-zinc-700">Payment</native:text>
                <native:row class="w-full gap-2">
                    @if ($payment === 'cash')
                        <native:pressable ref="pay-cash" a11y-label="Cash, selected"
                                          class="flex-1 items-center p-3 rounded-xl bg-blue-50 border border-blue-500"
                                          @tap="setPayment('cash')">
                            <native:text class="text-base font-semibold text-blue-900">Cash</native:text>
                        </native:pressable>
                        <native:pressable ref="pay-comp" a11y-label="Comp"
                                          class="flex-1 items-center p-3 rounded-xl bg-zinc-50 border border-zinc-200"
                                          @tap="setPayment('comp')">
                            <native:text class="text-base text-zinc-800">Comp</native:text>
                        </native:pressable>
                    @else
                        <native:pressable ref="pay-cash" a11y-label="Cash"
                                          class="flex-1 items-center p-3 rounded-xl bg-zinc-50 border border-zinc-200"
                                          @tap="setPayment('cash')">
                            <native:text class="text-base text-zinc-800">Cash</native:text>
                        </native:pressable>
                        <native:pressable ref="pay-comp" a11y-label="Comp, selected"
                                          class="flex-1 items-center p-3 rounded-xl bg-blue-50 border border-blue-500"
                                          @tap="setPayment('comp')">
                            <native:text class="text-base font-semibold text-blue-900">Comp</native:text>
                        </native:pressable>
                    @endif
                </native:row>
            </native:column>

            <native:pressable ref="register-btn" a11y-label="Register and check in"
                              class="w-full items-center p-4 rounded-xl bg-blue-600"
                              @tap="register">
                @if ($busy)
                    <native:activity-indicator size="small" color="#ffffff" />
                @else
                    <native:text class="text-base font-semibold text-white">Register &amp; check in</native:text>
                @endif
            </native:pressable>

            <native:pressable ref="card-btn" a11y-label="Pay by card on the site"
                              class="w-full items-center p-3"
                              @tap="payByCard">
                <native:text class="text-sm font-semibold text-blue-600">Pay by card — buy on the site instead</native:text>
            </native:pressable>
        @endif

    </native:column>
</native:scroll-view>
