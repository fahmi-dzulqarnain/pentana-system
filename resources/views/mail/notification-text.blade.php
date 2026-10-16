{!! $greeting ?: __('Hello!') !!}

@foreach ($introLines as $line)
{!! $line !!}

@endforeach
@isset($actionText)
{!! $actionText !!}: {!! $actionUrl !!}

@endisset
@foreach ($outroLines as $line)
{!! $line !!}

@endforeach
{!! __('The :app team', ['app' => config('app.name')]) !!}
