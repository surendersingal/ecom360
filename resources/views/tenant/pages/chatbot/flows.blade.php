@extends('layouts.tenant')
@section('title', 'Chatbot Flow Builder')

@section('content')
<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">Chatbot Flow Builder & Intent Manager</h4>
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="{{ route('tenant.chatbot.settings', $tenant->slug) }}">Chatbot Settings</a></li>
                            <li class="breadcrumb-item active">Flow Builder</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div id="saveAlert" class="alert d-none" role="alert"></div>

        {{-- ── Feature Overview ── --}}
        <div class="row mb-3">
            <div class="col-12">
                <div class="card bg-soft-primary">
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center">
                            <i class="bx bx-bot font-size-24 text-primary me-3"></i>
                            <div>
                                <h5 class="mb-1">Chatbot Capabilities</h5>
                                <p class="mb-0 text-muted">Your chatbot has <strong>10 built-in intents</strong>, <strong>5 advanced services</strong>, <strong>5 proactive support features</strong>, and <strong>custom conversation flows</strong> — all controllable from this panel.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Tab Navigation ── --}}
        <ul class="nav nav-tabs nav-tabs-custom mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#tab_intents" role="tab">
                    <i class="bx bx-brain me-1"></i> Intents & Handlers
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab_flows" role="tab">
                    <i class="bx bx-git-branch me-1"></i> Custom Flows
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab_triggers" role="tab">
                    <i class="bx bx-target-lock me-1"></i> Triggers & Actions
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab_advanced" role="tab">
                    <i class="bx bx-rocket me-1"></i> Advanced Services
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab_api" role="tab">
                    <i class="bx bx-code-alt me-1"></i> API Reference
                </a>
            </li>
        </ul>

        <div class="tab-content">

            {{-- ═══════════════════════════════════════════════════════════════
                 TAB 1: INTENTS & HANDLERS
                 ═══════════════════════════════════════════════════════════════ --}}
            <div class="tab-pane active" id="tab_intents" role="tabpanel">
                <div class="row">
                    {{-- Intent List --}}
                    <div class="col-xl-7">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3"><i class="bx bx-brain text-warning me-2"></i>Intent Detection Engine</h4>
                                <p class="text-muted mb-3">Each intent detects user messages via keyword matching, pattern recognition, and context-aware boosting. Toggle intents on/off — disabled intents fall back to the <code>general</code> handler.</p>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Intent</th>
                                                <th>Confidence</th>
                                                <th>Handler</th>
                                                <th class="text-center">Status</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @php
                                            $intents = [
                                                ['greeting', 0.95, 'handleGreeting', 'Responds with welcome message + quick-reply menu', 'hi, hello, hey, good morning, sup, howdy'],
                                                ['farewell', 0.95, 'handleFarewell', 'Resolves conversation, sends farewell template', 'bye, goodbye, thanks, done, nothing else'],
                                                ['product_inquiry', 0.85, 'handleProductInquiry', 'Searches catalog via AI Search, returns product cards', '100+ brand names + category words + custom keywords'],
                                                ['order_tracking', 0.90, 'handleOrderTracking', 'Extracts order ID, queries OrderTrackingService', 'where is my order, track, delivery status, order #XXX'],
                                                ['checkout_help', 0.88, 'handleCheckoutHelp', 'Quick replies for payment/coupon/shipping/address issues', "can't checkout, payment failed, place order"],
                                                ['return_request', 0.88, 'handleReturnRequest', 'Return policy info with configurable days + template', 'return, refund, exchange, wrong item, damaged'],
                                                ['coupon_inquiry', 0.88, 'handleCouponInquiry', 'Looks up active coupons, offers to apply', 'coupon, promo code, discount, deal, sale'],
                                                ['size_help', 0.87, 'handleSizeHelp', 'Size guide + interactive sizing assistance', 'size, sizing, measurement, fit, size chart'],
                                                ['shipping_inquiry', 0.87, 'handleShippingInquiry', 'Configurable shipping options template', 'shipping, delivery time, shipping cost, express'],
                                                ['add_to_cart', 0.85, 'handleAddToCart', 'Emits add_to_cart action with product_id payload', 'add to cart, buy, purchase, I want this, order this'],
                                                ['general', 0.30, 'handleGeneral', 'Fallback: shows capability menu with quick replies', '(matches when no other intent detected)'],
                                            ];
                                            @endphp
                                            @foreach($intents as [$name, $confidence, $handler, $desc, $patterns])
                                            <tr>
                                                <td>
                                                    <strong class="text-primary">{{ $name }}</strong>
                                                    <br><small class="text-muted">{{ $desc }}</small>
                                                </td>
                                                <td><span class="badge bg-{{ $confidence >= 0.90 ? 'success' : ($confidence >= 0.85 ? 'info' : 'secondary') }}">{{ $confidence }}</span></td>
                                                <td><code class="small">{{ $handler }}()</code></td>
                                                <td class="text-center">
                                                    @if($name !== 'general')
                                                    <div class="form-check form-switch d-inline-block">
                                                        <input type="checkbox" class="form-check-input intent-toggle" data-intent="{{ $name }}"
                                                            {{ ($settings['intent_' . $name] ?? '1') == '1' ? 'checked' : '' }}>
                                                    </div>
                                                    @else
                                                    <span class="badge bg-secondary">Always On</span>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    <button class="btn btn-sm btn-outline-info" onclick="showPatterns('{{ $name }}', `{{ $patterns }}`)">
                                                        <i class="bx bx-search-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Intent Detail Panel --}}
                    <div class="col-xl-5">
                        <div class="card" id="intentDetailCard">
                            <div class="card-body">
                                <h4 class="card-title mb-3"><i class="bx bx-detail text-info me-2"></i>Intent Detail</h4>
                                <div id="intentDetail">
                                    <p class="text-muted">Click the <i class="bx bx-search-alt"></i> button on an intent to see its detection patterns and configure it.</p>
                                </div>
                            </div>
                        </div>

                        {{-- Intent Detection Flow --}}
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3"><i class="bx bx-git-merge text-success me-2"></i>Detection Pipeline</h4>
                                <div class="border rounded p-3 bg-light">
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="badge bg-primary rounded-pill me-2">1</span>
                                        <span>User sends message</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2 ms-3">
                                        <i class="bx bx-subdirectory-right text-muted me-1"></i>
                                        <span class="badge bg-warning rounded-pill me-2">2</span>
                                        <span>Check maintenance mode & master toggle</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2 ms-3">
                                        <i class="bx bx-subdirectory-right text-muted me-1"></i>
                                        <span class="badge bg-warning rounded-pill me-2">3</span>
                                        <span>IntentService detects intent (keywords + fuzzy + context)</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2 ms-3">
                                        <i class="bx bx-subdirectory-right text-muted me-1"></i>
                                        <span class="badge bg-info rounded-pill me-2">4</span>
                                        <span>Check if intent is toggled ON (admin settings)</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2 ms-3">
                                        <i class="bx bx-subdirectory-right text-muted me-1"></i>
                                        <span class="badge bg-info rounded-pill me-2">5</span>
                                        <span>Check custom flows for matching trigger</span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2 ms-3">
                                        <i class="bx bx-subdirectory-right text-muted me-1"></i>
                                        <span class="badge bg-success rounded-pill me-2">6</span>
                                        <span>Execute handler → generate response</span>
                                    </div>
                                    <div class="d-flex align-items-center ms-3">
                                        <i class="bx bx-subdirectory-right text-muted me-1"></i>
                                        <span class="badge bg-success rounded-pill me-2">7</span>
                                        <span>Save conversation + return response + quick replies</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Custom Product Keywords --}}
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-3"><i class="bx bx-purchase-tag text-primary me-2"></i>Custom Product Keywords</h4>
                                <p class="text-muted mb-2">Add keywords that trigger <code>product_inquiry</code> intent. The system auto-detects 100+ brand names from your catalog; add custom ones here (one per line).</p>
                                <textarea class="form-control" id="custom_product_keywords" rows="5" placeholder="premium whisky&#10;gift set&#10;travel exclusive">{{ $settings['custom_product_keywords'] ?? '' }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════════
                 TAB 2: CUSTOM FLOWS
                 ═══════════════════════════════════════════════════════════════ --}}
            <div class="tab-pane" id="tab_flows" role="tabpanel">
                <div class="row">
                    <div class="col-xl-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <h4 class="card-title mb-0"><i class="bx bx-git-branch text-primary me-2"></i>Conversation Flows</h4>
                                    <button class="btn btn-sm btn-primary" onclick="addFlow()">
                                        <i class="bx bx-plus me-1"></i> New Flow
                                    </button>
                                </div>
                                <p class="text-muted mb-3">Create custom conversation flows with triggers, conditions, and multi-step responses. Flows take priority over default intent handlers.</p>

                                <div id="flowList">
                                    {{-- populated by JS --}}
                                </div>

                                <div id="flowEmptyState" class="text-center py-4 text-muted {{ !empty($flows) ? 'd-none' : '' }}">
                                    <i class="bx bx-git-branch font-size-48 d-block mb-2"></i>
                                    <p>No custom flows yet. Click "New Flow" to create one.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8">
                        <div class="card" id="flowEditorCard">
                            <div class="card-body">
                                <h4 class="card-title mb-3" id="flowEditorTitle"><i class="bx bx-edit text-warning me-2"></i>Flow Editor</h4>

                                <div id="flowEditorEmpty" class="text-center py-5 text-muted">
                                    <i class="bx bx-edit font-size-48 d-block mb-2"></i>
                                    <p>Select a flow from the list or create a new one.</p>
                                </div>

                                <div id="flowEditor" class="d-none">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Flow Name</label>
                                            <input type="text" class="form-control" id="flow_name" placeholder="e.g., Duty Free Welcome Offer">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Priority</label>
                                            <input type="number" class="form-control" id="flow_priority" value="10" min="1" max="100">
                                            <small class="text-muted">Higher = checked first</small>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">Status</label>
                                            <select class="form-select" id="flow_status">
                                                <option value="active">Active</option>
                                                <option value="draft">Draft</option>
                                                <option value="paused">Paused</option>
                                            </select>
                                        </div>
                                    </div>

                                    <hr>

                                    {{-- Trigger Section --}}
                                    <h5 class="mb-3"><i class="bx bx-target-lock text-danger me-2"></i>Trigger Conditions <small class="text-muted">(when to activate this flow)</small></h5>
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Trigger Type</label>
                                            <select class="form-select" id="flow_trigger_type" onchange="updateTriggerFields()">
                                                <option value="keyword">Keyword Match</option>
                                                <option value="intent">Intent Match</option>
                                                <option value="page">Page URL Contains</option>
                                                <option value="event">Frontend Event</option>
                                                <option value="schedule">Time-Based</option>
                                                <option value="visitor">Visitor Attribute</option>
                                            </select>
                                        </div>
                                        <div class="col-md-8" id="triggerFieldsContainer">
                                            <label class="form-label" id="triggerFieldLabel">Keywords (comma-separated)</label>
                                            <input type="text" class="form-control" id="flow_trigger_value" placeholder="e.g., gift, duty free offer, welcome deal">
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Condition (optional)</label>
                                            <select class="form-select" id="flow_condition">
                                                <option value="">No Condition</option>
                                                <option value="first_visit">First Visit Only</option>
                                                <option value="returning">Returning Visitor</option>
                                                <option value="has_cart">Has Items in Cart</option>
                                                <option value="empty_cart">Empty Cart</option>
                                                <option value="vip">VIP Customer</option>
                                                <option value="time_range">Within Time Range</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Max Triggers Per Session</label>
                                            <input type="number" class="form-control" id="flow_max_triggers" value="1" min="1" max="10">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Cooldown (minutes)</label>
                                            <input type="number" class="form-control" id="flow_cooldown" value="0" min="0" max="1440">
                                            <small class="text-muted">0 = no cooldown</small>
                                        </div>
                                    </div>

                                    <hr>

                                    {{-- Steps Section --}}
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h5 class="mb-0"><i class="bx bx-conversation text-primary me-2"></i>Response Steps</h5>
                                        <button class="btn btn-sm btn-outline-primary" onclick="addFlowStep()">
                                            <i class="bx bx-plus me-1"></i> Add Step
                                        </button>
                                    </div>

                                    <div id="flowSteps">
                                        {{-- dynamically populated --}}
                                    </div>

                                    <div id="stepsEmptyState" class="text-center py-3 text-muted border rounded">
                                        <i class="bx bx-message-dots font-size-36 d-block mb-1"></i>
                                        <p class="mb-0">Add response steps to define the conversation flow.</p>
                                    </div>

                                    <hr>

                                    <div class="d-flex justify-content-between">
                                        <button class="btn btn-outline-danger" onclick="deleteFlow()">
                                            <i class="bx bx-trash me-1"></i> Delete Flow
                                        </button>
                                        <button class="btn btn-primary" onclick="saveCurrentFlow()">
                                            <i class="bx bx-save me-1"></i> Save Flow
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════════
                 TAB 3: TRIGGERS & ACTIONS
                 ═══════════════════════════════════════════════════════════════ --}}
            <div class="tab-pane" id="tab_triggers" role="tabpanel">
                <div class="row">
                    {{-- Proactive Triggers --}}
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><i class="bx bx-target-lock text-success me-2"></i>Proactive Triggers</h4>
                                <p class="text-muted mb-3">Configure when the chatbot proactively reaches out to visitors.</p>

                                @foreach([
                                    ['trigger_cart_abandonment', 'Cart Abandonment', 'Auto-trigger when customer has items in cart but is leaving', 'bx-cart'],
                                    ['trigger_exit_intent', 'Exit Intent', 'Show chat when mouse leaves window (desktop)', 'bx-exit'],
                                    ['trigger_inactivity', 'Inactivity Timeout', 'Reach out after period of no activity', 'bx-time-five'],
                                    ['trigger_scroll_depth', 'Scroll Depth', 'Open chat when user scrolls past a threshold', 'bx-mouse'],
                                ] as [$key, $label, $desc, $icon])
                                <div class="mb-3 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm me-3">
                                            <span class="avatar-title rounded bg-soft-success text-success font-size-18">
                                                <i class="bx {{ $icon }}"></i>
                                            </span>
                                        </div>
                                        <div>
                                            <label class="form-label mb-0">{{ $label }}</label>
                                            <small class="d-block text-muted">{{ $desc }}</small>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input trigger-toggle" id="{{ $key }}" data-key="{{ $key }}"
                                            {{ ($settings[$key] ?? '0') == '1' ? 'checked' : '' }}>
                                    </div>
                                </div>
                                @endforeach

                                <hr>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Cart Delay (seconds)</label>
                                        <input type="number" class="form-control" id="trigger_cart_delay" value="{{ $settings['trigger_cart_delay'] ?? 60 }}" min="10" max="300">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Inactivity Timeout (sec)</label>
                                        <input type="number" class="form-control" id="trigger_inactivity_delay" value="{{ $settings['trigger_inactivity_delay'] ?? 120 }}" min="30" max="600">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Scroll Threshold (%)</label>
                                        <input type="number" class="form-control" id="trigger_scroll_percent" value="{{ $settings['trigger_scroll_percent'] ?? 70 }}" min="10" max="100">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Cart Message</label>
                                        <textarea class="form-control" id="trigger_cart_message" rows="2">{{ $settings['trigger_cart_message'] ?? "Hey! I noticed you have items in your cart. Need any help completing your purchase?" }}</textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Actions & Escalation --}}
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><i class="bx bx-transfer-alt text-danger me-2"></i>Actions & Escalation</h4>

                                <h6 class="text-uppercase text-muted small mb-3">Bot Actions</h6>
                                <div class="mb-3">
                                    <p class="text-muted">The chatbot can emit these JavaScript actions to your storefront widget:</p>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead class="table-light">
                                                <tr><th>Action</th><th>Payload</th><th>Handler</th></tr>
                                            </thead>
                                            <tbody>
                                                <tr><td><code>add_to_cart</code></td><td><code>{ product_id }</code></td><td>add_to_cart intent</td></tr>
                                                <tr><td><code>redirect</code></td><td><code>{ url }</code></td><td>Product card clicks</td></tr>
                                                <tr><td><code>open_product</code></td><td><code>{ product_id, url }</code></td><td>Product inquiry</td></tr>
                                                <tr><td><code>apply_coupon</code></td><td><code>{ code }</code></td><td>Coupon inquiry</td></tr>
                                                <tr><td><code>escalate</code></td><td><code>{ email, reason }</code></td><td>Sentiment/VIP</td></tr>
                                                <tr><td><code>close_chat</code></td><td><code>{}</code></td><td>Farewell</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <hr>

                                <h6 class="text-uppercase text-muted small mb-3">Escalation Rules</h6>
                                <div class="mb-3 d-flex align-items-center justify-content-between">
                                    <div>
                                        <label class="form-label mb-0">Enable Human Handoff</label>
                                        <small class="d-block text-muted">Transfer to live agent when escalated</small>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input" id="escalation_enabled"
                                            {{ ($settings['escalation_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Escalation Email</label>
                                        <input type="email" class="form-control" id="escalation_email" value="{{ $settings['escalation_email'] ?? '' }}" placeholder="support@store.com">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Max Bot Turns Before Offer</label>
                                        <input type="number" class="form-control" id="max_bot_turns" value="{{ $settings['max_bot_turns'] ?? 10 }}" min="3" max="50">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Sentiment Threshold (0-100)</label>
                                        <input type="number" class="form-control" id="sentiment_threshold" value="{{ $settings['sentiment_threshold'] ?? 30 }}" min="0" max="100">
                                        <small class="text-muted">Auto-escalate below this score</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">VIP LTV Threshold (₹)</label>
                                        <input type="number" class="form-control" id="vip_ltv_threshold" value="{{ $settings['vip_ltv_threshold'] ?? 2000 }}" min="0">
                                        <small class="text-muted">Above this = VIP treatment</small>
                                    </div>
                                </div>

                                <hr>

                                <h6 class="text-uppercase text-muted small mb-3">Escalation SLA (ProactiveSupportService)</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="table-light">
                                            <tr><th>Queue</th><th>SLA</th><th>Trigger</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr><td><span class="badge bg-danger">VIP Priority</span></td><td>2 min</td><td>VIP customer + negative sentiment</td></tr>
                                            <tr><td><span class="badge bg-warning">Escalation</span></td><td>5 min</td><td>Sentiment &lt; threshold or declining trend</td></tr>
                                            <tr><td><span class="badge bg-info">Monitored</span></td><td>15 min</td><td>Mildly negative sentiment</td></tr>
                                            <tr><td><span class="badge bg-secondary">Standard</span></td><td>Bot</td><td>Normal conversations</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════════
                 TAB 4: ADVANCED SERVICES
                 ═══════════════════════════════════════════════════════════════ --}}
            <div class="tab-pane" id="tab_advanced" role="tabpanel">
                <div class="row">
                    {{-- Advanced Chat Service (UC36-40) --}}
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><i class="bx bx-rocket text-primary me-2"></i>Advanced Chat Service</h4>
                                <p class="text-muted mb-3">Rich engagement features accessible via API endpoints.</p>

                                @foreach([
                                    ['chatbot_visual_tracking', 'Visual Order Tracking', 'UC36', 'Rich timeline with 8 status stages, tracking URL, item details', '/api/v1/chatbot/advanced/order-tracking'],
                                    ['chatbot_objection_handler', 'Pre-Checkout Objection Handler', 'UC37', 'Detects price/trust/shipping/return/quality objections, counter-responds with social proof', '/api/v1/chatbot/advanced/objection-handler'],
                                    ['chatbot_subscription_mgmt', 'Subscription Management', 'UC38', 'List/pause/cancel/modify subscriptions via chat', '/api/v1/chatbot/advanced/subscription'],
                                    ['chatbot_gift_card', 'Gift Card Builder', 'UC39', 'Multi-step wizard: design → personalize → confirm → code generation', '/api/v1/chatbot/advanced/gift-card'],
                                    ['chatbot_video_review', 'Video Review Guide', 'UC40', 'Invite → guidelines → upload → reward code (20% off)', '/api/v1/chatbot/advanced/video-review'],
                                ] as [$key, $label, $uc, $desc, $endpoint])
                                <div class="card border mb-3">
                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-start justify-content-between">
                                            <div>
                                                <h6 class="mb-1">{{ $label }} <span class="badge bg-soft-primary text-primary">{{ $uc }}</span></h6>
                                                <p class="text-muted small mb-1">{{ $desc }}</p>
                                                <code class="small">POST {{ $endpoint }}</code>
                                            </div>
                                            <div class="form-check form-switch ms-3">
                                                <input type="checkbox" class="form-check-input advanced-toggle" data-key="{{ $key }}"
                                                    {{ ($settings[$key] ?? '1') == '1' ? 'checked' : '' }}>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Proactive Support Service (UC31-35) --}}
                    <div class="col-xl-6">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><i class="bx bx-support text-success me-2"></i>Proactive Support Service</h4>
                                <p class="text-muted mb-3">Automated support features with intelligent routing.</p>

                                @foreach([
                                    ['chatbot_order_modification', 'Order Modification', 'UC31', 'Change address, cancel, add/remove items, upgrade shipping for pending orders', '/api/v1/chatbot/proactive/order-modification'],
                                    ['chatbot_sentiment_escalation', 'Sentiment Escalation', 'UC32', 'Real-time sentiment scoring (keyword + caps + puntuaction), VIP fast-lane, queue routing', '/api/v1/chatbot/proactive/sentiment-escalation'],
                                    ['chatbot_vip_greeting', 'VIP Greeting', 'UC33', 'Tier detection (Diamond/Platinum/Gold/Silver/Bronze), personalized welcome + quick actions', '/api/v1/chatbot/proactive/vip-greeting'],
                                    ['chatbot_warranty_claims', 'Warranty Claims', 'UC34', 'Multi-step: identify → describe issue → submit claim (5 issue types)', '/api/v1/chatbot/proactive/warranty-claim'],
                                    ['chatbot_multi_sizing', 'Multi-Item Sizing', 'UC35', 'Per-item size recs based on customer profile + stock check + fit tips', '/api/v1/chatbot/proactive/sizing-assistant'],
                                ] as [$key, $label, $uc, $desc, $endpoint])
                                <div class="card border mb-3">
                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-start justify-content-between">
                                            <div>
                                                <h6 class="mb-1">{{ $label }} <span class="badge bg-soft-success text-success">{{ $uc }}</span></h6>
                                                <p class="text-muted small mb-1">{{ $desc }}</p>
                                                <code class="small">POST {{ $endpoint }}</code>
                                            </div>
                                            <div class="form-check form-switch ms-3">
                                                <input type="checkbox" class="form-check-input advanced-toggle" data-key="{{ $key }}"
                                                    {{ ($settings[$key] ?? '0') == '1' ? 'checked' : '' }}>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════════
                 TAB 5: API REFERENCE
                 ═══════════════════════════════════════════════════════════════ --}}
            <div class="tab-pane" id="tab_api" role="tabpanel">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title mb-4"><i class="bx bx-code-alt text-info me-2"></i>Chatbot API Reference</h4>
                                <p class="text-muted mb-3">All endpoints require header <code>X-Ecom360-Key: {{ $tenant->api_key ?? 'YOUR_API_KEY' }}</code>. Rate limited to 60 requests/minute.</p>

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle mb-0">
                                        <thead class="table-light">
                                            <tr><th style="width:80px">Method</th><th>Endpoint</th><th>Description</th><th>Key Parameters</th></tr>
                                        </thead>
                                        <tbody>
                                            @foreach([
                                                ['POST', '/api/v1/chatbot/send', 'Send message & get AI response', 'message, conversation_id, session_id, email, page_context'],
                                                ['POST', '/api/v1/chatbot/rage-click', 'Rage-click intervention', 'element, page_url, session_id, email'],
                                                ['GET', '/api/v1/chatbot/history/{id}', 'Get conversation messages', 'conversationId (path)'],
                                                ['GET', '/api/v1/chatbot/conversations', 'List conversations', 'status, email, intent, limit'],
                                                ['POST', '/api/v1/chatbot/resolve/{id}', 'Resolve conversation', 'satisfaction_score (1-5)'],
                                                ['GET', '/api/v1/chatbot/widget-config', 'Widget config for embed', '—'],
                                                ['GET', '/api/v1/chatbot/analytics', 'Usage analytics', 'days (default: 30)'],
                                                ['POST', '/api/v1/chatbot/advanced/order-tracking', 'Visual order timeline', 'order_id, email'],
                                                ['POST', '/api/v1/chatbot/advanced/objection-handler', 'Objection countering', 'cart (items), objection (text)'],
                                                ['POST', '/api/v1/chatbot/advanced/subscription', 'Subscription CRUD', 'action (list|pause|cancel|modify), subscription_id'],
                                                ['POST', '/api/v1/chatbot/advanced/gift-card', 'Gift card wizard', 'step, amount, design, message, recipient_email'],
                                                ['POST', '/api/v1/chatbot/advanced/video-review', 'Video review flow', 'step (invite|upload_complete), product_id'],
                                                ['POST', '/api/v1/chatbot/proactive/order-modification', 'Modify pending order', 'order_id, action, new_address, item_id'],
                                                ['POST', '/api/v1/chatbot/proactive/sentiment-escalation', 'Sentiment routing', 'message, conversation_id, customer_email'],
                                                ['POST', '/api/v1/chatbot/proactive/vip-greeting', 'VIP greeting', 'email'],
                                                ['POST', '/api/v1/chatbot/proactive/warranty-claim', 'Warranty claim', 'step, order_id, product_id, issue_type, description'],
                                                ['POST', '/api/v1/chatbot/proactive/sizing-assistant', 'Multi-item sizing', 'cart (items), profile (height, weight, shoe_size)'],
                                            ] as [$method, $endpoint, $desc, $params])
                                            <tr>
                                                <td><span class="badge bg-{{ $method === 'GET' ? 'success' : 'primary' }}">{{ $method }}</span></td>
                                                <td><code>{{ $endpoint }}</code></td>
                                                <td>{{ $desc }}</td>
                                                <td><small class="text-muted">{{ $params }}</small></td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <h5 class="mt-4 mb-3">Quick Test</h5>
                                <div class="row">
                                    <div class="col-md-8">
                                        <input type="text" class="form-control" id="testMessage" placeholder="Type a test message... e.g., 'show me whisky under 5000'">
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-primary w-100" onclick="testChatbot()">
                                            <i class="bx bx-send me-1"></i> Send Test Message
                                        </button>
                                    </div>
                                </div>
                                <div id="testResult" class="mt-3 d-none">
                                    <pre class="bg-dark text-success p-3 rounded" style="max-height:400px;overflow:auto;"><code id="testResultCode"></code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- end tab-content --}}

        {{-- ── Global Save ── --}}
        <div class="row mt-3">
            <div class="col-12 text-end mb-4">
                <button class="btn btn-primary btn-lg px-5" id="saveAllBtn" onclick="saveAll()">
                    <i class="bx bx-save me-1"></i> Save All Changes
                </button>
            </div>
        </div>

    </div>
