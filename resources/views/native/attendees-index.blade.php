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

    {{-- SwiftUI only attaches swipe actions to rows of a real List —
         list-items loose in a scroll-view swallow the gesture (and the
         edge swipe pops the screen instead). --}}
    <native:list plain class="flex-1 w-full">
        @forelse ($rows as $row)
            {{-- Inline @if inside a tag applies to no attributes (both
                 branches render), so each state is its own element. --}}
            @if ($row['checked_in'])
                <native:list-item native:key="attendee-{{ $row['id'] }}" ref="attendee-{{ $row['id'] }}"
                                  headline="{{ $row['name'] }}"
                                  supporting="{{ $row['ticket'] }} · {{ $row['email'] }}"
                                  trailingIcon="ticket" trailingIconColor="#16a34a"
                                  a11y-label="{{ $row['name'] }}, checked in"
                                  @tap="open({{ $row['id'] }})" />
            @elseif ($row['eligible'])
                <native:list-item native:key="attendee-{{ $row['id'] }}" ref="attendee-{{ $row['id'] }}"
                                  headline="{{ $row['name'] }}"
                                  supporting="{{ $row['ticket'] }} · {{ $row['email'] }}"
                                  @tap="open({{ $row['id'] }})"
                                  :leading-actions="[[
                                      'method' => 'swipeCheckin('.$row['id'].')',
                                      'label' => 'Check in',
                                      'icon' => 'checkmark.circle.fill',
                                      'tint' => '#16a34a',
                                      'full_swipe' => true,
                                  ]]" />
            @elseif ($row['status_glyph'])
                <native:list-item native:key="attendee-{{ $row['id'] }}" ref="attendee-{{ $row['id'] }}"
                                  headline="{{ $row['name'] }}"
                                  supporting="{{ $row['ticket'] }} · {{ $row['email'] }}"
                                  trailingIcon="{{ $row['status_glyph'][0] }}" trailingIconColor="{{ $row['status_glyph'][1] }}"
                                  a11y-label="{{ $row['name'] }}, {{ $row['status_glyph'][2] }}"
                                  @tap="open({{ $row['id'] }})" />
            @else
                <native:list-item native:key="attendee-{{ $row['id'] }}" ref="attendee-{{ $row['id'] }}"
                                  headline="{{ $row['name'] }}"
                                  supporting="{{ $row['ticket'] }} · {{ $row['email'] }}"
                                  @tap="open({{ $row['id'] }})" />
            @endif
        @empty
            <native:column class="w-full items-center p-8">
                <native:text class="text-base text-zinc-500 text-center">No attendees match.</native:text>
            </native:column>
        @endforelse
    </native:list>
</native:column>
