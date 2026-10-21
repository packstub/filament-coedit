@php
    $members = $getState() ?? [];
@endphp

<div class="fi-coedit-column">
    @foreach (array_slice($members, 0, 3) as $member)
        <span
            @class(['fi-coedit-avatar', 'fi-coedit-avatar-editing' => $member['mode'] === 'editing'])
            style="--coedit-color: {{ $member['color'] }}"
            title="{{ $member['name'] }} · {{ __('filament-coedit::coedit.status.'.$member['mode']) }}"
        >
            @if ($member['avatar'])
                <img src="{{ $member['avatar'] }}" alt="{{ $member['name'] }}" />
            @else
                {{ collect(preg_split('/\s+/', $member['name']))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}
            @endif
        </span>
    @endforeach

    @if (count($members) > 3)
        <span class="fi-coedit-avatar fi-coedit-more" title="{{ collect(array_slice($members, 3))->pluck('name')->implode(', ') }}">+{{ count($members) - 3 }}</span>
    @endif
</div>