</div>

{{-- Pattern Detail Modal --}}
<div class="modal fade" id="patternModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="patternModalTitle">Intent Patterns</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="patternModalBody"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// ══════════════════════════════════════════════════════════════
// FLOW BUILDER STATE
// ══════════════════════════════════════════════════════════════
let flows = @json(json_decode($settings['custom_flows'] ?? '[]', true) ?: []);
let currentFlowIdx = -1;
let stepCounter = 0;

function renderFlowList() {
    const list = document.getElementById('flowList');
    const empty = document.getElementById('flowEmptyState');
    if (!flows.length) { list.innerHTML = ''; empty.classList.remove('d-none'); return; }
    empty.classList.add('d-none');

    list.innerHTML = flows.map((f, i) => `
        <div class="card border ${i === currentFlowIdx ? 'border-primary' : ''} mb-2 cursor-pointer" onclick="selectFlow(${i})" style="cursor:pointer">
            <div class="card-body p-2 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <strong>${f.name || 'Untitled Flow'}</strong>
                    <br><small class="text-muted">${f.trigger_type || 'keyword'} · ${f.steps?.length || 0} steps</small>
                </div>
                <span class="badge bg-${f.status === 'active' ? 'success' : f.status === 'paused' ? 'warning' : 'secondary'}">${f.status || 'draft'}</span>
            </div>
        </div>
    `).join('');
}

