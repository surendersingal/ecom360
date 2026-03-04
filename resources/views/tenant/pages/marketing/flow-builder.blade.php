@extends('layouts.tenant')

@section('title', 'Flow Builder')

@push('styles')
<link href="{{ URL::asset('css/flow-builder.css') }}" rel="stylesheet">
@endpush

@section('content')
    {{-- Header Bar --}}
    <div class="row mb-2">
        <div class="col-12">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                    <a href="{{ route('tenant.marketing.flows', $tenant->slug) }}" class="btn btn-light btn-sm" title="Back to flows">
                        <i class="bx bx-arrow-back"></i>
                    </a>
                    <div>
                        <h5 class="mb-0" id="fb-flow-name">Loading...</h5>
                        <small class="text-muted">Visual Flow Builder</small>
                    </div>
                    <span id="fb-flow-status"></span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="fb-settings-btn" title="Flow Settings">
                        <i class="bx bx-cog me-1"></i>Settings
                    </button>
                    <button type="button" class="btn btn-success btn-sm" id="fb-save-btn" title="Save (Ctrl+S)">
                        <i class="bx bx-save me-1"></i>Save
                    </button>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-primary" id="fb-activate-btn">
                            <i class="bx bx-play me-1"></i>Activate
                        </button>
                        <button type="button" class="btn btn-warning" id="fb-pause-btn">
                            <i class="bx bx-pause me-1"></i>Pause
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Main Builder Area --}}
    <div class="fb-wrapper">
        {{-- Left Palette --}}
        <div class="fb-palette" id="fb-palette">
            <div class="fb-palette-header">
                <i class="bx bx-category me-1"></i> Node Palette
            </div>
            {{-- Populated by JS --}}
        </div>

        {{-- Canvas --}}
        <div class="fb-canvas-wrap" id="fb-canvas-wrap">
            <div class="fb-canvas" id="fb-canvas">
                <svg class="fb-svg" xmlns="http://www.w3.org/2000/svg"></svg>
            </div>

            {{-- Canvas Toolbar --}}
            <div class="fb-toolbar">
                <button type="button" class="btn btn-light btn-sm" id="fb-zoom-fit" title="Fit to screen">
                    <i class="bx bx-fullscreen"></i>
                </button>
                <button type="button" class="btn btn-light btn-sm" id="fb-zoom-reset" title="Reset zoom">
                    <i class="bx bx-reset"></i>
                </button>
                <div class="vr mx-1"></div>
                <button type="button" class="btn btn-light btn-sm" id="fb-clear-all" title="Clear all nodes">
                    <i class="bx bx-trash text-danger"></i>
                </button>
            </div>

            {{-- Zoom Controls --}}
            <div class="fb-zoom-controls">
                <button type="button" class="btn btn-light btn-sm" id="fb-zoom-in" title="Zoom in">
                    <i class="bx bx-plus"></i>
                </button>
                <div class="fb-zoom-label">100%</div>
                <button type="button" class="btn btn-light btn-sm" id="fb-zoom-out" title="Zoom out">
                    <i class="bx bx-minus"></i>
                </button>
            </div>

            {{-- Status Bar --}}
            <div class="fb-status-bar">
                <span id="fb-stat-nodes">0 nodes</span>
                <span id="fb-stat-edges">0 connections</span>
                <span id="fb-stat-dirty" style="color:#34c38f">✓ Saved</span>
            </div>
        </div>

        {{-- Right Properties Panel --}}
        <div class="fb-properties" id="fb-properties">
            <div class="fb-properties-header">
                <span>Properties</span>
                <button type="button" class="btn btn-light btn-sm" id="fb-toggle-props" title="Toggle panel">
                    <i class="bx bx-chevron-right"></i>
                </button>
            </div>
            <div class="fb-properties-body">
                <div class="fb-empty-state">
                    <i class="bx bx-pointer"></i>
                    <p>Drag a node from the left palette<br>onto the canvas to start building</p>
                    <p class="mt-2"><small class="text-muted">
                        <strong>Tip:</strong> Drag from output port (bottom dot) to input port (top dot) to connect nodes
                    </small></p>
                </div>
            </div>
        </div>
    </div>

    {{-- Flow Settings Modal --}}
    <div class="modal fade" id="fbSettingsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Flow Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="fbSettingsForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Flow Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="fb-set-name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="fb-set-desc" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Trigger Type</label>
                            <select class="form-select" name="trigger_type" id="fb-set-trigger">
                                <option value="event">Event</option>
                                <option value="segment_enter">Segment Enter</option>
                                <option value="segment_exit">Segment Exit</option>
                                <option value="date_field">Date Field</option>
                                <option value="manual">Manual</option>
                                <option value="schedule">Schedule</option>
                                <option value="webhook">Webhook</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('script-bottom')
