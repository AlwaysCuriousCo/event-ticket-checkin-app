<native:top-bar title="Attendees" subtitle="{{ $checkedInCount }} / {{ $totalCount }} checked in" back />

<native:column class="w-full h-full bg-white">
    <native:column class="w-full p-4 gap-3">
        <native:outlined-text-input native:model.debounce.300ms="query" label="Search"
                                    placeholder="Name or email" />

        <native:row class="w-full gap-2">
            @foreach (['all' => 'All', 'in' => 'Checked in', 'out' => 'Not checked in'] as $key => $label)
                <native:pressable native:key="filter-{{ $key }}" ref="filter-{{ $key }}"
                                  class="flex-row px-4 py-2 rounded-full {{ $filter === $key ? 'bg-blue-600' : 'bg-zinc-100' }}"
                                  @tap="setFilter('{{ $key }}')">
                    <native:text class="text-sm font-semibold {{ $filter === $key ? 'text-white' : 'text-zinc-700' }}">{{ $label }}</native:text>
                </native:pressable>
            @endforeach
        </native:row>
    </native:column>

    <native:scroll-view class="flex-1 w-full">
        <native:column class="w-full px-4 gap-0">
            @forelse ($rows as $row)
                <native:list-item native:key="attendee-{{ $row['id'] }}" ref="attendee-{{ $row['id'] }}"
                                  headline="{{ $row['name'] }}"
                                  supporting="{{ $row['ticket'] }} · {{ $row['email'] }}"
                                  trailingText="{{ $row['checked_in'] ? 'IN ✓' : '' }}"
                                  trailingTextColor="#15803d"
                                  @tap="open({{ $row['id'] }})"
                                  @if ($row['eligible'])
                                  :leading-actions="[[
                                      'method' => 'swipeCheckin('.$row['id'].')',
                                      'label' => 'Check in',
                                      'icon' => 'checkmark.circle.fill',
                                      'tint' => '#16a34a',
                                  ]]"
                                  @endif
                />
            @empty
                <native:column class="w-full items-center p-8">
                    <native:text class="text-base text-zinc-500 text-center">No attendees match.</native:text>
                </native:column>
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