function addFlow() {
    flows.push({
        name: 'New Flow ' + (flows.length + 1),
        trigger_type: 'keyword',
        trigger_value: '',
        condition: '',
        max_triggers: 1,
        cooldown: 0,
        priority: 10,
        status: 'draft',
        steps: [],
    });
    currentFlowIdx = flows.length - 1;
    renderFlowList();
    loadFlowEditor();
}

function selectFlow(idx) {
    currentFlowIdx = idx;
    renderFlowList();
    loadFlowEditor();
}

function loadFlowEditor() {
    if (currentFlowIdx < 0 || currentFlowIdx >= flows.length) return;
    const f = flows[currentFlowIdx];
    document.getElementById('flowEditorEmpty').classList.add('d-none');
    document.getElementById('flowEditor').classList.remove('d-none');

    document.getElementById('flow_name').value = f.name || '';
    document.getElementById('flow_priority').value = f.priority || 10;
    document.getElementById('flow_status').value = f.status || 'draft';
    document.getElementById('flow_trigger_type').value = f.trigger_type || 'keyword';
    document.getElementById('flow_trigger_value').value = f.trigger_value || '';
    document.getElementById('flow_condition').value = f.condition || '';
    document.getElementById('flow_max_triggers').value = f.max_triggers || 1;
    document.getElementById('flow_cooldown').value = f.cooldown || 0;

    updateTriggerFields();
    renderSteps(f.steps || []);
}

