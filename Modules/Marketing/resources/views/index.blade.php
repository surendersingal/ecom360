@extends('marketing::layouts.master')

@section('content')
    <h1>Hello World</h1>

    <p>Module: {!! config('marketing.name') !!}</p>
@endsection
