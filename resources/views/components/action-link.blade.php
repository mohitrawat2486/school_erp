@props(['href', 'label'=>'View', 'icon'=>'eye', 'primary'=>false])
<a href="{{ $href }}" {{ $attributes->class(['table-action action-link', 'action-link--primary'=>$primary])->merge(['title'=>$label]) }}><x-icon :name="$icon" /><span>{{ $label }}</span></a>