function updateTriggerFields() {
    const type = document.getElementById('flow_trigger_type').value;
    const label = document.getElementById('triggerFieldLabel');
    const input = document.getElementById('flow_trigger_value');

    const labels = {
        keyword: 'Keywords (comma-separated)',
        intent: 'Intent Name (e.g., product_inquiry)',
        page: 'URL Contains (e.g., /checkout, /cart)',
        event: 'Event Name (e.g., cart_add, product_view)',
        schedule: 'Cron Expression or Time (e.g., 09:00-17:00)',
        visitor: 'Attribute (e.g., visits > 3, country = IN)',
    };
    const placeholders = {
        keyword: 'gift, duty free, welcome deal',
        intent: 'product_inquiry',
        page: '/checkout',
        event: 'cart_add',
        schedule: '09:00-17:00',
        visitor: 'visits > 3',
    };
    label.textContent = labels[type] || 'Value';
    input.placeholder = placeholders[type] || '';
}

function renderSteps(steps) {
    const container = document.getElementById('flowSteps');
    const empty = document.getElementById('stepsEmptyState');

    if (!steps.length) { container.innerHTML = ''; empty.classList.remove('d-none'); return; }
    empty.classList.add('d-none');

    container.innerHTML = steps.map((s, i) => `
        <div class="card border mb-2" id="step_${i}">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="mb-0"><span class="badge bg-primary rounded-pill me-2">${i + 1}</span>Step ${i + 1}</h6>
                    <div>
                        ${i > 0 ? `<button class="btn btn-sm btn-outline-secondary me-1" onclick="moveStep(${i}, -1)"><i class="bx bx-up-arrow-alt"></i></button>` : ''}
                        ${i < steps.length - 1 ? `<button class="btn btn-sm btn-outline-secondary me-1" onclick="moveStep(${i}, 1)"><i class="bx bx-down-arrow-alt"></i></button>` : ''}
                        <button class="btn btn-sm btn-outline-danger" onclick="removeStep(${i})"><i class="bx bx-trash"></i></button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <select class="form-select form-select-sm" onchange="updateStepType(${i}, this.value)">
                            <option value="text" ${s.type === 'text' ? 'selected' : ''}>Text Message</option>
                            <option value="quick_reply" ${s.type === 'quick_reply' ? 'selected' : ''}>Quick Replies</option>
                            <option value="product_search" ${s.type === 'product_search' ? 'selected' : ''}>Product Search</option>
                            <option value="action" ${s.type === 'action' ? 'selected' : ''}>Action/Event</option>
                            <option value="delay" ${s.type === 'delay' ? 'selected' : ''}>Delay</option>
                            <option value="condition" ${s.type === 'condition' ? 'selected' : ''}>Condition Branch</option>
                            <option value="api_call" ${s.type === 'api_call' ? 'selected' : ''}>API Call</option>
                            <option value="escalate" ${s.type === 'escalate' ? 'selected' : ''}>Escalate to Human</option>
                        </select>
                    </div>
                    <div class="col-md-9 mb-2">
                        ${getStepInput(s, i)}
                    </div>
                </div>
                ${s.type === 'delay' ? `<small class="text-muted">Seconds: <input type="number" class="form-control form-control-sm d-inline-block" style="width:80px" value="${s.delay || 2}" onchange="updateStepField(${i}, 'delay', this.value)" min="1" max="30"></small>` : ''}
            </div>
        </div>
    `).join('');
}

