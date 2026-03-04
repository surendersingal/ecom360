/**
 * Ecom360 Analytics — Client-Side Tracking SDK v2
 *
 * Full feature parity with Magento tracker.phtml. Includes:
 *   - localStorage event buffer with retry on reload
 *   - sendBeacon transport (non-blocking)
 *   - Exit intent detection (mouse leave, rapid scroll up, idle 60s)
 *   - Rage click detection (3+ clicks in 1.5s within 50px)
 *   - Free shipping progress bar
 *   - Intervention polling (coupon, popup, chat, redirect, notification)
 *   - Scroll depth, engagement time, fingerprint, UTM capture
 *
 * @version 2.0.0
 */
;
(function() {
    'use strict';

    /* ═══════════════════════════ Bootstrap ════════════════════════════ */

    const cfgEl = document.getElementById('ecom360-config');
    if (!cfgEl) return;

    let parsed;
    try { parsed = JSON.parse(cfgEl.textContent); } catch (e) { return; }

    const C = parsed.config || {};
    const events = parsed.events || {};
    const page = parsed.page || {};
    const advanced = parsed.advanced || {};

    if (!C.endpoint || !C.apiKey) return;

    const BASE = C.endpoint.replace(/\/$/, '');
    const COLLECT_URL = BASE + '/api/v1/collect';
    const BATCH_URL = BASE + '/api/v1/collect/batch';
    const POLL_URL = BASE + '/api/v1/interventions/poll';

    /* ═══════════════════════════ Session ══════════════════════════════ */

    const SESSION_KEY = 'ecom360_sid';
    const SESSION_TS_KEY = 'ecom360_sid_ts';
    const SESSION_TIMEOUT = (C.sessionTimeout || 30) * 60 * 1000; // ms

    function getSessionId() {
        let sid = getCookie(SESSION_KEY);
        let ts = parseInt(getCookie(SESSION_TS_KEY) || '0', 10);
        const now = Date.now();

        if (!sid || (now - ts) > SESSION_TIMEOUT) {
            sid = 'js_' + uuid();
            setCookie(SESSION_KEY, sid, 365);
        }
        setCookie(SESSION_TS_KEY, String(now), 365);
        return sid;
    }

    /* ═══════════════════════════ Helpers ══════════════════════════════ */

    function uuid() {
        if (crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    function getCookie(name) {
        const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
    }

    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;expires=' + d.toUTCString() + ';SameSite=Lax';
    }

    /* ═══════════════════════════ UTM ══════════════════════════════════ */

    function getUtm() {
        if (!C.captureUtm) return null;
        const params = new URLSearchParams(location.search);
        const utm = {};
        let found = false;
        ['source', 'medium', 'campaign', 'term', 'content'].forEach(function(k) {
            const v = params.get('utm_' + k);
            if (v) {
                utm[k] = v;
                found = true;
            }
        });
        return found ? utm : null;
    }

    /* ═══════════════════════════ Fingerprint ══════════════════════════ */

    function getFingerprint() {
        if (!C.enableFingerprint) return null;

        // Lightweight canvas + UA fingerprint (non-PII)
        const fp_key = 'ecom360_fp';
        let fp = getCookie(fp_key);
        if (fp) return fp;

        const parts = [
            navigator.userAgent,
            navigator.language,
            screen.colorDepth,
            screen.width + 'x' + screen.height,
            new Date().getTimezoneOffset(),
            navigator.hardwareConcurrency || 0,
            navigator.deviceMemory || 0,
        ];

        // Simple hash
        let hash = 0;
        const str = parts.join('|');
        for (let i = 0; i < str.length; i++) {
            hash = ((hash << 5) - hash + str.charCodeAt(i)) | 0;
        }
        fp = 'fp_' + Math.abs(hash).toString(36);
        setCookie(fp_key, fp, 365);
        return fp;
    }

    /* ═══════════════════════════ localStorage Buffer ══════════════════ */

    const BUFFER_KEY = 'ecom360_event_buffer';
    const BUFFER_MAX_ITEMS = 500;
    const BUFFER_MAX_BYTES = 512 * 1024; // 512KB

    function readBuffer() {
        try {
            const raw = localStorage.getItem(BUFFER_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) { return []; }
    }

    function writeBuffer(items) {
        try {
            let json = JSON.stringify(items);
            if (json.length > BUFFER_MAX_BYTES) {
                items = items.slice(Math.max(0, items.length - BUFFER_MAX_ITEMS));
                json = JSON.stringify(items);
            }
            localStorage.setItem(BUFFER_KEY, json);
        } catch (e) { /* quota exceeded — flush instead */ flush(); }
    }

    function clearBuffer() {
        try { localStorage.removeItem(BUFFER_KEY); } catch (e) {}
    }

    /* ═══════════════════════════ Event Queue ══════════════════════════ */

    let flushTimer = null;

    function buildEvent(eventType, metadata) {
        const evt = {
            event_type: eventType,
            url: page.url || location.href,
            page_title: page.title || document.title,
            session_id: getSessionId(),
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
            language: navigator.language || '',
            screen_resolution: screen.width + 'x' + screen.height,
        };

        if (metadata && Object.keys(metadata).length) {
            evt.metadata = metadata;
        }

        if (page.customer) {
            evt.customer_identifier = page.customer;
        }

        const referrer = C.captureReferrer ? document.referrer : null;
        if (referrer) evt.referrer = referrer;

        const fp = getFingerprint();
        if (fp) evt.device_fingerprint = fp;

        const utm = getUtm();
        if (utm) evt.utm = utm;

        return evt;
    }

    /**
     * Public API: track an event.
     */
    function track(eventType, metadata) {
        const evt = buildEvent(eventType, metadata || {});

        if (C.batchEvents) {
            const buffer = readBuffer();
            buffer.push(evt);
            if (buffer.length > BUFFER_MAX_ITEMS) {
                buffer.splice(0, buffer.length - BUFFER_MAX_ITEMS);
            }
            writeBuffer(buffer);

            if (buffer.length >= (C.batchSize || 10)) {
                flush();
            } else if (!flushTimer) {
                flushTimer = setTimeout(flush, C.flushInterval || 5000);
            }
        } else {
            sendSingle(evt);
        }
    }

    function flush() {
        if (flushTimer) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }
        const buffer = readBuffer();
        if (!buffer.length) return;

        const batch = buffer.splice(0, 50); // API max = 50
        writeBuffer(buffer);
        sendBatch(batch);
    }

    /* ═══════════════════════════ Transport ════════════════════════════ */

    function sendSingle(evt) {
        const body = JSON.stringify(evt);
        if (navigator.sendBeacon) {
            const blob = new Blob([body], { type: 'application/json' });
            // sendBeacon can't set custom headers, fall back to fetch
        }
        fetch(COLLECT_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Ecom360-Key': C.apiKey,
            },
            body: body,
            keepalive: true,
        }).catch(function() {});
    }

    function sendBatch(items) {
        const body = JSON.stringify({ events: items });
        fetch(BATCH_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Ecom360-Key': C.apiKey,
            },
            body: body,
            keepalive: true,
        }).catch(function() {});
    }

    /**
     * Use sendBeacon for unload – falls back to query-param auth since
     * sendBeacon cannot set custom headers.
     */
    function sendBeaconSingle(evt) {
        const url = COLLECT_URL + '?api_key=' + encodeURIComponent(C.apiKey);
        const blob = new Blob([JSON.stringify(evt)], { type: 'application/json' });
        navigator.sendBeacon(url, blob);
    }

    function sendBeaconBatch(items) {
        const url = BATCH_URL + '?api_key=' + encodeURIComponent(C.apiKey);
        const blob = new Blob([JSON.stringify({ events: items })], { type: 'application/json' });
        navigator.sendBeacon(url, blob);
    }

    /* ═══════════════════════════ Auto-track: Page View ════════════════ */

    if (events.pageViews) {
        track('page_view', {
            page_type: page.type || 'page',
            category: page.category || null,
        });
    }

    /* ═══════════════════════════ Auto-track: Product View ═════════════ */

    if (events.products && page.type === 'product' && page.product) {
        track('product_view', page.product);
    }

    /* ═══════════════════════════ Auto-track: Search ═══════════════════ */

    if (events.search) {
        var sp = new URLSearchParams(location.search);
        var searchQuery = sp.get('s') || sp.get('q') || sp.get('search') || sp.get('dgwt_wcas');
        if (searchQuery) {
            track('search', { query: searchQuery });
        }
    }

    /* ═══════════════════════════ Scroll Depth ═════════════════════════ */

    (function() {
        var maxScroll = 0;
        var milestones = [25, 50, 75, 100];
        var reported = {};

        window.addEventListener('scroll', function() {
            var docHeight = Math.max(
                document.body.scrollHeight,
                document.documentElement.scrollHeight
            ) - window.innerHeight;
            if (docHeight <= 0) return;

            var pct = Math.round((window.scrollY / docHeight) * 100);
            if (pct > maxScroll) maxScroll = pct;

            milestones.forEach(function(m) {
                if (pct >= m && !reported[m]) {
                    reported[m] = true;
                    track('scroll_depth', { percent: m });
                }
            });
        }, { passive: true });
    })();

    /* ═══════════════════════════ Engagement Time ══════════════════════ */

    (function() {
        var startTime = Date.now();
        var engaged = 0;
        var active = true;

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (active) engaged += Date.now() - startTime;
                active = false;
            } else {
                startTime = Date.now();
                active = true;
            }
        });

        // On unload, send engagement + flush buffer
        function onUnload() {
            if (active) engaged += Date.now() - startTime;

            var engagementEvt = buildEvent('engagement_time', {
                seconds: Math.round(engaged / 1000),
            });

            // Flush remaining buffer + engagement via sendBeacon
            var buffer = readBuffer();
            buffer.push(engagementEvt);
            if (buffer.length) {
                sendBeaconBatch(buffer.splice(0, 50));
                clearBuffer();
            }
        }

        window.addEventListener('pagehide', onUnload);
        // Fallback for older browsers
        window.addEventListener('beforeunload', onUnload);
    })();

    /* ═══════════════════════════ Exit Intent Detection ═══════════════ */

    if (advanced.exitIntent) {
        (function() {
            var exitFired = false;
            var exitCallbacks = [];

            function fireExitIntent(trigger) {
                if (exitFired) return;
                exitFired = true;
                track('exit_intent', { trigger: trigger });
                exitCallbacks.forEach(function(cb) { try { cb(trigger); } catch (e) {} });
            }

            // 1. Mouse leave (cursor exits above viewport)
            document.addEventListener('mouseout', function(e) {
                if (e.clientY < 10) fireExitIntent('mouse_leave');
            });

            // 2. Rapid scroll up
            (function() {
                var scrollSamples = [];
                window.addEventListener('scroll', function() {
                    scrollSamples.push(window.scrollY);
                    if (scrollSamples.length > 3) scrollSamples.shift();
                    if (scrollSamples.length === 3) {
                        var allDown = scrollSamples[0] > scrollSamples[1] && scrollSamples[1] > scrollSamples[2];
                        if (allDown && scrollSamples[0] > 300 && scrollSamples[2] < 100) {
                            fireExitIntent('rapid_scroll_up');
                        }
                    }
                }, { passive: true });
            })();

            // 3. Idle 60 seconds
            (function() {
                var idleTimer = null;

                function resetIdle() {
                    clearTimeout(idleTimer);
                    idleTimer = setTimeout(function() { fireExitIntent('idle_60s'); }, 60000);
                }
                ['mousemove', 'keydown', 'touchstart', 'scroll'].forEach(function(evt) {
                    document.addEventListener(evt, resetIdle, { passive: true });
                });
                resetIdle();
            })();

            // Expose callback registration
            window.ecom360 = window.ecom360 || {};
            window.ecom360.onExitIntent = function(cb) {
                if (typeof cb === 'function') exitCallbacks.push(cb);
            };
        })();
    }

    /* ═══════════════════════════ Rage Click Detection ════════════════ */

    if (advanced.rageClick) {
        (function() {
            var clicks = [];
            var reportedElements = {};

            function buildSelector(el) {
                if (!el || el === document.body) return 'body';
                var parts = [];
                var current = el;
                for (var i = 0; i < 3 && current && current !== document.body; i++) {
                    var tag = current.tagName.toLowerCase();
                    if (current.id) { parts.unshift(tag + '#' + current.id); break; }
                    if (current.className && typeof current.className === 'string') {
                        tag += '.' + current.className.trim().split(/\s+/).join('.');
                    }
                    parts.unshift(tag);
                    current = current.parentElement;
                }
                return parts.join(' > ');
            }

            document.addEventListener('click', function(e) {
                var now = Date.now();
                clicks.push({ x: e.clientX, y: e.clientY, t: now });
                // Keep only recent 2s of clicks
                clicks = clicks.filter(function(c) { return now - c.t < 2000; });

                // Check for 3+ clicks in 1.5s within a 50px radius
                var recent = clicks.filter(function(c) { return now - c.t <= 1500; });
                if (recent.length >= 3) {
                    var minX = Infinity,
                        maxX = -Infinity,
                        minY = Infinity,
                        maxY = -Infinity;
                    recent.forEach(function(c) {
                        if (c.x < minX) minX = c.x;
                        if (c.x > maxX) maxX = c.x;
                        if (c.y < minY) minY = c.y;
                        if (c.y > maxY) maxY = c.y;
                    });

                    if ((maxX - minX) <= 100 && (maxY - minY) <= 100) {
                        var selector = buildSelector(e.target);
                        if (!reportedElements[selector]) {
                            reportedElements[selector] = true;
                            track('rage_click', {
                                element: selector,
                                x: e.clientX,
                                y: e.clientY,
                                clicks: recent.length,
                                page_url: location.href,
                            });
                            // Trigger chatbot support if available
                            if (window.ecom360OpenChatbot) {
                                window.ecom360OpenChatbot('I noticed you might be having trouble. Can I help?');
                            }
                        }
                    }
                }
            });
        })();
    }

    /* ═══════════════════════════ Free Shipping Bar ═══════════════════ */

    if (advanced.freeShippingBar) {
        (function() {
            var threshold = advanced.freeShippingThreshold || 50;
            var currency = advanced.freeShippingCurrency || '$';
            var qualified = false;

            var bar = document.createElement('div');
            bar.id = 'ecom360-shipping-bar';
            bar.style.cssText = 'position:fixed;bottom:0;left:0;width:100%;z-index:99998;background:linear-gradient(90deg,#4f46e5,#7c3aed);color:#fff;padding:10px 20px;text-align:center;font-size:14px;font-weight:500;transition:transform .3s';
            bar.innerHTML = '<span id="ecom360-shipping-text"></span>' +
                '<div style="background:rgba(255,255,255,.3);height:4px;border-radius:2px;margin-top:6px">' +
                '<div id="ecom360-shipping-fill" style="height:100%;background:#fbbf24;border-radius:2px;transition:width .5s;width:0%"></div></div>';
            document.body.appendChild(bar);

            var textEl = document.getElementById('ecom360-shipping-text');
            var fillEl = document.getElementById('ecom360-shipping-fill');

            function updateBar(cartTotal) {
                if (typeof cartTotal !== 'number' || cartTotal < 0) cartTotal = 0;
                var remaining = threshold - cartTotal;
                var pct = Math.min(100, (cartTotal / threshold) * 100);
                fillEl.style.width = pct + '%';

                if (remaining <= 0) {
                    textEl.textContent = '\uD83C\uDF89 You qualify for FREE shipping!';
                    if (!qualified) {
                        qualified = true;
                        track('free_shipping_qualified', { cart_total: cartTotal, threshold: threshold });
                    }
                } else {
                    textEl.textContent = 'Add ' + currency + remaining.toFixed(2) + ' more for FREE shipping!';
                }
            }

            // Poll WooCommerce cart total from DOM
            function pollCartTotal() {
                try {
                    var el = document.querySelector('.cart-contents .woocommerce-Price-amount, .cart_totals .order-total .woocommerce-Price-amount');
                    if (el) {
                        var val = parseFloat(el.textContent.replace(/[^0-9.]/g, ''));
                        if (!isNaN(val)) updateBar(val);
                    }
                } catch (e) {}
            }

            setInterval(pollCartTotal, 3000);
            pollCartTotal();

            window.ecom360 = window.ecom360 || {};
            window.ecom360.updateCartTotal = updateBar;
        })();
    }

    /* ═══════════════════════════ Intervention Polling ════════════════ */

    if (advanced.interventions) {
        (function() {
            var pollInterval = (advanced.interventionsPollInterval || 15) * 1000;
            var currentSessionId = getSessionId();

            function pollInterventions() {
                fetch(POLL_URL + '?session_id=' + encodeURIComponent(currentSessionId), {
                    headers: { 'X-Ecom360-Key': C.apiKey },
                }).then(function(r) { return r.json(); }).then(function(res) {
                    var items = res.data || res.interventions || [];
                    if (Array.isArray(items)) items.forEach(executeIntervention);
                }).catch(function() {});
            }

            function executeIntervention(item) {
                var type = item.type || item.action;
                track('intervention_received', { type: type });

                switch (type) {
                    case 'popup':
                        showDynamicPopup(item);
                        break;
                    case 'coupon':
                        showCouponPopup(item);
                        break;
                    case 'chat':
                        if (window.ecom360OpenChatbot) window.ecom360OpenChatbot(item.message || 'Looking for help?');
                        break;
                    case 'redirect':
                        if (item.url) window.location.href = item.url;
                        break;
                    case 'notification':
                        showToast(item.message || item.title || 'New notification');
                        break;
                }
            }

            function showDynamicPopup(item) {
                var ol = document.createElement('div');
                ol.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:200001;display:flex;align-items:center;justify-content:center';
                var bx = document.createElement('div');
                bx.style.cssText = 'background:#fff;border-radius:16px;padding:32px;max-width:400px;text-align:center;position:relative';
                bx.innerHTML = '<button style="position:absolute;top:8px;right:12px;background:none;border:none;font-size:24px;cursor:pointer">&times;</button>' +
                    '<h3 style="margin:0 0 8px">' + (item.title || '') + '</h3>' +
                    '<p style="color:#666;margin:0 0 16px">' + (item.message || item.description || '') + '</p>' +
                    (item.cta_url ? '<a href="' + item.cta_url + '" style="display:inline-block;background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600">' + (item.cta_text || 'Learn More') + '</a>' : '');
                ol.appendChild(bx);
                document.body.appendChild(ol);
                ol.addEventListener('click', function(e) { if (e.target === ol) ol.remove(); });
                bx.querySelector('button').addEventListener('click', function() { ol.remove(); });
            }

            function showCouponPopup(item) {
                var ol = document.createElement('div');
                ol.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:200001;display:flex;align-items:center;justify-content:center';
                var bx = document.createElement('div');
                bx.style.cssText = 'background:#fff;border-radius:16px;padding:32px;max-width:400px;text-align:center;position:relative';
                bx.innerHTML = '<button style="position:absolute;top:8px;right:12px;background:none;border:none;font-size:24px;cursor:pointer">&times;</button>' +
                    '<h3 style="margin:0 0 8px">\uD83C\uDF81 Special Offer!</h3>' +
                    '<p style="color:#666;margin:0 0 12px">' + (item.message || 'Use this code for a discount') + '</p>' +
                    '<div style="background:#eef2ff;padding:16px;border-radius:8px;font-size:24px;font-weight:700;color:#4f46e5;letter-spacing:2px;margin:0 0 16px">' + (item.coupon_code || item.code || '') + '</div>' +
                    '<button class="e360-apply" style="background:#4f46e5;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-weight:600;cursor:pointer">Apply to Cart</button>';
                ol.appendChild(bx);
                document.body.appendChild(ol);
                ol.addEventListener('click', function(e) { if (e.target === ol) ol.remove(); });
                bx.querySelector('button').addEventListener('click', function() { ol.remove(); });
                bx.querySelector('.e360-apply').addEventListener('click', function() {
                    track('coupon_applied', { code: item.coupon_code || item.code });
                    ol.remove();
                });
            }

            function showToast(msg) {
                var t = document.createElement('div');
                t.style.cssText = 'position:fixed;top:20px;right:20px;z-index:200002;background:#111;color:#fff;padding:14px 20px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.3)';
                t.textContent = msg;
                document.body.appendChild(t);
                setTimeout(function() { t.remove(); }, 5000);
            }

            // Start polling after initial 5s delay
            setTimeout(function() {
                pollInterventions();
                setInterval(pollInterventions, pollInterval);
            }, 5000);
        })();
    }

    /* ═══════════════════════════ Retry Leftover Buffer ═══════════════ */

    (function() {
        var leftover = readBuffer();
        if (leftover.length > 0 && C.batchEvents) {
            setTimeout(function() {
                var batch = leftover.splice(0, 50);
                writeBuffer(leftover);
                sendBatch(batch);
            }, 1000);
        }
    })();

    /* ═══════════════════════════ Public API ═══════════════════════════ */

    // Expose for use by ecom360-wc.js and custom integrations
    window.ecom360 = window.ecom360 || {};
    window.ecom360.track = track;
    window.ecom360.flush = flush;
    window.ecom360.getSessionId = getSessionId;
    window.ecom360.buildEvent = buildEvent;

})();