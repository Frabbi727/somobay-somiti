@extends('reports.pdf.layout')

@section('content')
    @include('reports.partials.ledger', ['report' => $report])
@endsection