function getStepInput(step, idx) {
    switch (step.type) {
        case 'text':
            return `<textarea class="form-control form-control-sm" rows="2" onchange="updateStepField(${idx}, 'content', this.value)" placeholder="Bot message text...">${step.content || ''}</textarea>`;
        case 'quick_reply':
            return `<input type="text" class="form-control form-control-sm" value="${step.content || ''}" onchange="updateStepField(${idx}, 'content', this.value)" placeholder="Button labels, comma-separated (e.g., Yes, No, Maybe Later)">`;
        case 'product_search':
            return `<input type="text" class="form-control form-control-sm" value="${step.content || ''}" onchange="updateStepField(${idx}, 'content', this.value)" placeholder="Search query (e.g., whisky under 5000)">`;
        case 'action':
            return `<select class="form-select form-select-sm" onchange="updateStepField(${idx}, 'content', this.value)">
                <option value="add_to_cart" ${step.content === 'add_to_cart' ? 'selected' : ''}>Add to Cart</option>
                <option value="redirect" ${step.content === 'redirect' ? 'selected' : ''}>Redirect to URL</option>
                <option value="apply_coupon" ${step.content === 'apply_coupon' ? 'selected' : ''}>Apply Coupon</option>
                <option value="open_product" ${step.content === 'open_product' ? 'selected' : ''}>Open Product</option>
                <option value="close_chat" ${step.content === 'close_chat' ? 'selected' : ''}>Close Chat</option>
                <option value="custom" ${step.content === 'custom' ? 'selected' : ''}>Custom Event</option>
            </select>`;
        case 'delay':
            return `<small class="text-muted">Pause before next step</small>`;
        case 'condition':
            return `<input type="text" class="form-control form-control-sm" value="${step.content || ''}" onchange="updateStepField(${idx}, 'content', this.value)" placeholder="Condition (e.g., has_cart, user_vip, sentiment > 50)">`;
        case 'api_call':
            return `<input type="text" class="form-control form-control-sm" value="${step.content || ''}" onchange="updateStepField(${idx}, 'content', this.value)" placeholder="Endpoint (e.g., /api/v1/chatbot/advanced/objection-handler)">`;
        case 'escalate':
            return `<input type="text" class="form-control form-control-sm" value="${step.content || ''}" onchange="updateStepField(${idx}, 'content', this.value)" placeholder="Reason for escalation">`;
        default:
            return `<input type="text" class="form-control form-control-sm" value="${step.content || ''}" onchange="updateStepField(${idx}, 'content', this.value)">`;
    }
}

