@extends('layouts.tenant')

@section('title', 'Audience Sync')

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0 font-size-18">Audience Sync</h4>
                <div class="page-title-right">
                    <ol class="breadcrumb m-0">
                        <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                        <li class="breadcrumb-item">Marketing</li>
                        <li class="breadcrumb-item active">Audience Sync</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    {{-- Destination Cards --}}
    <div class="row" id="dest-cards">
        @php
            $destinations = [
                ['key' => 'google_ads', 'name' => 'Google Ads', 'icon' => 'bx bxl-google', 'color' => 'danger', 'desc' => 'Sync Customer Match audiences to Google Ads campaigns.'],
                ['key' => 'meta_ads', 'name' => 'Meta Ads', 'icon' => 'bx bxl-meta', 'color' => 'primary', 'desc' => 'Sync Custom Audiences to Facebook & Instagram Ads.'],
                ['key' => 'tiktok_ads', 'name' => 'TikTok Ads', 'icon' => 'bx bxl-tiktok', 'color' => 'dark', 'desc' => 'Sync audiences to TikTok Ads Manager.'],
                ['key' => 'klaviyo', 'name' => 'Klaviyo', 'icon' => 'bx bx-envelope', 'color' => 'success', 'desc' => 'Sync segments to Klaviyo lists for email marketing.'],
            ];
        @endphp

        @foreach($destinations as $dest)
        <div class="col-xl-3 col-md-6">
            <div class="card">
                <div class="card-body text-center py-4">
                    <div class="avatar-md mx-auto mb-3">
                        <span class="avatar-title rounded-circle bg-soft-{{ $dest['color'] }} text-{{ $dest['color'] }} font-size-24">
                            <i class="{{ $dest['icon'] }}"></i>
                        </span>
                    </div>
                    <h5 class="mb-1">{{ $dest['name'] }}</h5>
                    <p class="text-muted font-size-13 mb-3">{{ $dest['desc'] }}</p>
                    <span class="badge bg-soft-secondary text-secondary dest-status mb-2 d-block" data-dest="{{ $dest['key'] }}">Not connected</span>
                    <button class="btn btn-soft-primary btn-sm btn-sync" data-dest="{{ $dest['key'] }}" data-name="{{ $dest['name'] }}"><i class="bx bx-sync me-1"></i> Sync Now</button>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Sync Now Modal --}}
    <div class="modal fade" id="syncModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="syncModalTitle">Sync Audience</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="syncForm">
                    <div class="modal-body">
                        <input type="hidden" name="destination">
                        <div class="mb-3">
                            <label class="form-label">Select Segment <span class="text-danger">*</span></label>
                            <select class="form-select" name="segment_id" id="segment-select" required></select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">API Key <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="credentials[api_key]" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Account ID</label>
                            <input type="text" class="form-control" name="credentials[account_id]">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save"><i class="bx bx-sync me-1"></i> Start Sync</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Segments & Sync History --}}
    <div class="row">
        <div class="col-md-5">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="card-title mb-0">Audience Segments</h4>
                        <button class="btn btn-sm btn-soft-primary" id="btn-refresh-segments"><i class="bx bx-refresh"></i></button>
                    </div>
                    <div id="segments-list">
                        <div class="text-center text-muted py-3"><i class="bx bx-loader-alt bx-spin"></i> Loading segments...</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">Supported Destinations</h4>
                    <div id="destinations-table-wrap">
                        <table id="destinations-table" class="table table-centered table-nowrap mb-0 w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Destination</th>
                                    <th>Supported Fields</th>
                                    <th>Rate Limit</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script>
$(function() {
    const API_ADV = '/analytics/advanced';

    // Load segments
    function loadSegments() {
        $('#segments-list').html('<div class="text-center text-muted py-3"><i class="bx bx-loader-alt bx-spin"></i> Loading...</div>');
        EcomAPI.get(`${API_ADV}/audience/segments`).then(json => {
            const segs = json.data || [];
            const $sel = $('#segment-select').empty();
            if (!segs.length) {
                $('#segments-list').html('<div class="text-center text-muted py-3">No segments found.</div>');
                return;
            }
            let html = '<div class="list-group">';
            segs.forEach(s => {
                const id = s._id || s.id || s.segment_id;
                $sel.append(`<option value="${id}">${s.name} (${s.count || 0})</option>`);
                html += `<div class="list-group-item d-flex justify-content-between align-items-center">
                    <div><strong>${s.name}</strong><br><small class="text-muted">${s.description || s.type || ''}</small></div>
                    <span class="badge bg-primary rounded-pill">${EcomUtils.number(s.count || 0)}</span>
                </div>`;
            });
            html += '</div>';
            $('#segments-list').html(html);
        }).catch(err => {
            $('#segments-list').html(`<div class="text-center text-danger py-3">${err.message || 'Failed to load segments'}</div>`);
        });
    }

    // Load destinations
    function loadDestinations() {
        EcomAPI.get(`${API_ADV}/audience/destinations`).then(json => {
            const dests = json.data || [];
            const $tbody = $('#destinations-table tbody').empty();
            if (Array.isArray(dests)) {
                dests.forEach(d => {
                    $tbody.append(`<tr>
                        <td><strong>${d.name || d.key || d}</strong></td>
                        <td>${(d.supported_fields || []).join(', ') || '—'}</td>
                        <td>${d.rate_limit || '—'}</td>
                    </tr>`);
                });
            } else if (typeof dests === 'object') {
                Object.entries(dests).forEach(([key, val]) => {
                    const name = typeof val === 'object' ? (val.name || key) : key;
                    const fields = typeof val === 'object' ? (val.supported_fields || []).join(', ') : '';
                    const rate = typeof val === 'object' ? (val.rate_limit || '') : '';
                    $tbody.append(`<tr><td><strong>${name}</strong></td><td>${fields || '—'}</td><td>${rate || '—'}</td></tr>`);
                });
            }
            if (!$tbody.children().length) {
                $tbody.append('<tr><td colspan="3" class="text-center text-muted py-3">No destinations configured</td></tr>');
            }
        }).catch(() => {});
    }

    loadSegments();
    loadDestinations();

    $('#btn-refresh-segments').on('click', loadSegments);

    // Sync button
    $('.btn-sync').on('click', function() {
        const dest = $(this).data('dest'), name = $(this).data('name');
        EcomCRUD.resetForm('syncModal');
        $('#syncModalTitle').text('Sync to ' + name);
        $('#syncForm [name="destination"]').val(dest);
        $('#syncModal').modal('show');
    });

    // Sync form submit
    $('#syncForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('.btn-save').prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i> Syncing...');
        const raw = EcomCRUD.formData('syncForm');
        const payload = {
            segment_id: parseInt(raw.segment_id),
            destination: raw.destination,
            credentials: {},
        };
        Object.keys(raw).forEach(k => {
            if (k.startsWith('credentials[')) {
                payload.credentials[k.replace('credentials[','').replace(']','')] = raw[k];
            }
        });
        EcomAPI.post(`${API_ADV}/audience/sync`, payload).then(json => {
            toastr.success('Sync started successfully!');
            $('#syncModal').modal('hide');
        }).catch(err => {
            toastr.error(err.message || 'Sync failed');
        }).finally(() => $btn.prop('disabled', false).html('<i class="bx bx-sync me-1"></i> Start Sync'));
    });
});
</script>
@endsection
