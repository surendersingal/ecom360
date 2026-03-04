@extends('layouts.tenant')

@section('title', 'Ask a Question')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Ask a Question</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">AI &amp; Insights</li>
                        <li class="breadcrumb-item active">Ask a Question</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Query Input --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-start">
                        <div class="avatar-sm me-3 flex-shrink-0">
                            <span class="avatar-title rounded-circle bg-soft-primary text-primary font-size-20">
                                <i class="bx bx-chat"></i>
                            </span>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="mb-2">Ask anything about your data</h5>
                            <p class="text-muted mb-3">Query your analytics data using natural language — no SQL required.</p>
                            <form id="nlq-form" class="d-flex gap-2">
                                <input type="text" class="form-control form-control-lg" name="q" id="nlq-input" placeholder="e.g. What was the best selling product last week?" autocomplete="off" required>
                                <button type="submit" class="btn btn-primary btn-lg flex-shrink-0"><i class="bx bx-send me-1"></i> Ask</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Suggested Questions --}}
    <div class="row" id="suggestions-row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bx bx-bulb text-warning me-1"></i> Try these questions</h5>
                    <div id="suggestions-container" class="d-flex flex-wrap gap-2">
                        <span class="text-muted"><i class="bx bx-loader-alt bx-spin"></i> Loading suggestions...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Results Area --}}
    <div class="row" id="result-area" style="display:none;">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0"><i class="bx bx-message-square-detail me-1"></i> Answer</h5>
                        <button class="btn btn-sm btn-soft-secondary" id="btn-clear-result"><i class="bx bx-x me-1"></i> Clear</button>
                    </div>

                    {{-- Query echo --}}
                    <div class="alert alert-light mb-3" id="query-echo"></div>

                    {{-- Narrative answer --}}
                    <div id="result-narrative" class="mb-4"></div>

                    {{-- Data table --}}
                    <div id="result-table-wrap" style="display:none;">
                        <h6 class="text-muted mb-2">Data</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered" id="result-data-table">
                                <thead class="table-light" id="result-thead"></thead>
                                <tbody id="result-tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Raw JSON fallback --}}
                    <div id="result-raw" style="display:none;">
                        <h6 class="text-muted mb-2">Raw Result</h6>
                        <pre class="bg-light p-3 rounded" id="result-json" style="max-height:400px;overflow:auto;"></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Loading --}}
    <div class="row" id="nlq-loading" style="display:none;">
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <p class="text-muted">Analyzing your question...</p>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API = '/analytics/advanced/ask';

    // Load suggestions
    EcomAPI.get(API + '/suggest').then(json => {
        const suggestions = json.data || [];
        const $c = $('#suggestions-container').empty();
        (Array.isArray(suggestions) ? suggestions : [suggestions]).forEach(s => {
            const text = typeof s === 'string' ? s : (s.question || s.text || s.label || JSON.stringify(s));
            $c.append(`<button type="button" class="btn btn-outline-primary btn-sm suggestion-btn">${text}</button>`);
        });
        if (!$c.children().length) $c.html('<span class="text-muted">No suggestions available</span>');
    }).catch(() => {
        $('#suggestions-container').html('<span class="text-muted">Could not load suggestions</span>');
    });

    // Click suggestion
    $(document).on('click', '.suggestion-btn', function() {
        $('#nlq-input').val($(this).text());
        $('#nlq-form').submit();
    });

    // Submit query
    $('#nlq-form').on('submit', function(e) {
        e.preventDefault();
        const q = $('#nlq-input').val().trim();
        if (!q) return;

        $('#result-area').hide();
        $('#nlq-loading').show();

        EcomAPI.get(API + '?q=' + encodeURIComponent(q)).then(json => {
            const data = json.data || {};
            $('#query-echo').html('<i class="bx bx-user me-1"></i> <strong>' + $('<span>').text(q).html() + '</strong>');

            // Narrative
            const narrative = data.narrative || data.answer || data.text || data.summary || '';
            if (narrative) {
                $('#result-narrative').html('<div class="p-3 bg-soft-success rounded">' + narrative + '</div>').show();
            } else {
                $('#result-narrative').hide();
            }

            // Data table
            const tableData = data.data || data.results || data.rows || [];
            if (Array.isArray(tableData) && tableData.length && typeof tableData[0] === 'object') {
                const cols = Object.keys(tableData[0]);
                let thead = '<tr>' + cols.map(c => `<th>${c.replace(/_/g,' ').replace(/\b\w/g,l=>l.toUpperCase())}</th>`).join('') + '</tr>';
                let tbody = tableData.map(row => '<tr>' + cols.map(c => `<td>${row[c] ?? '—'}</td>`).join('') + '</tr>').join('');
                $('#result-thead').html(thead);
                $('#result-tbody').html(tbody);
                $('#result-table-wrap').show();
                $('#result-raw').hide();
            } else {
                $('#result-table-wrap').hide();
                // Show raw JSON
                const raw = data.data || data.results || data;
                if (typeof raw === 'object' && Object.keys(raw).length > 0) {
                    $('#result-json').text(JSON.stringify(raw, null, 2));
                    $('#result-raw').show();
                } else {
                    $('#result-raw').hide();
                }
            }

            $('#nlq-loading').hide();
            $('#result-area').show();
        }).catch(err => {
            $('#nlq-loading').hide();
            toastr.error(err.message || 'Query failed');
        });
    });

    $('#btn-clear-result').on('click', function() {
        $('#result-area').hide();
        $('#nlq-input').val('').focus();
    });
});
</script>
@endsection