function addFlowStep() {
    if (currentFlowIdx < 0) return;
    flows[currentFlowIdx].steps = flows[currentFlowIdx].steps || [];
    flows[currentFlowIdx].steps.push({ type: 'text', content: '' });
    renderSteps(flows[currentFlowIdx].steps);
}

function removeStep(idx) {
    if (currentFlowIdx < 0) return;
    flows[currentFlowIdx].steps.splice(idx, 1);
    renderSteps(flows[currentFlowIdx].steps);
}

function moveStep(idx, dir) {
    if (currentFlowIdx < 0) return;
    const s = flows[currentFlowIdx].steps;
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= s.length) return;
    [s[idx], s[newIdx]] = [s[newIdx], s[idx]];
    renderSteps(s);
}

function updateStepType(idx, type) {
    if (currentFlowIdx < 0) return;
    flows[currentFlowIdx].steps[idx].type = type;
    flows[currentFlowIdx].steps[idx].content = '';
    renderSteps(flows[currentFlowIdx].steps);
}

function updateStepField(idx, field, value) {
    if (currentFlowIdx < 0) return;
    flows[currentFlowIdx].steps[idx][field] = value;
}

function saveCurrentFlow() {
    if (currentFlowIdx < 0) return;
    const f = flows[currentFlowIdx];
    f.name = document.getElementById('flow_name').value;
    f.priority = parseInt(document.getElementById('flow_priority').value) || 10;
    f.status = document.getElementById('flow_status').value;
    f.trigger_type = document.getElementById('flow_trigger_type').value;
    f.trigger_value = document.getElementById('flow_trigger_value').value;
    f.condition = document.getElementById('flow_condition').value;
    f.max_triggers = parseInt(document.getElementById('flow_max_triggers').value) || 1;
    f.cooldown = parseInt(document.getElementById('flow_cooldown').value) || 0;
    renderFlowList();
    showAlert('Flow saved locally. Click "Save All Changes" to persist.', 'info');
}

