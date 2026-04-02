@extends('layouts.tenant')
@section('title', 'Search Analytics')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4>Search Analytics</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">AI Search</li>
                    <li class="breadcrumb-item active">Analytics</li>
                </ol>
            </nav>
        </div>
        <div class="header-actions">
            <select id="dateRange" class="form-select form-select-sm" style="width:auto;" onchange="location.href='?days='+this.value">
                @foreach([7=>'Last 7 days',14=>'Last 14 days',30=>'Last 30 days',60=>'Last 60 days',90=>'Last 90 days'] as $d => $l)
                <option value="{{ $d }}" {{ ($days ?? 30) == $d ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Searches</span>
                    <span class="kpi-icon" style="background:rgba(8,145,178,0.1);color:var(--search);"><i class="bx bx-search"></i></span>
                </div>
                <div class="kpi-value" data-countup="{{ $analytics['total_searches'] ?? 0 }}">{{ number_format($analytics['total_searches'] ?? 0) }}</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">CTR</span>
                    <span class="kpi-icon revenue"><i class="bx bx-pointer"></i></span>
                </div>
                <div class="kpi-value" style="color:{{ ($analytics['click_through_rate'] ?? 0) > 20 ? 'var(--success)' : 'var(--warning)' }}">{{ $analytics['click_through_rate'] ?? 0 }}%</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Conversion</span>
                    <span class="kpi-icon orders"><i class="bx bx-cart"></i></span>
                </div>
                <div class="kpi-value" style="color:{{ ($analytics['conversion_rate'] ?? 0) > 5 ? 'var(--success)' : 'var(--warning)' }}">{{ $analytics['conversion_rate'] ?? 0 }}%</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Zero Results</span>
                    <span class="kpi-icon conversion"><i class="bx bx-x-circle"></i></span>
                </div>
                <div class="kpi-value" style="color:{{ ($analytics['zero_result_rate'] ?? 0) < 10 ? 'var(--success)' : 'var(--danger)' }}">{{ $analytics['zero_result_rate'] ?? 0 }}%</div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Avg Latency</span>
                    <span class="kpi-icon visitors"><i class="bx bx-time"></i></span>
                </div>
                <div class="kpi-value"><span class="mono">{{ $analytics['avg_response_time'] ?? 0 }}</span><span style="font-size:14px;color:var(--neutral-400);">ms</span></div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Trending</span>
                    <span class="kpi-icon" style="background:var(--surface-2);color:var(--neutral-600);"><i class="bx bx-trending-up"></i></span>
                </div>
                <div class="kpi-value">{{ count($trending ?? []) }}</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Top Queries --}}
        <div class="col-xl-6">
            <div class="card" data-module="search">
                <div class="card-body">
                    <h5 class="card-title">Top Search Queries</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-nowrap mb-0">
                            <thead><tr><th style="width:30px">#</th><th>Query</th><th class="text-end">Searches</th><th class="text-end">CTR</th></tr></thead>
                            <tbody>
                                @forelse(($analytics['top_queries'] ?? []) as $idx => $q)
                                <tr>
                                    <td style="color:var(--neutral-400);font-weight:600;">{{ $idx + 1 }}</td>
                                    <td><code style="background:var(--surface-2);padding:2px 8px;border-radius:4px;">{{ $q['query'] ?? '' }}</code></td>
                                    <td class="text-end mono" style="font-weight:500;">{{ $q['count'] ?? 0 }}</td>
                                    <td class="text-end">
                                        <span class="e360-badge {{ ($q['ctr'] ?? 0) > 20 ? 'e360-badge-active' : (($q['ctr'] ?? 0) > 5 ? 'e360-badge-pending' : 'e360-badge-error') }}">
                                            {{ number_format($q['ctr'] ?? 0, 1) }}%
                                        </span>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4"><div class="e360-empty-state" style="padding:24px 0;"><div class="empty-icon">🔍</div><h3>No search data</h3><p>Search queries will appear here.</p></div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Zero Result Queries --}}
        <div class="col-xl-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Zero-Result Queries</h5>
                    <p style="font-size:13px;color:var(--neutral-400);margin-bottom:16px;">Queries that returned no results — add synonyms or check your catalog.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-nowrap mb-0">
                            <thead><tr><th style="width:30px">#</th><th>Query</th><th class="text-end">Count</th><th class="text-end">Action</th></tr></thead>
                            <tbody>
                                @forelse(($analytics['zero_result_queries'] ?? []) as $idx => $q)
                                <tr>
                                    <td style="color:var(--neutral-400);font-weight:600;">{{ $idx + 1 }}</td>
                                    <td><code style="background:var(--danger-bg);color:var(--danger);padding:2px 8px;border-radius:4px;">{{ $q['query'] ?? '' }}</code></td>
                                    <td class="text-end mono">{{ $q['count'] ?? 0 }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('tenant.search.settings', $tenant->slug) }}" class="btn btn-sm btn-light">Add Synonym</a>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4" style="text-align:center;color:var(--neutral-400);padding:24px;">No zero-result queries — great!</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Trending Searches --}}
    <div class="card mt-3">
        <div class="card-body">
            <h5 class="card-title">Trending Searches <span style="font-weight:400;color:var(--neutral-400);font-size:13px;">Last {{ $analytics['trending_window'] ?? 7 }} days</span></h5>
            <div class="d-flex flex-wrap gap-2">
                @forelse($trending ?? [] as $t)
                    <span class="e360-filter-pill active" style="cursor:default;">
                        {{ $t['query'] ?? '' }}
                        <span class="mono" style="font-size:11px;font-weight:700;margin-left:6px;color:var(--primary-500);">{{ $t['search_count'] ?? 0 }}</span>
                    </span>
                @empty
                    <span style="color:var(--neutral-400);font-size:14px;">No trending searches yet.</span>
                @endforelse
            </div>
        </div>
    </div>
@endsection
