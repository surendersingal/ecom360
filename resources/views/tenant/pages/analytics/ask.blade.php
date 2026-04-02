@extends('layouts.tenant')
@section('title', 'Ask a Question')
@section('content')
    <div class="e360-page-header">
        <div>
            <h4><i class="bx bx-message-rounded-dots" style="color:var(--analytics);"></i> Ask a Question</h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('tenant.analytics.overview', $tenant->slug) }}">Analytics</a></li>
                <li class="breadcrumb-item active">Ask</li>
            </ol></nav>
        </div>
    </div>
    <div class="d-flex justify-content-end mb-3">
        @include('tenant.pages.analytics._daterange')
    </div>
    <div class="e360-analytics-body">
        {{-- Hero question input --}}
        <div class="card mb-4" data-module="analytics" style="background:linear-gradient(135deg,#1a1f36 0%,#283352 100%);color:#fff;border:0;">
            <div class="card-body p-4">
                <h4 class="text-white mb-1">Ask anything about your data</h4>
                <p class="mb-3" style="opacity:0.7;font-size:13px;">Powered by natural language query engine — ask in plain English</p>
                <div class="d-flex gap-2">
                    <input type="text" id="nlq-input" class="form-control form-control-lg" placeholder="e.g. What was my best selling product last week?" style="border-radius:10px;font-size:15px;">
                    <button id="nlq-ask" class="btn btn-primary btn-lg px-4" style="border-radius:10px;white-space:nowrap;"><i class="bx bx-send me-1"></i> Ask</button>
                </div>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <span class="nlq-suggestion" style="cursor:pointer;background:rgba(255,255,255,0.15);padding:4px 12px;border-radius:20px;font-size:12px;">What's my revenue this month?</span>
                    <span class="nlq-suggestion" style="cursor:pointer;background:rgba(255,255,255,0.15);padding:4px 12px;border-radius:20px;font-size:12px;">Which products have the highest cart abandonment?</span>
                    <span class="nlq-suggestion" style="cursor:pointer;background:rgba(255,255,255,0.15);padding:4px 12px;border-radius:20px;font-size:12px;">Compare traffic sources for the last 30 days</span>
                    <span class="nlq-suggestion" style="cursor:pointer;background:rgba(255,255,255,0.15);padding:4px 12px;border-radius:20px;font-size:12px;">Show me top 5 countries by revenue</span>
                    <span class="nlq-suggestion" style="cursor:pointer;background:rgba(255,255,255,0.15);padding:4px 12px;border-radius:20px;font-size:12px;">What's my conversion rate trend?</span>
                </div>
            </div>
        </div>

        {{-- Answer area --}}
        <div id="nlq-result" class="d-none mb-4">
            <div class="card" data-module="analytics"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0" id="nlq-question-echo"></h5>
                    <span class="badge bg-light text-dark" id="nlq-time"></span>
                </div>
                <div id="nlq-answer-text" style="font-size:14px;line-height:1.7;"></div>
                <div id="nlq-chart" style="height:300px;" class="mt-3 d-none"></div>
                <div id="nlq-table" class="mt-3 d-none table-responsive"></div>
                <div class="mt-3 pt-3 border-top" id="nlq-meta" style="font-size:11px;color:#9CA3AF;"></div>
            </div></div>
        </div>

        {{-- History --}}
        <div class="card" data-module="analytics"><div class="card-body">
            <h5 class="card-title">Recent Questions</h5>
            <div id="nlq-history"><div class="text-muted text-center py-3">Ask your first question above.</div></div>
        </div></div>
    </div>
