@extends('reports.pdf.layout')

@section('content')
    @include('reports.partials.trial-balance', ['report' => $report])

    <h2>{{ __('reports.reconciliation.title') }}</h2>
    @include('reports.partials.reconciliation', ['checks' => $checks])
@endsection
