@extends('layouts.tenant')
@section('title', 'Visitor Log')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-list-ul" style="color:var(--analytics);"></i> Visitor Log</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Visitor Log</li>
            </ol></nav>
        </div>
    </div>

    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        <div class="card" data-module="analytics">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">Recent Visitor Sessions</h5>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="visitor-search" placeholder="Search by page, country..." style="width:200px;">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0" id="visitor-log-table">
                        <thead><tr>
                            <th style="width:50px;">#</th>
                            <th>Session</th>
                            <th>Location</th>
                            <th class="text-center">Pages</th>
                            <th class="text-end">Actions</th>
                            <th>Entry Page</th>
                            <th>Source</th>
                            <th>Last Seen</th>
                        </tr></thead>
                        <tbody id="visitor-log-body">
                            <tr><td colspan="8" class="text-center py-4"><i class="bx bx-loader-alt bx-spin"></i> Loading sessions...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted" style="font-size:12px;" id="log-count">—</div>
                    <div>
                        <button class="btn btn-sm btn-light" id="log-prev" disabled>← Prev</button>
                        <button class="btn btn-sm btn-light" id="log-next">Next →</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
(function(){
    const API = EcomAPI.baseUrl + '/analytics';
    const range = new URLSearchParams(location.search).get('date_range') || '30d';
    let page = 1;

    function loadLog() {
        $.get(API + '/recent-events', { date_range: range }).then(function(res) {
            const data = res?.data || res || {};
            const events = data.events || data.recent_sessions || data.data || [];
            const total = data.total || events.length;
            $('#log-count').text(`Showing ${events.length} of ${EcomUtils.number(total)} events`);

            if (!events.length) {
                $('#visitor-log-body').html('<tr><td colspan="8" class="text-center text-muted py-4">No sessions found</td></tr>');
                return;
            }

            // Group by session_id to show session-level rows
            const sessionMap = {};
            events.forEach(e => {
                const sid = e.session_id || e.visitor_id || 'unknown';
                if (!sessionMap[sid]) sessionMap[sid] = { events: [], session_id: sid, country: '', referrer: '', first_at: e.created_at, last_at: e.created_at };
                sessionMap[sid].events.push(e);
                if (e.metadata?.country) sessionMap[sid].country = e.metadata.country;
                if (e.metadata?.referrer) sessionMap[sid].referrer = e.metadata.referrer;
                sessionMap[sid].last_at = e.created_at;
            });
            const sessions = Object.values(sessionMap);

            let html = '';
            sessions.forEach((s, i) => {
                const num = i + 1;
                const short = s.session_id.substring(0,12) + '…';
                const loc = s.country || '—';
                const pageEvents = s.events.filter(e => e.event_type === 'page_view').length;
                const totalActions = s.events.length;
                const firstUrl = (s.events.find(e => e.metadata?.url) || {}).metadata?.url || '';
                const ref = s.referrer || (firstUrl ? 'Page view' : 'Direct');
                const time = s.last_at || s.first_at || '';
                const badge = '<span class="badge bg-soft-info" style="font-size:10px;">' + s.events[0]?.event_type + '</span>';

                const entryPage = firstUrl ? firstUrl.replace(/^https?:\/\/[^\/]+/, '') || '/' : '—';
                html += `<tr>
                    <td class="text-muted" style="font-size:11px;">${num}</td>
                    <td><code style="font-size:11px;">${short}</code> ${badge}</td>
                    <td style="font-size:12px;"><i class="bx bx-map-pin" style="font-size:11px;color:var(--neutral-400);"></i> ${loc}</td>
                    <td class="mono text-center" style="font-size:12px;">${pageEvents}</td>
                    <td class="mono text-end" style="font-size:12px;">${totalActions}</td>
                    <td style="font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${firstUrl}">${entryPage}</td>
                    <td style="font-size:12px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${ref}</td>
                    <td style="font-size:11px;color:var(--neutral-500);">${time ? new Date(time).toLocaleString() : '—'}</td>
                </tr>`;
            });
            $('#visitor-log-body').html(html);
            $('#log-prev').prop('disabled', true);
            $('#log-next').prop('disabled', events.length < 50);
        }).catch(function() {
            $('#visitor-log-body').html('<tr><td colspan="8" class="text-center text-muted py-4">Failed to load visitor log</td></tr>');
        });
    }

    loadLog();

    // Client-side search filter
    $('#visitor-search').on('input', function() {
        const q = $(this).val().toLowerCase();
        $('#visitor-log-body tr').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(q));
        });
    });
})();
</script>
@endsection
