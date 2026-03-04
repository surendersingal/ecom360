<?php
/**
 * AI Chatbot Widget — renders floating chatbot on frontend.
 *
 * Features: FAB button, sliding chat panel, message send/receive,
 * typing indicator, quick replies, rage-click trigger.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Chatbot {

    /** @var array<string, mixed> */
    private $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    public function is_enabled(): bool {
        return ! empty( $this->settings['chatbot_enabled'] )
            && ! empty( $this->settings['endpoint'] )
            && ! empty( $this->settings['api_key'] );
    }

    public function enqueue(): void {
        if ( ! $this->is_enabled() ) return;
        add_action( 'wp_footer', [ $this, 'render' ], 100 );
    }

    public function render(): void {
        $s = $this->settings;
        $endpoint = rtrim( $s['endpoint'], '/' );
        $position = $s['chatbot_position'] ?? 'bottom-right';
        $greeting = esc_html( $s['chatbot_greeting'] ?? 'Hi! How can I help you today?' );

        $config = wp_json_encode( [
            'endpoint' => $endpoint,
            'apiKey'   => $s['api_key'],
            'position' => $position,
            'greeting' => $s['chatbot_greeting'] ?? 'Hi! How can I help you today?',
        ], JSON_UNESCAPED_SLASHES );
        ?>
        <!-- Ecom360 AI Chatbot -->
        <style>
        .ecom360-chat-fab{position:fixed;z-index:99999;width:60px;height:60px;border-radius:50%;background:#4f46e5;color:#fff;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;font-size:28px;transition:transform .2s}
        .ecom360-chat-fab:hover{transform:scale(1.1)}
        .ecom360-chat-fab.bottom-right{bottom:24px;right:24px}
        .ecom360-chat-fab.bottom-left{bottom:24px;left:24px}
        .ecom360-chat-panel{position:fixed;z-index:100000;width:380px;height:520px;background:#fff;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.2);display:none;flex-direction:column;overflow:hidden}
        .ecom360-chat-panel.bottom-right{bottom:96px;right:24px}
        .ecom360-chat-panel.bottom-left{bottom:96px;left:24px}
        .ecom360-chat-panel.open{display:flex}
        .ecom360-chat-header{background:#4f46e5;color:#fff;padding:16px;display:flex;align-items:center;justify-content:space-between}
        .ecom360-chat-header h4{margin:0;font-size:16px;font-weight:600}
        .ecom360-chat-close{background:none;border:none;color:#fff;font-size:24px;cursor:pointer;padding:0;line-height:1}
        .ecom360-chat-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px}
        .ecom360-chat-msg{max-width:80%;padding:10px 14px;border-radius:12px;font-size:14px;line-height:1.4;word-wrap:break-word}
        .ecom360-chat-msg.bot{background:#f3f4f6;color:#111;align-self:flex-start;border-bottom-left-radius:4px}
        .ecom360-chat-msg.user{background:#4f46e5;color:#fff;align-self:flex-end;border-bottom-right-radius:4px}
        .ecom360-chat-msg.typing{background:#f3f4f6;align-self:flex-start;border-bottom-left-radius:4px}
        .ecom360-chat-msg.typing span{display:inline-block;width:8px;height:8px;border-radius:50%;background:#999;margin:0 2px;animation:ecom360-bounce .6s infinite alternate}
        .ecom360-chat-msg.typing span:nth-child(2){animation-delay:.2s}
        .ecom360-chat-msg.typing span:nth-child(3){animation-delay:.4s}
        @keyframes ecom360-bounce{to{transform:translateY(-6px);opacity:.4}}
        .ecom360-chat-quick-replies{display:flex;flex-wrap:wrap;gap:6px;padding:0 16px 8px}
        .ecom360-chat-quick-btn{background:#eef2ff;color:#4f46e5;border:1px solid #c7d2fe;border-radius:20px;padding:6px 14px;font-size:13px;cursor:pointer;white-space:nowrap}
        .ecom360-chat-quick-btn:hover{background:#c7d2fe}
        .ecom360-chat-input-row{display:flex;border-top:1px solid #e5e7eb;padding:8px}
        .ecom360-chat-input{flex:1;border:none;outline:none;padding:8px 12px;font-size:14px}
        .ecom360-chat-send{background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:8px 16px;cursor:pointer;font-size:14px}
        .ecom360-chat-send:hover{background:#4338ca}
        .ecom360-chat-product{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-top:4px;display:flex;gap:10px;align-items:center;max-width:90%;align-self:flex-start}
        .ecom360-chat-product img{width:60px;height:60px;object-fit:cover;border-radius:6px}
        .ecom360-chat-product-info{flex:1;font-size:13px}
        .ecom360-chat-product-info .name{font-weight:600;color:#111}
        .ecom360-chat-product-info .price{color:#4f46e5;font-weight:600}
        .ecom360-chat-product-info a{color:#4f46e5;text-decoration:none;font-size:12px}
        </style>

        <button id="ecom360-chat-fab" class="ecom360-chat-fab <?php echo esc_attr($position); ?>" aria-label="Chat">💬</button>

        <div id="ecom360-chat-panel" class="ecom360-chat-panel <?php echo esc_attr($position); ?>">
            <div class="ecom360-chat-header">
                <h4>Ecom360 Assistant</h4>
                <button class="ecom360-chat-close" aria-label="Close">&times;</button>
            </div>
            <div id="ecom360-chat-messages" class="ecom360-chat-messages"></div>
            <div id="ecom360-chat-quick" class="ecom360-chat-quick-replies"></div>
            <div class="ecom360-chat-input-row">
                <input id="ecom360-chat-input" class="ecom360-chat-input" type="text" placeholder="Type a message..." autocomplete="off" />
                <button id="ecom360-chat-send" class="ecom360-chat-send">Send</button>
            </div>
        </div>

        <script>
        (function(){
            'use strict';
            var CFG = <?php echo $config; ?>;
            var fab = document.getElementById('ecom360-chat-fab');
            var panel = document.getElementById('ecom360-chat-panel');
            var msgs = document.getElementById('ecom360-chat-messages');
            var input = document.getElementById('ecom360-chat-input');
            var sendBtn = document.getElementById('ecom360-chat-send');
            var quickContainer = document.getElementById('ecom360-chat-quick');
            var closeBtn = panel.querySelector('.ecom360-chat-close');
            var conversationId = null;
            var sessionId = (window.ecom360 && window.ecom360.getSessionId) ? window.ecom360.getSessionId() : 'anon_' + Date.now();

            function addMsg(text, type, html) {
                var div = document.createElement('div');
                div.className = 'ecom360-chat-msg ' + type;
                if (html) div.innerHTML = text; else div.textContent = text;
                msgs.appendChild(div);
                msgs.scrollTop = msgs.scrollHeight;
                return div;
            }

            function addProducts(products) {
                products.forEach(function(p) {
                    var div = document.createElement('div');
                    div.className = 'ecom360-chat-product';
                    div.innerHTML = (p.image ? '<img src="'+p.image+'" alt="'+p.name+'">' : '') +
                        '<div class="ecom360-chat-product-info"><div class="name">'+p.name+'</div>' +
                        (p.price ? '<div class="price">'+p.price+'</div>' : '') +
                        (p.url ? '<a href="'+p.url+'" target="_blank">View Product →</a>' : '') +
                        '</div>';
                    msgs.appendChild(div);
                });
                msgs.scrollTop = msgs.scrollHeight;
            }

            function showTyping() {
                var div = document.createElement('div');
                div.className = 'ecom360-chat-msg typing';
                div.id = 'ecom360-typing';
                div.innerHTML = '<span></span><span></span><span></span>';
                msgs.appendChild(div);
                msgs.scrollTop = msgs.scrollHeight;
            }

            function hideTyping() {
                var el = document.getElementById('ecom360-typing');
                if (el) el.remove();
            }

            function setQuickReplies(replies) {
                quickContainer.innerHTML = '';
                if (!replies || !replies.length) return;
                replies.forEach(function(r) {
                    var btn = document.createElement('button');
                    btn.className = 'ecom360-chat-quick-btn';
                    btn.textContent = r;
                    btn.addEventListener('click', function() { sendMessage(r); });
                    quickContainer.appendChild(btn);
                });
            }

            function sendMessage(text) {
                if (!text.trim()) return;
                addMsg(text, 'user');
                input.value = '';
                quickContainer.innerHTML = '';
                showTyping();

                var body = {
                    message: text,
                    session_id: sessionId,
                    conversation_id: conversationId,
                    page_url: location.href,
                    page_title: document.title,
                };

                fetch(CFG.endpoint + '/api/v1/chatbot/send', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Ecom360-Key': CFG.apiKey,
                    },
                    body: JSON.stringify(body),
                }).then(function(r) { return r.json(); }).then(function(res) {
                    hideTyping();
                    if (res.success && res.data) {
                        var d = res.data;
                        conversationId = d.conversation_id || conversationId;
                        addMsg(d.response || d.message || 'No response', 'bot');
                        if (d.products && d.products.length) addProducts(d.products);
                        if (d.quick_replies) setQuickReplies(d.quick_replies);
                    } else {
                        addMsg(res.message || 'Sorry, something went wrong.', 'bot');
                    }
                }).catch(function() {
                    hideTyping();
                    addMsg('Connection error. Please try again.', 'bot');
                });

                if (window.ecom360) window.ecom360.track('chatbot_message_sent', {message: text.substring(0,100)});
            }

            // Toggle
            fab.addEventListener('click', function() {
                panel.classList.toggle('open');
                if (panel.classList.contains('open')) {
                    if (!msgs.children.length) addMsg(CFG.greeting, 'bot');
                    input.focus();
                    if (window.ecom360) window.ecom360.track('chatbot_opened', {});
                }
            });
            closeBtn.addEventListener('click', function() {
                panel.classList.remove('open');
            });

            sendBtn.addEventListener('click', function() { sendMessage(input.value); });
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); sendMessage(input.value); }
            });

            // Global API for rage-click trigger
            window.ecom360OpenChatbot = function(msg) {
                panel.classList.add('open');
                if (!msgs.children.length) addMsg(CFG.greeting, 'bot');
                if (msg) { setTimeout(function() { sendMessage(msg); }, 500); }
            };
        })();
        </script>
        <?php
    }
}
