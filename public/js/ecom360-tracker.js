/**
 * ═══════════════════════════════════════════════════════════════════════
 *  Ecom360 Analytics — Store JavaScript SDK  v1.0.0
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  Enterprise-grade client-side analytics tracker for ecommerce stores.
 *
 *  Usage:
 *    <script src="https://your-server/js/ecom360-tracker.js"></script>
 *    <script>
 *      Ecom360.init({
 *        apiKey:    'ek_xxxxxxxxxxxx',
 *        endpoint:  'https://your-server/api/v1',
 *        debug:     true
 *      });
 *    </script>
 *
 *  Features:
 *    - Automatic page view tracking
 *    - Session management (cookie-based, 30-min inactivity timeout)
 *    - Device fingerprinting (canvas + WebGL + screen + timezone)
 *    - UTM parameter capture & attribution
 *    - Ecommerce event helpers (product_view, add_to_cart, purchase, etc.)
 *    - Identity resolution (email / phone)
 *    - Scroll depth tracking (25/50/75/100%)
 *    - Time on page tracking
 *    - Batched event queue with automatic flush & retry
 *    - Error boundary (never breaks the host page)
 *    - GDPR consent gating
 *    - Real-time behavioral intervention listener (WebSocket/polling)
 *
 *  Copyright (c) 2026 Ecom360 Platform.
 * ═══════════════════════════════════════════════════════════════════════
 */
