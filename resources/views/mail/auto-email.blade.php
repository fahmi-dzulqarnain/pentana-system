@extends('mail.layout')

{{-- RichEditor HTML, rendered unescaped. Authored by admins (trusted) and sanitized by Filament. --}}
@section('content')
    {!! $body !!}
@endsection