function deleteFlow() {
    if (currentFlowIdx < 0) return;
    if (!confirm('Delete this flow?')) return;
    flows.splice(currentFlowIdx, 1);
    currentFlowIdx = -1;
    document.getElementById('flowEditorEmpty').classList.remove('d-none');
    document.getElementById('flowEditor').classList.add('d-none');
    renderFlowList();
}

// ══════════════════════════════════════════════════════════════
// INTENT PATTERNS
// ══════════════════════════════════════════════════════════════
function showPatterns(name, patterns) {
    document.getElementById('intentDetail').innerHTML = `
        <h5 class="text-primary mb-2">${name}</h5>
        <label class="form-label fw-bold">Detection Patterns:</label>
        <div class="bg-light p-2 rounded mb-2"><code class="small">${patterns}</code></div>
        <label class="form-label fw-bold">How It Works:</label>
        <ul class="text-muted small mb-0">
            <li>Keyword substring matching (case-insensitive)</li>
            <li>Fuzzy multi-word matching for misspellings</li>
            <li>Context boosting based on current page (product, cart, checkout, search)</li>
            <li>Order ID regex auto-routes to order_tracking at 0.92 confidence</li>
            <li>Custom product keywords merge into product_inquiry patterns</li>
        </ul>
    `;
}

