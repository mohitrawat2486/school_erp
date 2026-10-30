@props(['values'=>[], 'prefix'=>''])
@foreach($values as $key=>$value)
    @php($name = $prefix === '' ? $key : $prefix.'['.$key.']')
    @if(is_array($value))
        <x-context-fields :values="$value" :prefix="$name" />
    @elseif($value !== null)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endif
@endforeach
