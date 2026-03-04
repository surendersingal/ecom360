@extends('layouts.tenant')

@section('title', 'Funnel Analytics')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Funnel Analytics</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item active">Funnels</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Overall Conversion</p>
                            <h4 class="mb-0">{{ number_format($funnel['overall_conversion_pct'] ?? 0, 2) }}%</h4>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-success mini-stat-icon">
                                <span class="avatar-title bg-success"><i class="bx bx-check-circle font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="card mini-stats-wid">
                <div class="card-body">
                    <div class="d-flex">
                        <div class="flex-grow-1">
                            <p class="text-muted fw-medium">Period</p>
                            <h6 class="mb-0">{{ $funnel['date_from'] ?? '-' }} to {{ $funnel['date_to'] ?? '-' }}</h6>
                        </div>
                        <div class="flex-shrink-0 align-self-center">
                            <div class="avatar-sm rounded-circle bg-info mini-stat-icon">
                                <span class="avatar-title bg-info"><i class="bx bx-calendar font-size-24"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">E-commerce Conversion Funnel</h4>
                    <div id="funnel-chart" style="height: 400px;"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Funnel Stage Details</h4>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light"><tr><th>Stage</th><th class="text-end">Users</th><th class="text-end">Drop-off</th></tr></thead>
                            <tbody>
                                @forelse($funnel['stages'] ?? [] as $stage)
                                <tr>
                                    <td>{{ ucwords(str_replace('_', ' ', $stage['stage'])) }}</td>
                                    <td class="text-end">{{ number_format($stage['unique_sessions'] ?? 0) }}</td>
                                    <td class="text-end">
                                        @if(($stage['drop_off_pct'] ?? 0) > 0)
                                        <span class="text-danger">-{{ number_format($stage['drop_off_pct'], 1) }}%</span>
                                        @else
                                        <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="3" class="text-center text-muted">No funnel data</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
<script>
    @php
        $fLabels = collect($funnel['stages'] ?? [])->map(fn($s) => ucwords(str_replace('_', ' ', $s['stage'])))->toArray();
        $fData   = collect($funnel['stages'] ?? [])->pluck('unique_sessions')->toArray();
    @endphp
    new ApexCharts(document.querySelector("#funnel-chart"), {
        chart: { type: 'bar', height: 400, toolbar: { show: false } },
        plotOptions: { bar: { horizontal: true, barHeight: '50%', distributed: true } },
        series: [{ name: 'Users', data: @json($fData) }],
        xaxis: { categories: @json($fLabels) },
        colors: ['#556ee6', '#34c38f', '#f1b44c', '#f46a6a'],
        dataLabels: { enabled: true },
        legend: { show: false }
    }).render();
</script>
@endsection