// ══════════════════════════════════════════════════════════════
// SAVE ALL
// ══════════════════════════════════════════════════════════════
function saveAll() {
    const btn = document.getElementById('saveAllBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Saving...';

    const getVal = id => document.getElementById(id)?.value ?? '';
    const getChecked = id => document.getElementById(id)?.checked ? '1' : '0';

    // Collect intent toggles
    const data = { _token: '{{ csrf_token() }}' };
    document.querySelectorAll('.intent-toggle').forEach(el => {
        data['intent_' + el.dataset.intent] = el.checked ? '1' : '0';
    });

    // Collect advanced toggles
    document.querySelectorAll('.advanced-toggle').forEach(el => {
        data[el.dataset.key] = el.checked ? '1' : '0';
    });

    // Collect trigger toggles
    document.querySelectorAll('.trigger-toggle').forEach(el => {
        data[el.dataset.key] = el.checked ? '1' : '0';
    });

    // Trigger params
    data.trigger_cart_delay = getVal('trigger_cart_delay');
    data.trigger_inactivity_delay = getVal('trigger_inactivity_delay');
    data.trigger_scroll_percent = getVal('trigger_scroll_percent');
    data.trigger_cart_message = getVal('trigger_cart_message');

    // Escalation
    data.escalation_enabled = getChecked('escalation_enabled');
    data.escalation_email = getVal('escalation_email');
    data.max_bot_turns = getVal('max_bot_turns');
    data.sentiment_threshold = getVal('sentiment_threshold');
    data.vip_ltv_threshold = getVal('vip_ltv_threshold');

    // Custom keywords
    data.custom_product_keywords = getVal('custom_product_keywords');

    // Custom flows as JSON
    data.custom_flows = JSON.stringify(flows);

    fetch('{{ route("tenant.chatbot.settings.save", $tenant->slug) }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        body: JSON.stringify(data),
    })
    .then(r => r.json())
    .then(d => {
        showAlert(d.message || (d.success ? 'All changes saved.' : 'Error saving.'), d.success ? 'success' : 'danger');
    })
    .catch(e => {
        showAlert('Network error: ' + e.message, 'danger');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-save me-1"></i> Save All Changes';
    });
}

function showAlert(msg, type) {
    const alert = document.getElementById('saveAlert');
    alert.className = `alert alert-${type}`;
    alert.textContent = msg;
    alert.classList.remove('d-none');
    setTimeout(() => alert.classList.add('d-none'), 5000);
}

// ══════════════════════════════════════════════════════════════
// TEST CHATBOT
// ══════════════════════════════════════════════════════════════
function testChatbot() {
    const msg = document.getElementById('testMessage').value.trim();
    if (!msg) return;

    document.getElementById('testResult').classList.remove('d-none');
    document.getElementById('testResultCode').textContent = 'Sending...';

    fetch('/api/v1/chatbot/send', {
        method: 'POST',
        headers: { 'X-Ecom360-Key': '{{ $tenant->api_key ?? "" }}', 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ message: msg, session_id: 'admin-test-' + Date.now() }),
    })
    .then(r => r.json())
    .then(d => {
        document.getElementById('testResultCode').textContent = JSON.stringify(d, null, 2);
    })
    .catch(e => {
        document.getElementById('testResultCode').textContent = 'Error: ' + e.message;
    });
}

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    renderFlowList();
});
</script>
@endpush
