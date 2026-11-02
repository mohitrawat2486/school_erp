@props(['name', 'label', 'value' => null, 'type' => 'text', 'required' => false, 'maxlength' => 255, 'hint' => null, 'useOld' => true])
@php
    $parts = explode('.', $name);
    $inputName = array_shift($parts).implode('', array_map(fn ($part) => '['.$part.']', $parts));
    $inputId = str_replace('.', '-', $name);
    $inputValue = $useOld ? old($name, $value) : $value;
    $inputValue = is_scalar($inputValue) ? $inputValue : '';
@endphp
<div class="student-field">
    <label for="{{ $inputId }}">{{ $label }} @if($required || $attributes->has('data-guardian-required') || $attributes->has('data-contact-required'))<span class="student-required" aria-hidden="true">*</span>@endif</label>
    <input id="{{ $inputId }}" name="{{ $inputName }}" type="{{ $type }}" value="{{ $inputValue }}" maxlength="{{ $maxlength }}" {{ $attributes }} @required($required) @if($errors->has($name)) aria-invalid="true" @endif aria-describedby="{{ $inputId }}-feedback">
    <div id="{{ $inputId }}-feedback">@error($name)<p class="student-field-error">{{ $message }}</p>@else @if($hint)<p class="student-field-hint">{{ $hint }}</p>@endif @enderror</div>
</div>
