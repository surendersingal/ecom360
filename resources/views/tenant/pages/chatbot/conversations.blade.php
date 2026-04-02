@extends('layouts.tenant')
@section('title', 'Chatbot Conversations')

@section('content')
    <div class="e360-page-header">
        <div>
            <h4>Conversations</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Chatbot</li>
                    <li class="breadcrumb-item active">Conversations</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- KPI Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Total Conversations</span>
                    <span class="kpi-icon" style="background:rgba(124,58,237,0.1);color:var(--chatbot);"><i class="bx bx-chat"></i></span>
                </div>
                <div class="kpi-value" data-countup="{{ $stats['total'] ?? 0 }}">{{ number_format($stats['total'] ?? 0) }}</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Active Now</span>
                    <span class="kpi-icon revenue"><i class="bx bx-pulse"></i></span>
                </div>
                <div class="kpi-value" style="color:var(--success);">{{ number_format($stats['active'] ?? 0) }}</div>
                <div class="kpi-trend up"><span class="e360-live-badge" style="font-size:11px;padding:2px 8px;"><span class="live-dot"></span> Live</span></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Resolved</span>
                    <span class="kpi-icon orders"><i class="bx bx-check-circle"></i></span>
                </div>
                <div class="kpi-value" data-countup="{{ $stats['resolved'] ?? 0 }}">{{ number_format($stats['resolved'] ?? 0) }}</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card">
                <div class="kpi-header">
                    <span class="kpi-label">Escalated</span>
                    <span class="kpi-icon conversion"><i class="bx bx-error"></i></span>
                </div>
                <div class="kpi-value" style="color:var(--danger);">{{ number_format($stats['escalated'] ?? 0) }}</div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card mb-3">
        <div class="card-body" style="padding:16px 24px !important;">
            <form method="GET" class="d-flex flex-wrap gap-3 align-items-end">
                <div style="min-width:140px;">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="resolved" {{ request('status') == 'resolved' ? 'selected' : '' }}>Resolved</option>
                        <option value="escalated" {{ request('status') == 'escalated' ? 'selected' : '' }}>Escalated</option>
                        <option value="abandoned" {{ request('status') == 'abandoned' ? 'selected' : '' }}>Abandoned</option>
                    </select>
                </div>
                <div style="min-width:160px;">
                    <label class="form-label">Intent</label>
                    <select name="intent" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach(['greeting','product_inquiry','order_tracking','checkout_help','return_request','coupon_inquiry','size_help','shipping_inquiry','add_to_cart','general'] as $i)
                        <option value="{{ $i }}" {{ request('intent') == $i ? 'selected' : '' }}>{{ ucwords(str_replace('_', ' ', $i)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="min-width:120px;">
                    <label class="form-label">Channel</label>
                    <select name="channel" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="widget" {{ request('channel') == 'widget' ? 'selected' : '' }}>Widget</option>
                        <option value="email" {{ request('channel') == 'email' ? 'selected' : '' }}>Email</option>
                        <option value="whatsapp" {{ request('channel') == 'whatsapp' ? 'selected' : '' }}>WhatsApp</option>
                    </select>
                </div>
                <div style="flex:1;min-width:180px;">
                    <label class="form-label">Email</label>
                    <input type="text" name="email" class="form-control form-control-sm" value="{{ request('email') }}" placeholder="Search email...">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="{{ route('tenant.chatbot.conversations', $tenant->slug) }}" class="btn btn-light btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Conversations Table --}}
    <div class="card" data-module="chatbot">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-nowrap mb-0">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Intent</th>
                            <th>Channel</th>
                            <th class="text-center">Msgs</th>
                            <th>Status</th>
                            <th>Satisfaction</th>
                            <th>Started</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($conversations as $conv)
                        <tr>
                            <td>
                                <div style="font-weight:500;color:var(--neutral-800);">{{ $conv['email'] ?? 'Anonymous' }}</div>
                            </td>
                            <td><span class="e360-badge" style="background:var(--primary-100);color:var(--primary-700);">{{ ucwords(str_replace('_', ' ', $conv['intent'] ?? 'general')) }}</span></td>
                            <td><span class="e360-badge" style="background:var(--info-bg);color:var(--info);">{{ ucfirst($conv['channel'] ?? 'widget') }}</span></td>
                            <td class="text-center mono" style="font-weight:600;">{{ $conv['message_count'] ?? 0 }}</td>
                            <td>
                                @php
                                    $statusBadge = match($conv['status'] ?? 'active') {
                                        'active' => 'e360-badge-active',
                                        'resolved' => 'e360-badge-info',
                                        'escalated' => 'e360-badge-error',
                                        'abandoned' => 'e360-badge-pending',
                                        default => '',
                                    };
                                @endphp
                                <span class="e360-badge {{ $statusBadge }}">{{ ucfirst($conv['status'] ?? 'active') }}</span>
                            </td>
                            <td>
                                @if(isset($conv['satisfaction_score']))
                                    <div style="display:flex;gap:2px;">
                                        @for($s = 1; $s <= 5; $s++)
                                            <i class="bx bxs-star" style="font-size:14px;color:{{ $s <= $conv['satisfaction_score'] ? 'var(--warning)' : 'var(--neutral-200)' }};"></i>
                                        @endfor
                                    </div>
                                @else
                                    <span style="color:var(--neutral-300);">—</span>
                                @endif
                            </td>
                            <td style="font-size:13px;color:var(--neutral-500);white-space:nowrap;">{{ !empty($conv['started_at']) ? \Carbon\Carbon::parse($conv['started_at'])->diffForHumans() : '—' }}</td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-light" onclick="viewConversation('{{ $conv['id'] }}')" title="View thread">
                                    <i class="bx bx-message-detail" style="font-size:16px;"></i>
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8">
                                <div class="e360-empty-state" style="padding:32px 0;">
                                    <div class="empty-icon">💬</div>
                                    <h3>No conversations yet</h3>
                                    <p>Conversations will appear here once customers start chatting.</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

{{-- Conversation Modal --}}
<div class="modal fade" id="conversationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bx bx-message-detail me-2" style="color:var(--chatbot);"></i> Conversation Thread</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: 480px; overflow-y: auto; background: var(--surface-1);" id="conversationBody">
                <div class="text-center py-4"><div class="skeleton-loading" style="width:200px;height:20px;margin:0 auto;"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger btn-sm" id="resolveBtn" onclick="resolveConversation()">
                    <i class="bx bx-check-circle me-1"></i> Mark Resolved
                </button>
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('script')
<script>
    let currentConversationId = null;

    // Countup
    document.querySelectorAll('[data-countup]').forEach(function(el) {
        var target = parseInt(el.dataset.countup, 10);
        if (target > 0 && window.ecom360CountUp) window.ecom360CountUp(el, target, { duration: 1200 });
    });

    function viewConversation(id) {
        currentConversationId = id;
        const modal = new bootstrap.Modal(document.getElementById('conversationModal'));
        modal.show();

        document.getElementById('conversationBody').innerHTML = '<div class="text-center py-4"><div class="skeleton-loading" style="width:200px;height:20px;margin:0 auto;"></div></div>';

        fetch(`/api/v1/chatbot/history/${id}`, {
            headers: { 'X-Ecom360-Key': '{{ $tenant->api_key ?? "" }}', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success && !d.data?.messages) {
                document.getElementById('conversationBody').innerHTML = '<p style="color:var(--danger);text-align:center;padding:20px;">Could not load messages.</p>';
                return;
            }
            const messages = d.data?.messages || d.messages || [];
            let html = '<div style="display:flex;flex-direction:column;gap:12px;padding:8px 0;">';
            messages.forEach(m => {
                const isUser = m.role === 'user';
                const isSystem = m.role === 'system';
                const align = isUser ? 'flex-end' : 'flex-start';
                const bg = isUser ? 'var(--primary-500)' : isSystem ? 'var(--surface-2)' : 'var(--surface-0)';
                const color = isUser ? '#fff' : 'var(--neutral-800)';
                const borderColor = isUser ? 'transparent' : 'var(--border)';
                const label = isUser ? 'Customer' : isSystem ? 'System' : 'Bot';
                const labelColor = isUser ? 'var(--primary-300)' : 'var(--neutral-400)';
                html += `<div style="display:flex;flex-direction:column;align-items:${align};">
                    <div style="font-size:11px;color:${labelColor};margin-bottom:4px;font-weight:500;">${label} &middot; ${m.created_at ? new Date(m.created_at).toLocaleTimeString() : ''}</div>
                    <div style="max-width:75%;padding:12px 16px;border-radius:12px;background:${bg};color:${color};border:1px solid ${borderColor};font-size:14px;line-height:1.5;${isUser ? 'border-bottom-right-radius:4px;' : 'border-bottom-left-radius:4px;'}">${m.content?.replace(/\n/g, '<br>') || ''}</div>
                </div>`;
            });
            html += '</div>';
            document.getElementById('conversationBody').innerHTML = html || '<p style="color:var(--neutral-400);text-align:center;">No messages.</p>';
        })
        .catch(() => {
            document.getElementById('conversationBody').innerHTML = '<p style="color:var(--danger);text-align:center;">Error loading conversation.</p>';
        });
    }

    function resolveConversation() {
        if (!currentConversationId) return;
        fetch(`/api/v1/chatbot/resolve/${currentConversationId}`, {
            method: 'POST',
            headers: { 'X-Ecom360-Key': '{{ $tenant->api_key ?? "" }}', 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ satisfaction_score: 5 }),
        })
        .then(r => r.json())
        .then(() => { location.reload(); });
    }
</script>
@endsection