<script src="{{ URL::asset('js/ecom360-api.js') }}"></script>
<script src="{{ URL::asset('js/flow-builder.js') }}"></script>
<script>
$(function() {
    const FLOW_ID = {{ $flowId }};

    // ── Populate palette from node type definitions ──
    const groups = {};
    Object.entries(FB_NODE_TYPES).forEach(([type, def]) => {
        if (!groups[def.group]) groups[def.group] = [];
        groups[def.group].push({ type, ...def });
    });

    const $palette = $('#fb-palette');
    Object.entries(groups).forEach(([groupName, items]) => {
        const $group = $(`<div class="fb-palette-group"><div class="fb-palette-group-title">${groupName}</div></div>`);
        items.forEach(item => {
            $group.append(`
                <div class="fb-palette-item" data-type="${item.type}" draggable="false">
                    <span class="fb-icon fb-color-${item.color}"><i class="${item.icon}"></i></span>
                    <span>${item.label}</span>
                </div>
            `);
        });
        $palette.append($group);
    });

    // ── Initialize builder ──
    const builder = new FlowBuilder({ flowId: FLOW_ID });

    // ── Toolbar buttons ──
    $('#fb-save-btn').on('click', () => builder.save());
    $('#fb-activate-btn').on('click', () => builder.activate());
    $('#fb-pause-btn').on('click', () => builder.pause());
    $('#fb-zoom-in').on('click', () => builder.zoomIn());
    $('#fb-zoom-out').on('click', () => builder.zoomOut());
    $('#fb-zoom-reset').on('click', () => builder.zoomReset());
    $('#fb-zoom-fit').on('click', () => builder.zoomFit());

    // ── Clear all ──
    $('#fb-clear-all').on('click', () => {
        Swal.fire({
            title: 'Clear all nodes?',
            text: 'This will remove all nodes and connections from the canvas.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, clear all',
        }).then(result => {
            if (result.isConfirmed) {
                const ids = [...builder.nodes.keys()];
                ids.forEach(id => builder.removeNode(id));
                toastr.info('Canvas cleared');
            }
        });
    });

    // ── Toggle properties panel ──
    $('#fb-toggle-props').on('click', function() {
        const $props = $('#fb-properties');
        $props.toggleClass('fb-collapsed');
        $(this).find('i').toggleClass('bx-chevron-right bx-chevron-left');
    });

    // ── Flow Settings Modal ──
    $('#fb-settings-btn').on('click', function() {
        if (builder.flowData) {
            $('#fb-set-name').val(builder.flowData.name || '');
            $('#fb-set-desc').val(builder.flowData.description || '');
            $('#fb-set-trigger').val(builder.flowData.trigger_type || 'event');
        }
        $('#fbSettingsModal').modal('show');
    });

    $('#fbSettingsForm').on('submit', function(e) {
        e.preventDefault();
        const data = {
            name: $('#fb-set-name').val(),
            description: $('#fb-set-desc').val(),
            trigger_type: $('#fb-set-trigger').val(),
        };
        EcomAPI.put('/marketing/flows/' + FLOW_ID, data).then(() => {
            builder.flowData = Object.assign(builder.flowData || {}, data);
            $('#fb-flow-name').text(data.name);
            $('#fbSettingsModal').modal('hide');
            toastr.success('Settings updated');
        }).catch(err => toastr.error(err.message));
    });

    // ── Warn before leaving with unsaved changes ──
    $(window).on('beforeunload', function(e) {
        if (builder.isDirty) {
            e.preventDefault();
            return 'You have unsaved changes.';
        }
    });
});
</script>
@endsection
