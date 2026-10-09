@extends('errors.layout')

@section('code')
403
@endsection
@section('title')
You don't have access to this page
@endsection
@section('message')
{{ $exception?->getMessage() ?: 'Ask a manager if you need this page.' }}
@endsection
