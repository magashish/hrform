@php($id = 'f_'.$field->field_key)
<div class="field">
    @switch($field->type)
        @case('checkbox')
            <label class="check"><input type="hidden" name="{{ $field->field_key }}" value="0"><input id="{{ $id }}" type="checkbox" name="{{ $field->field_key }}" value="1" @checked($value)> {{ $field->label }}@if ($field->required) <span class="req">(required)</span>@endif</label>
            @break
        @case('attestation')
            <p class="attestation">{{ $field->configValue('statement') }}</p>
            <label class="check"><input type="hidden" name="{{ $field->field_key }}" value="0"><input id="{{ $id }}" type="checkbox" name="{{ $field->field_key }}" value="1" @checked($value)> {{ $field->label }}</label>
            @break
        @case('radio')
            <fieldset>
                <legend>{{ $field->label }}@if ($field->required) <span class="req">(required)</span>@endif</legend>
                @foreach ($field->configValue('options', []) as $i => $option)
                    <label class="check"><input type="radio" name="{{ $field->field_key }}" value="{{ $option }}" @checked($value === $option)> {{ $option }}</label>
                @endforeach
            </fieldset>
            @break
        @default
            <label for="{{ $id }}">{{ $field->label }}@if ($field->required) <span class="req">(required)</span>@endif</label>
            @if ($field->type === 'textarea')
                <textarea id="{{ $id }}" name="{{ $field->field_key }}" rows="4" maxlength="{{ $field->configValue('max_length', 2000) }}">{{ $value }}</textarea>
            @elseif ($field->type === 'select')
                <select id="{{ $id }}" name="{{ $field->field_key }}">
                    <option value="">Select…</option>
                    @foreach ($field->configValue('options', []) as $option)
                        <option value="{{ $option }}" @selected($value === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            @else
                <input id="{{ $id }}" name="{{ $field->field_key }}" value="{{ $value }}"
                    type="{{ match ($field->type) { 'email' => 'email', 'phone' => 'tel', 'date' => 'date', 'number' => 'number', 'ssn' => 'password', default => 'text' } }}"
                    @if ($field->type === 'ssn') autocomplete="off" inputmode="numeric" maxlength="11" placeholder="123-45-6789" @endif
                    @if ($field->type === 'number') step="{{ $field->configValue('integer_only') ? '1' : 'any' }}" @endif
                    @if ($field->type === 'text') maxlength="{{ $field->configValue('max_length', 255) }}" @endif
                    @if ($field->type === 'email') autocomplete="email" @endif>
            @endif
    @endswitch
    @if ($field->help_text)<p class="hint">{{ $field->help_text }}</p>@endif
    @error($field->field_key)<p class="field-error">{{ $message }}</p>@enderror
</div>
