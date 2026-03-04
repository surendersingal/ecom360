@extends('analytics::layouts.master')

@section('content')
    <h1>Hello World</h1>

    <p>Module: {!! config('analytics.name') !!}</p>
@endsection
