@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <h1>Settings</h1>
    <p class="muted">Changes are recorded in the audit log and require password confirmation. Technical settings (storage, mail server, scanner, automatic purge) are set by your technical operator in the server configuration.</p>

    <form method="POST" action="{{ route('hr.settings.update') }}" novalidate>
        @csrf @method('PUT')
        @foreach ($definitions as $group => $items)
            <section class="card">
                <h2>{{ $group }}</h2>
                @foreach ($items as $key => $def)
                    <div class="field">
                        @if ($key === 'hr_notification_events')
                            <fieldset>
                                <legend>{{ $def['label'] }}</legend>
                                @foreach ($availableEvents as $eventKey => $eventLabel)
                                    <label class="check"><input type="checkbox" name="hr_notification_events[]" value="{{ $eventKey }}" @checked(in_array($eventKey, (array) $values[$key], true))> {{ $eventLabel }}</label>
                                @endforeach
                            </fieldset>
                        @elseif (str_ends_with($key, '_intro'))
                            <label for="{{ $key }}">{{ $def['label'] }}</label>
                            <textarea id="{{ $key }}" name="{{ $key }}" rows="3">{{ old($key, $values[$key]) }}</textarea>
                        @else
                            <label for="{{ $key }}">{{ $def['label'] }}</label>
                            <input id="{{ $key }}" type="text" name="{{ $key }}" value="{{ old($key, $values[$key]) }}">
                        @endif
                        @isset($def['help'])<p class="hint">{{ $def['help'] }}</p>@endisset
                    </div>
                @endforeach
            </section>
        @endforeach
        <div class="actions"><button type="submit" class="button">Save settings</button></div>
    </form>
@endsection
