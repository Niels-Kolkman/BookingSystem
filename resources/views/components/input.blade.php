@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null])

@php
    // Never echo a password back into the form.
    $current = $type === 'password' ? null : old($name, $value);
    $errorId = $name.'-error';
    $hintId = $name.'-hint';
    $describedBy = trim(($hint ? $hintId : '').' '.($errors->has($name) ? $errorId : ''));
@endphp

<div class="mb-4">
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($current !== null) value="{{ $current }}" @endif
        @if ($errors->has($name)) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge(['class' => 'block w-full rounded border border-gray-300 px-3 py-2 focus:border-gray-500 focus:outline-none']) }}
    >

    @if ($hint)
        <p id="{{ $hintId }}" class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $errorId }}" class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
