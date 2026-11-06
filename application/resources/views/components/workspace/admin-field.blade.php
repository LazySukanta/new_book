@props(['name', 'label', 'value' => '', 'type' => 'text', 'options' => null, 'multiple' => false, 'required' => false])

@php($fieldId = 'admin-'.\Illuminate\Support\Str::random(8))
<x-ui.field :id="$fieldId" :$label>
    @if ($options !== null)
        <select id="{{ $fieldId }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" class="select w-full border-base-300!" @if ($multiple) multiple size="4" @endif @required($required)>
            @if (!$multiple && !$required)<option value="">Choose an option</option>@endif
            @foreach ($options as $key => $option)
                <option value="{{ $key }}" @selected(in_array((string) $key, array_map('strval', (array) $value), true))>{{ $option }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $fieldId }}" name="{{ $name }}" class="textarea w-full border-base-300!" rows="3" @required($required)>{{ $value }}</textarea>
    @else
        <input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" class="input w-full border-base-300!"
            @if (!in_array($type, ['password', 'file'], true)) value="{{ $value }}" @endif
            @if ($type === 'password') autocomplete="new-password" @endif
            @if ($type === 'file') accept="image/png,image/jpeg" @endif @required($required)>
    @endif
</x-ui.field>