@endsection
@section('script')
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endsection
@section('script-bottom')
<script>
(function(){
    const API = EcomAPI.baseUrl + '/analytics';
    let history = [];
    let chart;

    // Suggestion click
    $(document).on('click', '.nlq-suggestion', function() {
        $('#nlq-input').val($(this).text());
        $('#nlq-ask').click();
    });

    // Enter key
    $('#nlq-input').on('keydown', function(e) { if (e.key === 'Enter') $('#nlq-ask').click(); });

    // Ask button
    $('#nlq-ask').on('click', function() {
        const q = $('#nlq-input').val().trim();
        if (!q) return;

        $(this).prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin"></i>');
        const start = Date.now();

        $.ajax({
            url: API + '/advanced/ask',
            method: 'POST',
            contentType: 'application/json',
            processData: false,
            data: JSON.stringify({ q: q }),
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') }
        }).then(function(res) {
            const d = res?.data || res || {};
            const elapsed = Date.now() - start;

            $('#nlq-result').removeClass('d-none');
            $('#nlq-question-echo').text('"' + q + '"');
            $('#nlq-time').text((elapsed/1000).toFixed(1) + 's');

            // Text answer — sanitize to prevent XSS
            const answer = d.answer || d.response || d.suggestion || d.text || d.message || 'No answer returned.';
            const safe = $('<div>').text(answer).html().replace(/\n/g, '<br>');
            $('#nlq-answer-text').html(safe);

            // Chart if data
            const chartData = d.chart_data || d.visualization || d.data_points || null;
            if (chartData && Array.isArray(chartData) && chartData.length) {
                $('#nlq-chart').removeClass('d-none');
                if (chart) chart.destroy();
                chart = new ApexCharts(document.querySelector('#nlq-chart'), {
                    chart:{type:'bar',height:300,fontFamily:'Inter',toolbar:{show:false}},
                    series:[{name:'Value',data:chartData.map(c=>c.value||c.count||0)}],
                    xaxis:{categories:chartData.map(c=>c.label||c.name||c.key||'?')},
                    plotOptions:{bar:{borderRadius:4,columnWidth:'55%'}},
                    colors:['#1A56DB'],dataLabels:{enabled:false},
                });
                chart.render();
            } else { $('#nlq-chart').addClass('d-none'); }

            // Table if rows
            const rows = d.table_data || d.results || d.rows || null;
            if (rows && Array.isArray(rows) && rows.length) {
                const keys = Object.keys(rows[0]);
                let t = '<table class="table table-sm mb-0"><thead><tr>' + keys.map(k=>'<th>'+k+'</th>').join('') + '</tr></thead><tbody>';
                rows.forEach(r => { t += '<tr>' + keys.map(k=>'<td style="font-size:12px;">'+((r[k]!=null)?r[k]:'—')+'</td>').join('') + '</tr>'; });
                t += '</tbody></table>';
                $('#nlq-table').removeClass('d-none').html(t);
            } else { $('#nlq-table').addClass('d-none'); }

            // Metadata
            const meta = [];
            if (d.sql || d.query) meta.push('Query: <code>' + (d.sql||d.query) + '</code>');
            if (d.records_analyzed) meta.push('Records: ' + EcomUtils.number(d.records_analyzed));
            if (d.confidence) meta.push('Confidence: ' + (d.confidence*100).toFixed(0) + '%');
            $('#nlq-meta').html(meta.join(' &middot; '));

            // History
            history.unshift({ q, answer: answer.substring(0,100), time: new Date().toLocaleTimeString() });
            renderHistory();

        }).catch(function(err) {
            $('#nlq-result').removeClass('d-none');
            $('#nlq-question-echo').text('"' + q + '"');
            $('#nlq-answer-text').html('<span class="text-danger">Error: ' + (err.responseJSON?.message || 'Failed to process question') + '</span>');
        }).always(function() {
            $('#nlq-ask').prop('disabled', false).html('<i class="bx bx-send me-1"></i> Ask');
        });
    });

    function renderHistory() {
        if (!history.length) return;
        let h = '';
        history.slice(0, 10).forEach((item, idx) => {
            const safeQ = $('<div>').text(item.q).html();
            const safeAnswer = $('<div>').text(item.answer).html();
            h += `<div class="d-flex align-items-start p-2 mb-2 nlq-history-item" data-idx="${idx}" style="background:#f8f9fa;border-radius:6px;cursor:pointer;">
                <i class="bx bx-message-rounded-dots text-muted me-2" style="margin-top:2px;"></i>
                <div class="flex-grow-1">
                    <div style="font-size:12px;font-weight:500;">${safeQ}</div>
                    <div style="font-size:11px;color:#9CA3AF;">${safeAnswer}...</div>
                </div>
                <span style="font-size:10px;color:#9CA3AF;">${item.time}</span>
            </div>`;
        });
        $('#nlq-history').html(h);
    }

    $(document).on('click', '.nlq-history-item', function() {
        const idx = $(this).data('idx');
        if (history[idx]) { $('#nlq-input').val(history[idx].q); $('#nlq-ask').click(); }
    });
})();
</script>
@endsection