(function(window, document) {
    'use strict';

    // Prevent double-init.
    if (window.Ecom360 && window.Ecom360._initialized) return;

    // ──────────────────────────────────────────────────────────────────────
    //  Constants
    // ──────────────────────────────────────────────────────────────────────
    var VERSION = '1.0.0';
    var SESSION_COOKIE = '_e360_sid';
    var VISITOR_COOKIE = '_e360_vid';
    var CONSENT_COOKIE = '_e360_consent';
    var SESSION_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
    var BATCH_INTERVAL_MS = 3000; // Flush queue every 3 seconds
    var MAX_BATCH_SIZE = 25; // Max events per batch POST
    var MAX_QUEUE_SIZE = 200; // Drop oldest if exceeded
    var MAX_RETRIES = 3;
    var RETRY_DELAY_MS = 2000;

    // Scroll depth thresholds (track once per threshold per page).
    var SCROLL_THRESHOLDS = [25, 50, 75, 100];

    // ──────────────────────────────────────────────────────────────────────
    //  State
    // ──────────────────────────────────────────────────────────────────────
    var config = {};
    var sessionId = null;
    var visitorId = null;
    var eventQueue = [];
    var flushTimer = null;
    var pageLoadTime = Date.now();
    var scrollTracked = {};
    var lastActivityTs = Date.now();
    var customerIdentifier = null;
    var consentGranted = true; // Default to true; set false if consent required
    var debugCallbacks = [];
    var interventionCallbacks = [];
    var interventionPollTimer = null;

    // ──────────────────────────────────────────────────────────────────────
    //  Utility Helpers
    // ──────────────────────────────────────────────────────────────────────

    function log() {
        if (config.debug && window.console && console.log) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[Ecom360]');
            console.log.apply(console, args);
            // Notify debug callbacks.
            for (var i = 0; i < debugCallbacks.length; i++) {
                try { debugCallbacks[i](args.join(' ')); } catch (e) {}
            }
        }
    }

    function warn() {
        if (window.console && console.warn) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[Ecom360]');
            console.warn.apply(console, args);
        }
    }

    /**
     * Generate a UUID v4.
     */
    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    /**
     * Cookie management.
     */
    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + (days || 365) * 86400000);
        var sameSite = config.secureCookies ? '; SameSite=None; Secure' : '; SameSite=Lax';
        document.cookie = name + '=' + encodeURIComponent(value) +
            '; expires=' + d.toUTCString() +
            '; path=/' + sameSite;
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function deleteCookie(name) {
        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    }

    /**
     * Parse URL query parameters.
     */
    function getQueryParams() {
        var params = {};
        var search = window.location.search.substring(1);
        if (!search) return params;
        var pairs = search.split('&');
        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].split('=');
            params[decodeURIComponent(pair[0])] = pair[1] ? decodeURIComponent(pair[1]) : '';
        }
        return params;
    }

    /**
     * Extract UTM parameters from the URL.
     */
    function captureUtm() {
        var params = getQueryParams();
        var utm = {};
        var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        var hasUtm = false;
        for (var i = 0; i < keys.length; i++) {
            if (params[keys[i]]) {
                utm[keys[i].replace('utm_', '')] = params[keys[i]];
                hasUtm = true;
            }
        }
        return hasUtm ? utm : null;
    }

    /**
     * Basic device fingerprint (canvas + screen + timezone + plugins).
     * This is NOT as robust as FingerprintJS Pro, but provides a
     * reasonable signal for anonymous user recognition.
     */
    function generateFingerprint() {
        try {
            var components = [];

            // Screen dimensions.
            components.push(screen.width + 'x' + screen.height + 'x' + screen.colorDepth);

            // Timezone offset.
            components.push(new Date().getTimezoneOffset());

            // Language.
            components.push(navigator.language || navigator.userLanguage || '');

            // Platform.
            components.push(navigator.platform || '');

            // Hardware concurrency.
            components.push(navigator.hardwareConcurrency || 0);

            // Canvas fingerprint.
            try {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                if (ctx) {
                    canvas.width = 200;
                    canvas.height = 50;
                    ctx.textBaseline = 'top';
                    ctx.font = '14px Arial';
                    ctx.fillStyle = '#f60';
                    ctx.fillRect(0, 0, 200, 50);
                    ctx.fillStyle = '#069';
                    ctx.fillText('Ecom360fp', 2, 15);
                    ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                    ctx.fillText('Ecom360fp', 4, 17);
                    components.push(canvas.toDataURL());
                }
            } catch (e) {
                components.push('canvas-blocked');
            }

            // WebGL renderer.
            try {
                var gl = document.createElement('canvas').getContext('webgl');
                if (gl) {
                    var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                    if (debugInfo) {
                        components.push(gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL));
                    }
                }
            } catch (e) {
                components.push('webgl-blocked');
            }

            // Hash the components (simple djb2).
            var str = components.join('|||');
            var hash = 5381;
            for (var i = 0; i < str.length; i++) {
                hash = ((hash << 5) + hash) + str.charCodeAt(i);
                hash = hash & hash; // Convert to 32bit integer.
            }

            // Convert to hex string, then SHA-like suffix for consistency.
            return 'fp_' + Math.abs(hash).toString(16).padStart(8, '0') + '_' + str.length.toString(16);
        } catch (e) {
            return null;
        }
    }

    /**
     * Get or create the scroll depth measurement for CURRENT page.
     */
    function getMaxScrollPercent() {
        var docHeight = Math.max(
            document.body.scrollHeight || 0,
            document.documentElement.scrollHeight || 0,
            document.body.offsetHeight || 0,
            document.documentElement.offsetHeight || 0,
            document.body.clientHeight || 0,
            document.documentElement.clientHeight || 0
        );
        var winHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;

        if (docHeight <= winHeight) return 100;
        return Math.min(100, Math.round(((scrollTop + winHeight) / docHeight) * 100));
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Session Management
    // ──────────────────────────────────────────────────────────────────────

    function initSession() {
        // Visitor ID persists across sessions (1 year cookie).
        visitorId = getCookie(VISITOR_COOKIE);
        if (!visitorId) {
            visitorId = 'v_' + uuid();
            setCookie(VISITOR_COOKIE, visitorId, 365);
            log('New visitor:', visitorId);
        }

        // Session ID expires after inactivity (session cookie + JS timeout).
        sessionId = getCookie(SESSION_COOKIE);
        if (!sessionId) {
            sessionId = 's_' + uuid();
            setCookie(SESSION_COOKIE, sessionId, 0); // Session cookie (expires on browser close).
            log('New session:', sessionId);
        } else {
            log('Resumed session:', sessionId);
        }

        // Touch the session timestamp.
        lastActivityTs = Date.now();
    }

    function touchSession() {
        var now = Date.now();
        // If inactive for > 30 min, start new session.
        if (now - lastActivityTs > SESSION_TIMEOUT_MS) {
            sessionId = 's_' + uuid();
            setCookie(SESSION_COOKIE, sessionId, 0);
            scrollTracked = {};
            pageLoadTime = now;
            log('Session expired, new session:', sessionId);
        }
        lastActivityTs = now;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Event Queue & Transport
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build the common event envelope.
     */
    function buildEvent(eventType, metadata, customData) {
        touchSession();

        var event = {
            session_id: sessionId,
            event_type: eventType,
            url: window.location.href,
            metadata: metadata || {},
            custom_data: customData || {},
            referrer: document.referrer || null,
            page_title: document.title || null,
            screen_resolution: screen.width + 'x' + screen.height,
            timezone: Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : null,
            language: navigator.language || null,
            timestamp: new Date().toISOString()
        };

        // Attach device fingerprint if available.
        var fp = generateFingerprint();
        if (fp) event.device_fingerprint = fp;

        // Attach customer identity if known.
        if (customerIdentifier) {
            event.customer_identifier = customerIdentifier;
        }

        // Attach UTM on first event of session.
        var utm = captureUtm();
        if (utm) event.utm = utm;

        return event;
    }

    /**
     * Queue an event for batched sending.
     */
    function enqueue(event) {
        if (!consentGranted) {
            log('Consent not granted, dropping event:', event.event_type);
            return;
        }

        eventQueue.push(event);

        // Protect against runaway queues.
        if (eventQueue.length > MAX_QUEUE_SIZE) {
            var dropped = eventQueue.length - MAX_QUEUE_SIZE;
            eventQueue = eventQueue.slice(-MAX_QUEUE_SIZE);
            warn('Queue overflow, dropped', dropped, 'oldest events');
        }

        log('Queued:', event.event_type, '| Queue size:', eventQueue.length);

        // If queue is full, flush immediately.
        if (eventQueue.length >= MAX_BATCH_SIZE) {
            flush();
        }
    }

    /**
     * Flush all queued events to the server.
     */
    function flush() {
        if (eventQueue.length === 0) return;

        var batch = eventQueue.splice(0, MAX_BATCH_SIZE);
        log('Flushing', batch.length, 'events');

        sendBatch(batch, 0);
    }

    /**
     * Send a batch of events via POST.
     * Falls back to single-event endpoint if batch fails.
     */
    function sendBatch(batch, retryCount) {
        var endpoint = config.endpoint + '/collect/batch';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Ecom360-Key', config.apiKey);
        xhr.timeout = 10000;

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;

            if (xhr.status >= 200 && xhr.status < 300) {
                log('Batch sent successfully (' + batch.length + ' events)');
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.data && resp.data.errors && resp.data.errors.length > 0) {
                        warn('Partial batch errors:', resp.data.errors);
                    }
                } catch (e) {}
            } else if (xhr.status === 429) {
                // Rate limited — back off and retry.
                warn('Rate limited, backing off...');
                if (retryCount < MAX_RETRIES) {
                    setTimeout(function() {
                        sendBatch(batch, retryCount + 1);
                    }, RETRY_DELAY_MS * Math.pow(2, retryCount));
                } else {
                    warn('Max retries reached, dropping', batch.length, 'events');
                }
            } else if (xhr.status === 0 || xhr.status >= 500) {
                // Network error or server error — retry with backoff.
                if (retryCount < MAX_RETRIES) {
                    log('Send failed (status=' + xhr.status + '), retry', retryCount + 1);
                    setTimeout(function() {
                        sendBatch(batch, retryCount + 1);
                    }, RETRY_DELAY_MS * Math.pow(2, retryCount));
                } else {
                    warn('Max retries reached, dropping', batch.length, 'events');
                }
            } else {
                // 4xx client error — don't retry, log it.
                warn('Client error', xhr.status, xhr.responseText);
            }
        };

        xhr.onerror = function() {
            if (retryCount < MAX_RETRIES) {
                setTimeout(function() {
                    sendBatch(batch, retryCount + 1);
                }, RETRY_DELAY_MS * Math.pow(2, retryCount));
            }
        };

        xhr.ontimeout = function() {
            if (retryCount < MAX_RETRIES) {
                setTimeout(function() {
                    sendBatch(batch, retryCount + 1);
                }, RETRY_DELAY_MS * Math.pow(2, retryCount));
            }
        };

        try {
            xhr.send(JSON.stringify({ events: batch }));
        } catch (e) {
            warn('XHR send error:', e.message);
        }
    }

    /**
     * Send a single event immediately (for critical events like purchase).
     */
    function sendImmediate(event) {
        var endpoint = config.endpoint + '/collect';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Ecom360-Key', config.apiKey);
        xhr.timeout = 10000;

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status >= 200 && xhr.status < 300) {
                log('Immediate event sent:', event.event_type);
            } else {
                warn('Immediate send failed:', xhr.status);
                // Fall back to queue for retry.
                enqueue(event);
            }
        };

        try {
            xhr.send(JSON.stringify(event));
        } catch (e) {
            enqueue(event);
        }
    }

    /**
     * Use sendBeacon for page unload events (reliable delivery).
     */
    function sendBeacon(events) {
        if (!navigator.sendBeacon) return false;

        var endpoint;
        var payload;

        if (events.length === 1) {
            endpoint = config.endpoint + '/collect';
            payload = JSON.stringify(events[0]);
        } else {
            endpoint = config.endpoint + '/collect/batch';
            payload = JSON.stringify({ events: events });
        }

        // sendBeacon doesn't support custom headers, so we append the API key as a query param.
        endpoint += (endpoint.indexOf('?') === -1 ? '?' : '&') + 'api_key=' + encodeURIComponent(config.apiKey);

        try {
            return navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }));
        } catch (e) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Auto-Tracking: Scroll Depth
    // ──────────────────────────────────────────────────────────────────────

    function handleScroll() {
        var pct = getMaxScrollPercent();
        for (var i = 0; i < SCROLL_THRESHOLDS.length; i++) {
            var threshold = SCROLL_THRESHOLDS[i];
            if (pct >= threshold && !scrollTracked[threshold]) {
                scrollTracked[threshold] = true;
                enqueue(buildEvent('scroll_depth', {
                    depth_percent: threshold,
                    page_path: window.location.pathname
                }));
            }
        }
    }

    var scrollDebounceTimer = null;

    function onScroll() {
        if (scrollDebounceTimer) return;
        scrollDebounceTimer = setTimeout(function() {
            scrollDebounceTimer = null;
            handleScroll();
        }, 250);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Auto-Tracking: Time on Page
    // ──────────────────────────────────────────────────────────────────────

    function trackTimeOnPage() {
        var duration = Math.round((Date.now() - pageLoadTime) / 1000);
        if (duration < 1) return;

        var event = buildEvent('time_on_page', {
            duration_seconds: duration,
            page_path: window.location.pathname
        });

        // Use sendBeacon for reliability on page unload.
        var sent = sendBeacon([event]);
        if (!sent) {
            // Fallback: synchronous XHR (last resort).
            try {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', config.endpoint + '/collect', false); // Synchronous
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-Ecom360-Key', config.apiKey);
                xhr.send(JSON.stringify(event));
            } catch (e) { /* Best effort */ }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Auto-Tracking: Click Tracking (for outbound links & CTAs)
    // ──────────────────────────────────────────────────────────────────────

    function handleClick(e) {
        var target = e.target;
        // Walk up to find the closest <a> tag.
        while (target && target.tagName !== 'A') {
            target = target.parentElement;
        }
        if (!target || !target.href) return;

        var href = target.href;
        var isExternal = target.hostname !== window.location.hostname;

        if (isExternal) {
            enqueue(buildEvent('outbound_click', {
                href: href,
                text: (target.textContent || '').trim().substring(0, 200),
                page_path: window.location.pathname
            }));
        }

        // Track CTA clicks (buttons with data-e360-cta attribute).
        if (target.hasAttribute('data-e360-cta')) {
            enqueue(buildEvent('cta_click', {
                cta_id: target.getAttribute('data-e360-cta'),
                href: href,
                text: (target.textContent || '').trim().substring(0, 200)
            }));
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Auto-Tracking: Form Interactions
    // ──────────────────────────────────────────────────────────────────────

    function handleFormSubmit(e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        enqueue(buildEvent('form_submit', {
            form_id: form.id || null,
            form_action: form.action || null,
            form_method: form.method || 'GET',
            page_path: window.location.pathname
        }));

        // Try to capture email field for identity resolution.
        var emailInputs = form.querySelectorAll('input[type="email"], input[name*="email"]');
        for (var i = 0; i < emailInputs.length; i++) {
            var val = emailInputs[i].value;
            if (val && val.indexOf('@') > 0) {
                Ecom360.identify('email', val);
                break;
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SPA History Tracking (pushState / replaceState / popstate)
    // ──────────────────────────────────────────────────────────────────────

    function patchHistory() {
        if (!window.history || !window.history.pushState) return;

        var origPush = window.history.pushState;
        var origReplace = window.history.replaceState;

        window.history.pushState = function() {
            origPush.apply(this, arguments);
            onRouteChange();
        };

        window.history.replaceState = function() {
            origReplace.apply(this, arguments);
            onRouteChange();
        };

        window.addEventListener('popstate', onRouteChange);
    }

    function onRouteChange() {
        // Delay slightly to let the DOM update.
        setTimeout(function() {
            scrollTracked = {};
            pageLoadTime = Date.now();
            if (config.autoTrackPageViews !== false) {
                Ecom360.trackPageView();
            }
        }, 100);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Behavioral Intervention Listener
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Poll a lightweight endpoint or use WebSocket to receive
     * behavioral interventions (popups, discount overlays, etc.)
     * from the rules engine.
     *
     * When an intervention is received, call all registered callbacks.
     */
    function startInterventionListener() {
        if (interventionPollTimer) clearInterval(interventionPollTimer);
        if (interventionCallbacks.length === 0) return;

        log('Intervention listener started (polling)');

        // 1. Try WebSocket via Laravel Echo if available
        if (window.Echo && sessionId) {
            try {
                window.Echo.private('session.' + sessionId)
                    .listen('InterventionEvent', function(data) {
                        handleIntervention(data);
                    });
                log('WebSocket intervention channel subscribed');
                return; // WebSocket connected — skip polling
            } catch (e) {
                log('WebSocket unavailable, falling back to polling');
            }
        }

        // 2. Polling fallback — check every 15 seconds
        var POLL_INTERVAL = 15000;
        interventionPollTimer = setInterval(function() {
            if (!sessionId || interventionCallbacks.length === 0) return;
            var url = config.endpoint + '/interventions/poll?session_id=' +
                encodeURIComponent(sessionId) + '&visitor_id=' + encodeURIComponent(visitorId || '');
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.setRequestHeader('X-Ecom360-Key', config.apiKey);
            xhr.timeout = 5000;
            xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4 || xhr.status !== 200) return;
                try {
                    var resp = JSON.parse(xhr.responseText);
                    var items = resp.data || resp.interventions || [];
                    for (var i = 0; i < items.length; i++) {
                        handleIntervention(items[i]);
                    }
                } catch (e) {}
            };
            xhr.send();
        }, POLL_INTERVAL);
    }

    function handleIntervention(data) {
        log('Intervention received:', data);
        for (var i = 0; i < interventionCallbacks.length; i++) {
            try {
                interventionCallbacks[i](data);
            } catch (e) {
                warn('Intervention callback error:', e.message);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Exit-Intent Detection
    // ──────────────────────────────────────────────────────────────────────

    var exitIntentFired = false;
    var EXIT_INTENT_DELAY = 2000; // minimum ms on page before firing
    var exitIntentCallbacks = [];

    function startExitIntentDetection() {
        // Desktop: mouse leaves viewport at top
        document.addEventListener('mouseout', function(e) {
            if (exitIntentFired) return;
            if (Date.now() - pageLoadTime < EXIT_INTENT_DELAY) return;
            if (e.clientY < 10 && (e.relatedTarget === null || e.relatedTarget.nodeName === 'HTML')) {
                exitIntentFired = true;
                var eventData = {
                    trigger: 'mouse_leave',
                    time_on_page: Math.round((Date.now() - pageLoadTime) / 1000),
                    page_path: window.location.pathname,
                    cart_total: getCartTotalFromDOM()
                };
                enqueue(buildEvent('exit_intent', eventData));
                for (var i = 0; i < exitIntentCallbacks.length; i++) {
                    try { exitIntentCallbacks[i](eventData); } catch (e) {}
                }
                log('Exit intent detected (mouse_leave)');
            }
        });

        // Mobile: back button / orientation + rapid scroll up
        var lastScrollY = window.pageYOffset || 0;
        var rapidScrollCount = 0;
        window.addEventListener('scroll', function() {
            if (exitIntentFired) return;
            var currentY = window.pageYOffset || 0;
            if (currentY < lastScrollY && currentY < 100 && lastScrollY > 300) {
                rapidScrollCount++;
                if (rapidScrollCount >= 3) {
                    exitIntentFired = true;
                    var eventData = {
                        trigger: 'rapid_scroll_up',
                        time_on_page: Math.round((Date.now() - pageLoadTime) / 1000),
                        page_path: window.location.pathname
                    };
                    enqueue(buildEvent('exit_intent', eventData));
                    for (var i = 0; i < exitIntentCallbacks.length; i++) {
                        try { exitIntentCallbacks[i](eventData); } catch (e) {}
                    }
                    log('Exit intent detected (rapid_scroll_up)');
                }
            } else {
                rapidScrollCount = 0;
            }
            lastScrollY = currentY;
        }, { passive: true });

        // Idle detection: no mouse/keyboard for 60s
        var idleTimeout = null;
        var idleFired = false;

        function resetIdle() {
            if (idleTimeout) clearTimeout(idleTimeout);
            idleTimeout = setTimeout(function() {
                if (idleFired) return;
                idleFired = true;
                var eventData = {
                    trigger: 'idle',
                    idle_seconds: 60,
                    page_path: window.location.pathname
                };
                enqueue(buildEvent('idle_detected', eventData));
                log('Idle detected (60s inactivity)');
            }, 60000);
        }
        document.addEventListener('mousemove', resetIdle, { passive: true });
        document.addEventListener('keydown', resetIdle, { passive: true });
        document.addEventListener('touchstart', resetIdle, { passive: true });
        resetIdle();
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Rage-Click Detection
    // ──────────────────────────────────────────────────────────────────────

    var RAGE_CLICK_THRESHOLD = 3; // clicks
    var RAGE_CLICK_WINDOW = 1500; // ms
    var RAGE_CLICK_RADIUS = 50; // px
    var recentClicks = [];
    var rageClickFiredElements = {};

    function startRageClickDetection() {
        document.addEventListener('click', function(e) {
            var now = Date.now();
            recentClicks.push({ x: e.clientX, y: e.clientY, t: now });

            // Prune old clicks
            recentClicks = recentClicks.filter(function(c) {
                return now - c.t < RAGE_CLICK_WINDOW;
            });

            if (recentClicks.length >= RAGE_CLICK_THRESHOLD) {
                // Check proximity — all clicks within RAGE_CLICK_RADIUS
                var first = recentClicks[0];
                var allClose = recentClicks.every(function(c) {
                    var dx = c.x - first.x,
                        dy = c.y - first.y;
                    return Math.sqrt(dx * dx + dy * dy) < RAGE_CLICK_RADIUS;
                });

                if (allClose) {
                    var target = e.target;
                    var selector = getElementSelector(target);
                    // Don't fire for same element more than once per page
                    if (rageClickFiredElements[selector]) return;
                    rageClickFiredElements[selector] = true;

                    var eventData = {
                        click_count: recentClicks.length,
                        element: selector,
                        element_text: (target.textContent || '').trim().substring(0, 100),
                        page_path: window.location.pathname,
                        x: e.clientX,
                        y: e.clientY
                    };
                    enqueue(buildEvent('rage_click', eventData));
                    log('Rage click detected on:', selector);
                    recentClicks = [];
                }
            }
        }, true);
    }

    function getElementSelector(el) {
        if (!el || !el.tagName) return 'unknown';
        var parts = [];
        while (el && el.tagName && parts.length < 4) {
            var tag = el.tagName.toLowerCase();
            if (el.id) { parts.unshift(tag + '#' + el.id); break; }
            if (el.className && typeof el.className === 'string') {
                tag += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
            }
            parts.unshift(tag);
            el = el.parentElement;
        }
        return parts.join(' > ');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Free-Shipping Gamification Widget
    // ──────────────────────────────────────────────────────────────────────

    var freeShippingConfig = null;

    function initFreeShippingWidget(cfg) {
        freeShippingConfig = cfg || {};
        var threshold = freeShippingConfig.threshold || 0;
        if (threshold <= 0) return;

        // Create widget container
        var widget = document.createElement('div');
        widget.id = 'e360-freeship-bar';
        widget.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#2d3748;color:#fff;padding:10px 20px;z-index:9999;text-align:center;font-family:sans-serif;font-size:14px;transition:transform 0.3s;transform:translateY(100%);display:flex;align-items:center;justify-content:center;gap:12px;';

        var progressOuter = document.createElement('div');
        progressOuter.style.cssText = 'flex:1;max-width:300px;height:8px;background:rgba(255,255,255,0.2);border-radius:4px;overflow:hidden;';
        var progressInner = document.createElement('div');
        progressInner.id = 'e360-freeship-progress';
        progressInner.style.cssText = 'height:100%;background:linear-gradient(90deg,#48bb78,#38a169);border-radius:4px;transition:width 0.5s;width:0%;';
        progressOuter.appendChild(progressInner);

        var text = document.createElement('span');
        text.id = 'e360-freeship-text';

        var close = document.createElement('button');
        close.textContent = '\u00d7';
        close.style.cssText = 'background:none;border:none;color:#fff;font-size:20px;cursor:pointer;padding:0 4px;';
        close.onclick = function() { widget.style.transform = 'translateY(100%)'; };

        widget.appendChild(text);
        widget.appendChild(progressOuter);
        widget.appendChild(close);
        document.body.appendChild(widget);

        updateFreeShippingWidget(getCartTotalFromDOM());
    }

    function updateFreeShippingWidget(cartTotal) {
        if (!freeShippingConfig || !freeShippingConfig.threshold) return;
        var threshold = freeShippingConfig.threshold;
        var currency = freeShippingConfig.currency || '$';
        var widget = document.getElementById('e360-freeship-bar');
        var text = document.getElementById('e360-freeship-text');
        var progress = document.getElementById('e360-freeship-progress');
        if (!widget || !text || !progress) return;

        var remaining = Math.max(0, threshold - cartTotal);
        var pct = Math.min(100, Math.round((cartTotal / threshold) * 100));
        progress.style.width = pct + '%';

        if (remaining <= 0) {
            text.innerHTML = '\ud83c\udf89 You qualify for <strong>FREE shipping!</strong>';
            progress.style.background = 'linear-gradient(90deg,#48bb78,#38a169)';
            widget.style.transform = 'translateY(0)';
            enqueue(buildEvent('free_shipping_qualified', { cart_total: cartTotal, threshold: threshold }));
        } else {
            text.innerHTML = 'Add <strong>' + currency + remaining.toFixed(2) + '</strong> more for <strong>FREE shipping!</strong>';
            widget.style.transform = 'translateY(0)';
        }
    }

    function getCartTotalFromDOM() {
        // Attempt to read cart total from common e-commerce selectors
        var selectors = ['.cart-total', '.cart__total', '#cart-total', '[data-cart-total]',
            '.minicart-price', '.subtotal .price', '.cart-summary .grand .price'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el) {
                var val = parseFloat((el.textContent || '').replace(/[^0-9.]/g, ''));
                if (!isNaN(val) && val > 0) return val;
            }
        }
        return 0;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Page Visibility (pause/resume tracking)
    // ──────────────────────────────────────────────────────────────────────

    function handleVisibilityChange() {
        if (document.hidden || document.visibilityState === 'hidden') {
            // Page hidden: flush any pending events.
            flush();
        } else {
            // Page visible: touch session.
            touchSession();
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ──────────────────────────────────────────────────────────────────────

    var Ecom360 = {
        _initialized: false,
        _version: VERSION,

        /**
         * Initialize the tracker.
         *
         * @param {Object} opts
         * @param {string} opts.apiKey          - Tenant API key (required)
         * @param {string} opts.endpoint        - Base API URL (required, e.g. 'https://host/api/v1')
         * @param {boolean} [opts.debug=false]  - Enable console logging
         * @param {boolean} [opts.autoTrackPageViews=true] - Auto-track page views
         * @param {boolean} [opts.autoTrackScroll=true] - Auto-track scroll depth
         * @param {boolean} [opts.autoTrackClicks=true] - Auto-track outbound clicks
         * @param {boolean} [opts.autoTrackForms=true] - Auto-track form submissions
         * @param {boolean} [opts.trackTimeOnPage=true] - Track time on page on unload
         * @param {boolean} [opts.trackSpa=true] - Auto-track SPA route changes
         * @param {boolean} [opts.requireConsent=false] - Require explicit consent before tracking
         * @param {boolean} [opts.secureCookies=false] - Use Secure + SameSite=None cookies
         */
        init: function(opts) {
            if (!opts || !opts.apiKey || !opts.endpoint) {
                warn('init() requires apiKey and endpoint');
                return;
            }

            config = {
                apiKey: opts.apiKey,
                endpoint: opts.endpoint.replace(/\/$/, ''), // Strip trailing slash.
                debug: opts.debug || false,
                autoTrackPageViews: opts.autoTrackPageViews !== false,
                autoTrackScroll: opts.autoTrackScroll !== false,
                autoTrackClicks: opts.autoTrackClicks !== false,
                autoTrackForms: opts.autoTrackForms !== false,
                trackTimeOnPage: opts.trackTimeOnPage !== false,
                trackSpa: opts.trackSpa !== false,
                requireConsent: opts.requireConsent || false,
                secureCookies: opts.secureCookies || false
            };

            // Handle consent.
            if (config.requireConsent) {
                var saved = getCookie(CONSENT_COOKIE);
                consentGranted = saved === '1';
                if (!consentGranted) {
                    log('Consent required but not yet granted. Call Ecom360.grantConsent().');
                }
            }

            // Init session.
            initSession();

            // Start the batch flush timer.
            flushTimer = setInterval(flush, BATCH_INTERVAL_MS);

            // Auto-track page view.
            if (config.autoTrackPageViews) {
                Ecom360.trackPageView();
            }

            // Auto-track scroll depth.
            if (config.autoTrackScroll) {
                window.addEventListener('scroll', onScroll, { passive: true });
            }

            // Auto-track outbound clicks & CTAs.
            if (config.autoTrackClicks) {
                document.addEventListener('click', handleClick, true);
            }

            // Auto-track form submissions.
            if (config.autoTrackForms) {
                document.addEventListener('submit', handleFormSubmit, true);
            }

            // Track time on page on unload.
            if (config.trackTimeOnPage) {
                window.addEventListener('beforeunload', trackTimeOnPage);
                window.addEventListener('pagehide', trackTimeOnPage);
            }

            // Flush on unload.
            window.addEventListener('beforeunload', function() {
                if (eventQueue.length > 0) {
                    var sent = sendBeacon(eventQueue.splice(0));
                    if (!sent) flush();
                }
            });

            // SPA tracking.
            if (config.trackSpa) {
                patchHistory();
            }

            // Visibility change handler.
            document.addEventListener('visibilitychange', handleVisibilityChange);

            // Exit-intent detection (enabled by default).
            if (opts.exitIntent !== false) {
                startExitIntentDetection();
            }

            // Rage-click detection (enabled by default).
            if (opts.rageClick !== false) {
                startRageClickDetection();
            }

            // Free-shipping gamification widget.
            if (opts.freeShipping && opts.freeShipping.threshold > 0) {
                if (document.readyState === 'complete') {
                    initFreeShippingWidget(opts.freeShipping);
                } else {
                    window.addEventListener('load', function() {
                        initFreeShippingWidget(opts.freeShipping);
                    });
                }
            }

            Ecom360._initialized = true;
            log('Initialized v' + VERSION, '| Session:', sessionId, '| Visitor:', visitorId);
        },

        // ── Page View ─────────────────────────────────────────────────────

        /**
         * Track a page view event.
         *
         * @param {Object} [properties] - Additional metadata
         */
        trackPageView: function(properties) {
            var meta = Object.assign({
                page_path: window.location.pathname,
                page_title: document.title || '',
                referrer: document.referrer || ''
            }, properties || {});

            enqueue(buildEvent('page_view', meta));
        },

        // ── Ecommerce Events ──────────────────────────────────────────────

        /**
         * Track a product view.
         *
         * @param {Object} product
         * @param {string} product.id         - Product ID
         * @param {string} product.name       - Product name
         * @param {number} product.price      - Product price
         * @param {string} [product.category] - Product category
         * @param {string} [product.brand]    - Product brand
         * @param {string} [product.variant]  - Product variant
         * @param {string} [product.sku]      - Product SKU
         */
        trackProductView: function(product) {
            if (!product || !product.id) {
                warn('trackProductView requires product.id');
                return;
            }
            enqueue(buildEvent('product_view', {
                product_id: product.id,
                product_name: product.name || '',
                price: product.price || 0,
                category: product.category || '',
                brand: product.brand || '',
                variant: product.variant || '',
                sku: product.sku || '',
                currency: product.currency || 'USD'
            }));
        },

        /**
         * Track adding a product to cart.
         *
         * @param {Object} product            - Product details
         * @param {number} [product.quantity]  - Quantity added
         * @param {Object} [cart]             - Current cart state
         * @param {Array}  [cart.items]       - All cart items
         * @param {number} [cart.total]       - Cart total
         */
        trackAddToCart: function(product, cart) {
            if (!product || !product.id) {
                warn('trackAddToCart requires product.id');
                return;
            }
            var meta = {
                product_id: product.id,
                product_name: product.name || '',
                price: product.price || 0,
                quantity: product.quantity || 1,
                category: product.category || '',
                variant: product.variant || ''
            };

            if (cart) {
                meta.cart_total = cart.total || 0;
                meta.cart_items = cart.items || [];
                meta.cart_item_count = (cart.items || []).length;
            }

            enqueue(buildEvent('add_to_cart', meta));

            // Also send cart_update for LiveContextService.
            if (cart) {
                enqueue(buildEvent('cart_update', {
                    cart_items: cart.items || [],
                    cart_total: cart.total || 0
                }));
            }
        },

        /**
         * Track removing a product from cart.
         */
        trackRemoveFromCart: function(product, cart) {
            if (!product || !product.id) {
                warn('trackRemoveFromCart requires product.id');
                return;
            }
            var meta = {
                product_id: product.id,
                product_name: product.name || '',
                price: product.price || 0,
                quantity: product.quantity || 1
            };

            if (cart) {
                meta.cart_total = cart.total || 0;
                meta.cart_items = cart.items || [];
            }

            enqueue(buildEvent('remove_from_cart', meta));

            if (cart) {
                enqueue(buildEvent('cart_update', {
                    cart_items: cart.items || [],
                    cart_total: cart.total || 0
                }));
            }
        },

        /**
         * Track cart update (general — e.g. quantity change).
         */
        trackCartUpdate: function(cart) {
            if (!cart) { warn('trackCartUpdate requires cart object'); return; }
            enqueue(buildEvent('cart_update', {
                cart_items: cart.items || [],
                cart_total: cart.total || 0,
                cart_item_count: (cart.items || []).length
            }));
        },

        /**
         * Track beginning of checkout.
         */
        trackBeginCheckout: function(cart, properties) {
            var meta = Object.assign({
                cart_total: cart ? cart.total || 0 : 0,
                cart_items: cart ? cart.items || [] : [],
                cart_item_count: cart ? (cart.items || []).length : 0
            }, properties || {});

            enqueue(buildEvent('begin_checkout', meta));
        },

        /**
         * Track a completed purchase. Sent immediately (not batched).
         *
         * @param {Object} order
         * @param {string}   order.id         - Order ID
         * @param {number}   order.total      - Order total
         * @param {string}   [order.currency] - Currency code
         * @param {number}   [order.tax]      - Tax amount
         * @param {number}   [order.shipping] - Shipping cost
         * @param {string}   [order.coupon]   - Coupon code used
         * @param {Array}    [order.items]    - Array of purchased items
         */
        trackPurchase: function(order) {
            if (!order || !order.id) {
                warn('trackPurchase requires order.id');
                return;
            }

            var event = buildEvent('purchase', {
                order_id: order.id,
                order_total: order.total || 0,
                currency: order.currency || 'USD',
                tax: order.tax || 0,
                shipping: order.shipping || 0,
                coupon: order.coupon || null,
                items: order.items || [],
                item_count: (order.items || []).length
            });

            // Purchases are critical — send immediately AND via batch as backup.
            sendImmediate(event);
            log('Purchase tracked:', order.id, '$' + (order.total || 0));
        },

        /**
         * Track a search query.
         */
        trackSearch: function(query, resultsCount, properties) {
            enqueue(buildEvent('search', Object.assign({
                search_query: query || '',
                results_count: resultsCount || 0,
                page_path: window.location.pathname
            }, properties || {})));
        },

        /**
         * Track a product list / category view.
         */
        trackCategoryView: function(category, products, properties) {
            enqueue(buildEvent('category_view', Object.assign({
                category_name: category || '',
                product_count: (products || []).length,
                product_ids: (products || []).map(function(p) { return p.id || p; }).slice(0, 20)
            }, properties || {})));
        },

        /**
         * Track a wishlist action.
         */
        trackWishlist: function(action, product) {
            enqueue(buildEvent('wishlist_' + (action || 'add'), {
                product_id: product ? product.id : '',
                product_name: product ? product.name || '' : ''
            }));
        },

        // ── Custom Events ─────────────────────────────────────────────────

        /**
         * Track a custom event with arbitrary data.
         *
         * @param {string} eventType   - Event type (snake_case, e.g. 'video_play')
         * @param {Object} [metadata]  - Structured metadata
         * @param {Object} [customData] - Free-form custom data
         */
        track: function(eventType, metadata, customData) {
            if (!eventType || typeof eventType !== 'string') {
                warn('track() requires a string eventType');
                return;
            }
            // Normalize to snake_case.
            eventType = eventType.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/^_+|_+$/g, '');
            if (!eventType || !/^[a-z]/.test(eventType)) {
                warn('Invalid event type:', eventType);
                return;
            }
            enqueue(buildEvent(eventType, metadata || {}, customData || {}));
        },

        // ── Identity Resolution ───────────────────────────────────────────

        /**
         * Identify the current visitor as a known customer.
         *
         * @param {string} type  - 'email' or 'phone'
         * @param {string} value - The identifier value
         * @param {Object} [attributes] - Additional customer attributes
         */
        identify: function(type, value, attributes) {
            if (!type || !value) {
                warn('identify() requires type and value');
                return;
            }

            customerIdentifier = { type: type, value: value };

            // Fire an identify event so the backend links this session.
            enqueue(buildEvent('identify', {
                identifier_type: type,
                identifier_value: value
            }, attributes || {}));

            log('Identified:', type, '=', value);
        },

        // ── Consent Management ────────────────────────────────────────────

        /**
         * Grant tracking consent (GDPR compliance).
         */
        grantConsent: function() {
            consentGranted = true;
            setCookie(CONSENT_COOKIE, '1', 365);
            log('Consent granted');

            // If we haven't tracked the initial page view, do it now.
            if (config.autoTrackPageViews && Ecom360._initialized) {
                Ecom360.trackPageView();
            }
        },

        /**
         * Revoke tracking consent.
         */
        revokeConsent: function() {
            consentGranted = false;
            setCookie(CONSENT_COOKIE, '0', 365);
            eventQueue = []; // Clear any pending events.
            log('Consent revoked, queue cleared');
        },

        /**
         * Check if consent has been granted.
         */
        hasConsent: function() {
            return consentGranted;
        },

        // ── Intervention Handling ─────────────────────────────────────────

        /**
         * Register a callback for behavioral interventions from the rules engine.
         *
         * @param {function} callback - Called with intervention data:
         *   { action_type, action_payload, rule_name, intent, fired_at }
         */
        onIntervention: function(callback) {
            if (typeof callback === 'function') {
                interventionCallbacks.push(callback);
                startInterventionListener();
            }
        },

        /**
         * Register a callback for exit-intent events.
         */
        onExitIntent: function(callback) {
            if (typeof callback === 'function') {
                exitIntentCallbacks.push(callback);
            }
        },

        /**
         * Update the free-shipping widget with a new cart total.
         */
        updateCartTotal: function(total) {
            updateFreeShippingWidget(total);
        },

        /**
         * Enable the free-shipping gamification widget.
         */
        enableFreeShipping: function(cfg) {
            if (document.readyState === 'complete') {
                initFreeShippingWidget(cfg);
            } else {
                window.addEventListener('load', function() {
                    initFreeShippingWidget(cfg);
                });
            }
        },

        /**
         * Manually trigger an intervention (for testing).
         */
        _simulateIntervention: function(data) {
            handleIntervention(data);
        },

        // ── Debug Helpers ─────────────────────────────────────────────────

        /**
         * Register a debug log callback.
         */
        onDebug: function(callback) {
            if (typeof callback === 'function') {
                debugCallbacks.push(callback);
            }
        },

        /**
         * Get current tracker state (for debugging).
         */
        getState: function() {
            return {
                version: VERSION,
                sessionId: sessionId,
                visitorId: visitorId,
                queueSize: eventQueue.length,
                consent: consentGranted,
                customer: customerIdentifier,
                config: {
                    endpoint: config.endpoint,
                    debug: config.debug
                }
            };
        },

        /**
         * Get the current session ID.
         */
        getSessionId: function() {
            return sessionId;
        },

        /**
         * Get the current visitor ID.
         */
        getVisitorId: function() {
            return visitorId;
        },

        /**
         * Force-flush all queued events.
         */
        flush: function() {
            flush();
        },

        /**
         * Reset the tracker (new session, clear identity).
         */
        reset: function() {
            sessionId = 's_' + uuid();
            setCookie(SESSION_COOKIE, sessionId, 0);
            customerIdentifier = null;
            scrollTracked = {};
            pageLoadTime = Date.now();
            eventQueue = [];
            log('Tracker reset. New session:', sessionId);
        },

        /**
         * Destroy the tracker (stop all tracking).
         */
        destroy: function() {
            if (flushTimer) clearInterval(flushTimer);
            if (interventionPollTimer) clearInterval(interventionPollTimer);
            window.removeEventListener('scroll', onScroll);
            document.removeEventListener('click', handleClick, true);
            document.removeEventListener('submit', handleFormSubmit, true);
            window.removeEventListener('beforeunload', trackTimeOnPage);
            document.removeEventListener('visibilitychange', handleVisibilityChange);
            flush(); // Final flush.
            Ecom360._initialized = false;
            log('Tracker destroyed');
        }
    };

    // ──────────────────────────────────────────────────────────────────────
    //  Export
    // ──────────────────────────────────────────────────────────────────────
    window.Ecom360 = Ecom360;

})(window, document);