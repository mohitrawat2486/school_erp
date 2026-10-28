@props(['href','icon','label','active'=>false])
<a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" class="sidebar-link {{ $active ? 'is-active' : '' }}" @if($active) aria-current="page" @endif><x-icon :name="$icon" /><span>{{ $label }}</span></a>
