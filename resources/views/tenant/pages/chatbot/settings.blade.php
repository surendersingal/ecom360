@extends('layouts.tenant')
@section('title', 'AI Chatbot Settings')

@section('content')
<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0 font-size-18">AI Chatbot Settings</h4>
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li class="breadcrumb-item"><a href="{{ route('tenant.dashboard', $tenant->slug) }}">Dashboard</a></li>
                            <li class="breadcrumb-item active">Chatbot Settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div id="saveAlert" class="alert d-none" role="alert"></div>

        {{-- ── Section 1: Widget Appearance ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-palette text-primary me-2"></i>Widget Appearance</h4>

                        <div class="mb-3">
                            <label class="form-label">Bot Name</label>
                            <input type="text" class="form-control" id="chatbot_name" value="{{ $settings['chatbot_name'] ?? 'Shopping Assistant' }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Welcome Greeting</label>
                            <textarea class="form-control" id="chatbot_greeting" rows="2">{{ $settings['chatbot_greeting'] ?? 'Hi! 👋 Welcome! How can I help you today?' }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Greeting Quick-Reply Buttons</label>
                            <textarea class="form-control" id="chatbot_greeting_buttons" rows="2" placeholder="Track My Order|track_order, Find a Product|find_product, I Need Help|help, Browse Deals|browse_deals">{{ $settings['chatbot_greeting_buttons'] ?? '' }}</textarea>
                            <small class="text-muted">Comma-separated. Format: <code>Label|value</code> — leave empty for defaults.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Fallback Quick-Reply Buttons</label>
                            <textarea class="form-control" id="chatbot_fallback_buttons" rows="2" placeholder="Track Order|track_order, Product Help|product_help, Talk to Agent|escalate">{{ $settings['chatbot_fallback_buttons'] ?? '' }}</textarea>
                            <small class="text-muted">Shown when bot cannot match intent. Comma-separated <code>Label|value</code>.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">General Fallback Message</label>
                            <textarea class="form-control" id="tpl_general_fallback" rows="2">{{ $settings['tpl_general_fallback'] ?? "I'm not sure I understood that. Could you rephrase, or try one of these options?" }}</textarea>
                            <small class="text-muted">Displayed when the bot can't understand the user.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Avatar URL</label>
                            <input type="url" class="form-control" id="chatbot_avatar" value="{{ $settings['chatbot_avatar'] ?? '' }}" placeholder="https://your-store.com/bot-avatar.png">
                            <small class="text-muted">Leave empty for default icon</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Primary Color</label>
                            <input type="color" class="form-control form-control-color w-100" id="chatbot_color" value="{{ $settings['chatbot_color'] ?? '#4F46E5' }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Widget Position</label>
                            <select class="form-select" id="chatbot_position">
                                <option value="bottom-right" {{ ($settings['chatbot_position'] ?? 'bottom-right') == 'bottom-right' ? 'selected' : '' }}>Bottom Right</option>
                                <option value="bottom-left" {{ ($settings['chatbot_position'] ?? '') == 'bottom-left' ? 'selected' : '' }}>Bottom Left</option>
                                <option value="top-right" {{ ($settings['chatbot_position'] ?? '') == 'top-right' ? 'selected' : '' }}>Top Right</option>
                                <option value="top-left" {{ ($settings['chatbot_position'] ?? '') == 'top-left' ? 'selected' : '' }}>Top Left</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Widget Width (px)</label>
                            <input type="number" class="form-control" id="chatbot_width" value="{{ $settings['chatbot_width'] ?? 380 }}" min="300" max="500">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Widget Height (px)</label>
                            <input type="number" class="form-control" id="chatbot_height" value="{{ $settings['chatbot_height'] ?? 520 }}" min="400" max="700">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Offline Message</label>
                            <textarea class="form-control" id="chatbot_offline_message" rows="2">{{ $settings['chatbot_offline_message'] ?? "We're currently offline. Leave us a message and we'll get back to you!" }}</textarea>
                            <small class="text-muted">Shown outside active hours</small>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Section 2: Bot Behavior ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-bot text-success me-2"></i>Bot Behavior</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Enable Chatbot</label>
                                <small class="d-block text-muted">Master toggle for the chatbot widget</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_enabled" {{ ($settings['chatbot_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Auto-Open Delay (seconds)</label>
                            <input type="number" class="form-control" id="chatbot_auto_open_seconds" value="{{ $settings['chatbot_auto_open_seconds'] ?? 0 }}" min="0" max="120">
                            <small class="text-muted">0 = don't auto-open. Seconds before chatbot opens automatically.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Language</label>
                            <select class="form-select" id="chatbot_language">
                                @foreach(['en' => 'English', 'hi' => 'Hindi', 'ar' => 'Arabic', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'pt' => 'Portuguese', 'ja' => 'Japanese', 'zh' => 'Chinese', 'ko' => 'Korean', 'th' => 'Thai'] as $code => $name)
                                <option value="{{ $code }}" {{ ($settings['chatbot_language'] ?? 'en') == $code ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Product Cards</label>
                                <small class="d-block text-muted">Display rich product cards for product queries</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_product_cards" {{ ($settings['chatbot_product_cards'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Products in Cards</label>
                            <input type="number" class="form-control" id="chatbot_max_products" value="{{ $settings['chatbot_max_products'] ?? 5 }}" min="1" max="20">
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Quick Replies</label>
                                <small class="d-block text-muted">Display suggested action buttons</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_quick_replies" {{ ($settings['chatbot_quick_replies'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Show Typing Indicator</label>
                                <small class="d-block text-muted">Show "..." while bot is processing</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_typing_indicator" {{ ($settings['chatbot_typing_indicator'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Sound Notifications</label>
                                <small class="d-block text-muted">Play sound for new messages</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_sound_enabled" {{ ($settings['chatbot_sound_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 3: Intent & Response Configuration ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-brain text-warning me-2"></i>Intent Detection</h4>
                        <p class="text-muted mb-3">Enable or disable specific intent handlers. Disabled intents will route to general help.</p>

                        @foreach([
                            'intent_greeting' => ['Greeting Detection', 'Respond to hi/hello/hey'],
                            'intent_farewell' => ['Farewell Detection', 'Respond to bye/goodbye/thanks'],
                            'intent_product_inquiry' => ['Product Inquiry', 'Search products from chat messages'],
                            'intent_order_tracking' => ['Order Tracking', 'Look up order status'],
                            'intent_checkout_help' => ['Checkout Help', 'Assist with checkout issues'],
                            'intent_return_request' => ['Return & Refund', 'Handle return requests'],
                            'intent_coupon_inquiry' => ['Coupon & Promotions', 'Check and apply coupons'],
                            'intent_size_help' => ['Size Assistance', 'Help with sizing/fit'],
                            'intent_shipping_inquiry' => ['Shipping Info', 'Delivery time and cost info'],
                            'intent_add_to_cart' => ['Add to Cart', 'Cart actions from chat'],
                        ] as $key => [$label, $desc])
                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">{{ $label }}</label>
                                <small class="d-block text-muted">{{ $desc }}</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="{{ $key }}" {{ ($settings[$key] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ── Section 4: Advanced Chatbot Features ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-rocket text-danger me-2"></i>Advanced Features</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Rage-Click Intervention</label>
                                <small class="d-block text-muted">Auto-open chatbot when user rage-clicks</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_rage_click" {{ ($settings['chatbot_rage_click'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Sentiment Escalation</label>
                                <small class="d-block text-muted">Auto-escalate frustrated customers to support</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_sentiment_escalation" {{ ($settings['chatbot_sentiment_escalation'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">VIP Customer Greeting</label>
                                <small class="d-block text-muted">Personalized welcome for high-LTV customers</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_vip_greeting" {{ ($settings['chatbot_vip_greeting'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Pre-Checkout Objection Handler</label>
                                <small class="d-block text-muted">Counter objections at checkout</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_objection_handler" {{ ($settings['chatbot_objection_handler'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Visual Order Tracking</label>
                                <small class="d-block text-muted">Rich order timeline in chat</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_visual_tracking" {{ ($settings['chatbot_visual_tracking'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Order Modification</label>
                                <small class="d-block text-muted">Allow address/item changes via chat</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_order_modification" {{ ($settings['chatbot_order_modification'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Warranty Claims</label>
                                <small class="d-block text-muted">Guided warranty claim filing</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_warranty_claims" {{ ($settings['chatbot_warranty_claims'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Multi-Item Sizing</label>
                                <small class="d-block text-muted">Size recommendations for entire cart</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_multi_sizing" {{ ($settings['chatbot_multi_sizing'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 5: Response Templates ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-message-dots text-info me-2"></i>Response Templates</h4>
                        <p class="text-muted mb-3">Customize automated responses. Use <code>**bold**</code> for formatting.</p>

                        <div class="mb-3">
                            <label class="form-label">Shipping Info Response</label>
                            <textarea class="form-control" id="tpl_shipping" rows="4">{{ $settings['tpl_shipping'] ?? "Here are our shipping options:\n\n• **Standard** — 5-7 business days (Free over \$50)\n• **Express** — 2-3 business days (\$9.99)\n• **Next Day** — 1 business day (\$19.99)\n\nAll orders include tracking!" }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Return Policy Response</label>
                            <textarea class="form-control" id="tpl_returns" rows="4">{{ $settings['tpl_returns'] ?? "Our return policy allows returns within 30 days of delivery for most items. Would you like to start a return?" }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">No Products Found Message</label>
                            <textarea class="form-control" id="tpl_no_products" rows="2">{{ $settings['tpl_no_products'] ?? "I couldn't find products matching your search. Could you try different keywords or let me help you browse categories?" }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Farewell Message</label>
                            <textarea class="form-control" id="tpl_farewell" rows="2">{{ $settings['tpl_farewell'] ?? "Thank you for chatting! Have a wonderful day! 😊" }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Section 6: Proactive Triggers ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-target-lock text-success me-2"></i>Proactive Triggers</h4>
                        <p class="text-muted mb-3">Configure when the chatbot proactively reaches out.</p>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Cart Abandonment</label>
                                <small class="d-block text-muted">Trigger when user has items in cart but is leaving</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="trigger_cart_abandonment" {{ ($settings['trigger_cart_abandonment'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Cart Abandonment Delay (seconds)</label>
                            <input type="number" class="form-control" id="trigger_cart_delay" value="{{ $settings['trigger_cart_delay'] ?? 60 }}" min="10" max="300">
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Exit Intent</label>
                                <small class="d-block text-muted">Show chat when mouse leaves window</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="trigger_exit_intent" {{ ($settings['trigger_exit_intent'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Inactivity Timeout</label>
                                <small class="d-block text-muted">Reach out after period of no activity</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="trigger_inactivity" {{ ($settings['trigger_inactivity'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Inactivity Timeout (seconds)</label>
                            <input type="number" class="form-control" id="trigger_inactivity_delay" value="{{ $settings['trigger_inactivity_delay'] ?? 120 }}" min="30" max="600">
                        </div>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Scroll Depth Trigger</label>
                                <small class="d-block text-muted">Open chat when user scrolls past threshold</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="trigger_scroll_depth" {{ ($settings['trigger_scroll_depth'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Scroll Depth Threshold (%)</label>
                            <input type="number" class="form-control" id="trigger_scroll_percent" value="{{ $settings['trigger_scroll_percent'] ?? 70 }}" min="10" max="100">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Cart Abandonment Message</label>
                            <textarea class="form-control" id="trigger_cart_message" rows="2">{{ $settings['trigger_cart_message'] ?? "Hey! I noticed you have items in your cart. Need any help completing your purchase?" }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 7: Escalation & Support ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-transfer-alt text-danger me-2"></i>Escalation Settings</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Enable Human Handoff</label>
                                <small class="d-block text-muted">Allow transfer to live agent</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="escalation_enabled" {{ ($settings['escalation_enabled'] ?? '1') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Escalation Email</label>
                            <input type="email" class="form-control" id="escalation_email" value="{{ $settings['escalation_email'] ?? '' }}" placeholder="support@your-store.com">
                            <small class="text-muted">Notify this email when escalation occurs</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Sentiment Threshold (0-100)</label>
                            <input type="number" class="form-control" id="sentiment_threshold" value="{{ $settings['sentiment_threshold'] ?? 30 }}" min="0" max="100">
                            <small class="text-muted">Auto-escalate when sentiment drops below this</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">VIP LTV Threshold ($)</label>
                            <input type="number" class="form-control" id="vip_ltv_threshold" value="{{ $settings['vip_ltv_threshold'] ?? 2000 }}" min="0">
                            <small class="text-muted">Customers above this LTV get VIP treatment</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Bot Turns Before Escalation</label>
                            <input type="number" class="form-control" id="max_bot_turns" value="{{ $settings['max_bot_turns'] ?? 10 }}" min="3" max="50">
                            <small class="text-muted">Auto-offer human help after this many exchanges</small>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Section 8: Business Hours ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-time-five text-purple me-2"></i>Business Hours</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Enforce Business Hours</label>
                                <small class="d-block text-muted">Show offline message outside hours</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="business_hours_enabled" {{ ($settings['business_hours_enabled'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Timezone</label>
                            <select class="form-select" id="business_timezone">
                                @foreach(['Asia/Kolkata','America/New_York','America/Los_Angeles','Europe/London','Europe/Paris','Asia/Dubai','Asia/Singapore','Asia/Tokyo','Australia/Sydney','Pacific/Auckland'] as $tz)
                                <option value="{{ $tz }}" {{ ($settings['business_timezone'] ?? 'Asia/Kolkata') == $tz ? 'selected' : '' }}>{{ $tz }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Active Hours Start</label>
                            <input type="time" class="form-control" id="business_hours_start" value="{{ $settings['business_hours_start'] ?? '09:00' }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Active Hours End</label>
                            <input type="time" class="form-control" id="business_hours_end" value="{{ $settings['business_hours_end'] ?? '21:00' }}">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Days Active</label>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day)
                                @php $days = explode(',', $settings['business_days'] ?? 'Mon,Tue,Wed,Thu,Fri,Sat,Sun'); @endphp
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input business-day" value="{{ $day }}" id="day_{{ $day }}" {{ in_array($day, $days) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="day_{{ $day }}">{{ $day }}</label>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 9: Custom Brand Keywords ── --}}
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-purchase-tag text-primary me-2"></i>Custom Product Keywords</h4>
                        <p class="text-muted mb-3">Additional keywords that should trigger product search intent. The chatbot auto-detects brand names from your catalog, but you can add custom keywords here. One per line.</p>

                        <div class="mb-3">
                            <textarea class="form-control" id="custom_product_keywords" rows="6" placeholder="premium whisky&#10;gift set&#10;travel exclusive&#10;duty free deal">{{ $settings['custom_product_keywords'] ?? '' }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 10: Store Integration ── --}}
        <div class="row">
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-store text-success me-2"></i>Store Integration</h4>

                        <div class="mb-3">
                            <label class="form-label">Store Base URL</label>
                            <input type="url" class="form-control" id="chatbot_store_url" value="{{ $settings['chatbot_store_url'] ?? '' }}" placeholder="https://your-store.com">
                            <small class="text-muted">Used for product links and images in chat</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Product URL Pattern</label>
                            <input type="text" class="form-control" id="chatbot_product_url_pattern" value="{{ $settings['chatbot_product_url_pattern'] ?? '/default/{url_key}.html' }}">
                            <small class="text-muted">Use <code>{url_key}</code> as placeholder</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Currency Symbol</label>
                            <input type="text" class="form-control" id="chatbot_currency_symbol" value="{{ $settings['chatbot_currency_symbol'] ?? '₹' }}" maxlength="5">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Free Shipping Threshold</label>
                            <input type="number" class="form-control" id="chatbot_free_shipping" value="{{ $settings['chatbot_free_shipping'] ?? 50 }}" min="0">
                            <small class="text-muted">Amount above which shipping is free (used in responses)</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Return Policy Days</label>
                            <input type="number" class="form-control" id="chatbot_return_days" value="{{ $settings['chatbot_return_days'] ?? 30 }}" min="0" max="365">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Warranty Period (days)</label>
                            <input type="number" class="form-control" id="chatbot_warranty_days" value="{{ $settings['chatbot_warranty_days'] ?? 365 }}" min="0" max="730">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Emergency Controls ── --}}
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4"><i class="bx bx-shield-x text-danger me-2"></i>Emergency Controls</h4>

                        <div class="mb-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-label mb-0">Maintenance Mode</label>
                                <small class="d-block text-muted">Pause chatbot without deleting settings</small>
                            </div>
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="chatbot_maintenance" {{ ($settings['chatbot_maintenance'] ?? '0') == '1' ? 'checked' : '' }}>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Maintenance Message</label>
                            <textarea class="form-control" id="chatbot_maintenance_message" rows="2">{{ $settings['chatbot_maintenance_message'] ?? "Our chatbot is temporarily under maintenance. Please try again later or email us at support@store.com." }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Max Messages Per Session</label>
                            <input type="number" class="form-control" id="chatbot_max_messages" value="{{ $settings['chatbot_max_messages'] ?? 100 }}" min="10" max="500">
                            <small class="text-muted">Prevent abuse by capping messages per session</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Rate Limit (msg/min per session)</label>
                            <input type="number" class="form-control" id="chatbot_rate_limit" value="{{ $settings['chatbot_rate_limit'] ?? 15 }}" min="5" max="60">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Save Button ── --}}
        <div class="row">
            <div class="col-12 text-end mb-4">
                <button class="btn btn-primary btn-lg px-5" id="saveBtn" onclick="saveChatbotSettings()">
                    <i class="bx bx-save me-1"></i> Save Chatbot Settings
                </button>
            </div>
        </div>

    </div>
</div>
@endsection

@push('scripts')
<script>
    function saveChatbotSettings() {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Saving...';

        const getVal = id => document.getElementById(id)?.value ?? '';
        const getChecked = id => document.getElementById(id)?.checked ? '1' : '0';

        // Collect business days
        const days = [];
        document.querySelectorAll('.business-day:checked').forEach(cb => days.push(cb.value));

        const data = {
            _token: '{{ csrf_token() }}',
            // Appearance
            chatbot_name: getVal('chatbot_name'),
            chatbot_greeting: getVal('chatbot_greeting'),
            chatbot_greeting_buttons: getVal('chatbot_greeting_buttons'),
            chatbot_fallback_buttons: getVal('chatbot_fallback_buttons'),
            tpl_general_fallback: getVal('tpl_general_fallback'),
            chatbot_avatar: getVal('chatbot_avatar'),
            chatbot_color: getVal('chatbot_color'),
            chatbot_position: getVal('chatbot_position'),
            chatbot_width: getVal('chatbot_width'),
            chatbot_height: getVal('chatbot_height'),
            chatbot_offline_message: getVal('chatbot_offline_message'),
            // Behavior
            chatbot_enabled: getChecked('chatbot_enabled'),
            chatbot_auto_open_seconds: getVal('chatbot_auto_open_seconds'),
            chatbot_language: getVal('chatbot_language'),
            chatbot_product_cards: getChecked('chatbot_product_cards'),
            chatbot_max_products: getVal('chatbot_max_products'),
            chatbot_quick_replies: getChecked('chatbot_quick_replies'),
            chatbot_typing_indicator: getChecked('chatbot_typing_indicator'),
            chatbot_sound_enabled: getChecked('chatbot_sound_enabled'),
            // Intents
            intent_greeting: getChecked('intent_greeting'),
            intent_farewell: getChecked('intent_farewell'),
            intent_product_inquiry: getChecked('intent_product_inquiry'),
            intent_order_tracking: getChecked('intent_order_tracking'),
            intent_checkout_help: getChecked('intent_checkout_help'),
            intent_return_request: getChecked('intent_return_request'),
            intent_coupon_inquiry: getChecked('intent_coupon_inquiry'),
            intent_size_help: getChecked('intent_size_help'),
            intent_shipping_inquiry: getChecked('intent_shipping_inquiry'),
            intent_add_to_cart: getChecked('intent_add_to_cart'),
            // Advanced
            chatbot_rage_click: getChecked('chatbot_rage_click'),
            chatbot_sentiment_escalation: getChecked('chatbot_sentiment_escalation'),
            chatbot_vip_greeting: getChecked('chatbot_vip_greeting'),
            chatbot_objection_handler: getChecked('chatbot_objection_handler'),
            chatbot_visual_tracking: getChecked('chatbot_visual_tracking'),
            chatbot_order_modification: getChecked('chatbot_order_modification'),
            chatbot_warranty_claims: getChecked('chatbot_warranty_claims'),
            chatbot_multi_sizing: getChecked('chatbot_multi_sizing'),
            // Templates
            tpl_shipping: getVal('tpl_shipping'),
            tpl_returns: getVal('tpl_returns'),
            tpl_no_products: getVal('tpl_no_products'),
            tpl_farewell: getVal('tpl_farewell'),
            // Triggers
            trigger_cart_abandonment: getChecked('trigger_cart_abandonment'),
            trigger_cart_delay: getVal('trigger_cart_delay'),
            trigger_exit_intent: getChecked('trigger_exit_intent'),
            trigger_inactivity: getChecked('trigger_inactivity'),
            trigger_inactivity_delay: getVal('trigger_inactivity_delay'),
            trigger_scroll_depth: getChecked('trigger_scroll_depth'),
            trigger_scroll_percent: getVal('trigger_scroll_percent'),
            trigger_cart_message: getVal('trigger_cart_message'),
            // Escalation
            escalation_enabled: getChecked('escalation_enabled'),
            escalation_email: getVal('escalation_email'),
            sentiment_threshold: getVal('sentiment_threshold'),
            vip_ltv_threshold: getVal('vip_ltv_threshold'),
            max_bot_turns: getVal('max_bot_turns'),
            // Business Hours
            business_hours_enabled: getChecked('business_hours_enabled'),
            business_timezone: getVal('business_timezone'),
            business_hours_start: getVal('business_hours_start'),
            business_hours_end: getVal('business_hours_end'),
            business_days: days.join(','),
            // Keywords
            custom_product_keywords: getVal('custom_product_keywords'),
            // Store Integration
            chatbot_store_url: getVal('chatbot_store_url'),
            chatbot_product_url_pattern: getVal('chatbot_product_url_pattern'),
            chatbot_currency_symbol: getVal('chatbot_currency_symbol'),
            chatbot_free_shipping: getVal('chatbot_free_shipping'),
            chatbot_return_days: getVal('chatbot_return_days'),
            chatbot_warranty_days: getVal('chatbot_warranty_days'),
            // Emergency
            chatbot_maintenance: getChecked('chatbot_maintenance'),
            chatbot_maintenance_message: getVal('chatbot_maintenance_message'),
            chatbot_max_messages: getVal('chatbot_max_messages'),
            chatbot_rate_limit: getVal('chatbot_rate_limit'),
        };

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify(data),
        })
        .then(r => r.json())
        .then(d => {
            const alert = document.getElementById('saveAlert');
            alert.className = d.success ? 'alert alert-success' : 'alert alert-danger';
            alert.textContent = d.message || (d.success ? 'Settings saved.' : 'Error saving.');
            alert.classList.remove('d-none');
            setTimeout(() => alert.classList.add('d-none'), 4000);
        })
        .catch(e => {
            const alert = document.getElementById('saveAlert');
            alert.className = 'alert alert-danger';
            alert.textContent = 'Network error: ' + e.message;
            alert.classList.remove('d-none');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bx bx-save me-1"></i> Save Chatbot Settings';
        });
    }
</script>
@endpush
