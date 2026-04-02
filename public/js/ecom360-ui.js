/**
 * Ecom360 — Premium UI Interactions
 * Mega-menu nav, toast system, chart defaults, utilities
 */

(function() {
    'use strict';

    // ═══════════════════════════════════════════════════════════════════════
    // MEGA-MENU INTERACTIONS
    // ═══════════════════════════════════════════════════════════════════════

    // Mobile: toggle megabar visibility via hamburger
    window.ecom360ToggleMobileMenu = function() {
        var megabar = document.querySelector('.e360-megabar');
        if (megabar) megabar.classList.toggle('mobile-open');
    };

    // Mobile: toggle individual mega-item dropdown
    document.addEventListener('click', function(e) {
        if (window.innerWidth > 768) return;
        var item = e.target.closest('.e360-mega-item');
        if (!item) return;
        var link = e.target.closest('.e360-mega-item > a');
        if (!link) return;
        var dropdown = item.querySelector('.e360-mega-dropdown');
        if (!dropdown) return;
        // If item has a dropdown, prevent navigation and toggle
        if (link.getAttribute('href') === 'javascript:void(0)' || dropdown) {
            e.preventDefault();
            item.classList.toggle('mobile-open');
        }
    });

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(e) {
        if (window.innerWidth > 768) return;
        if (!e.target.closest('.e360-megabar') && !e.target.closest('.e360-mobile-toggle')) {
            var megabar = document.querySelector('.e360-megabar');
            if (megabar) megabar.classList.remove('mobile-open');
        }
    });

    // Legacy sidebar toggle (no-op now, but keep for backward compat)
    window.ecom360ToggleSidebar = function() {
        window.ecom360ToggleMobileMenu();
    };


    // ═══════════════════════════════════════════════════════════════════════
    // TOAST NOTIFICATION SYSTEM
    // ═══════════════════════════════════════════════════════════════════════

    const toastContainer = document.createElement('div');
    toastContainer.id = 'e360-toast-container';
    toastContainer.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column-reverse;gap:8px;';
    document.body.appendChild(toastContainer);

    /**
     * Show a toast notification
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} type
     * @param {number} duration - ms before auto-dismiss (default 4000)
     */
    window.ecom360Toast = function(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;

        var icons = {
            success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
            error: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
            warning: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            info: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
        };

        var toast = document.createElement('div');
        toast.className = 'e360-toast ' + type;
        toast.innerHTML = '<span class="toast-icon">' + (icons[type] || icons.info) + '</span>' +
            '<span class="toast-message">' + message + '</span>' +
            '<button onclick="this.parentElement.remove()" style="background:none;border:none;color:rgba(255,255,255,0.5);cursor:pointer;padding:0 0 0 8px;font-size:18px;">&times;</button>';

        toastContainer.appendChild(toast);

        setTimeout(function() {
            toast.classList.add('e360-toast-out');
            setTimeout(function() { toast.remove(); }, 300);
        }, duration);
    };

    // Override Toastr if loaded, to use our toast system
    if (typeof toastr !== 'undefined') {
        var origSuccess = toastr.success;
        var origError = toastr.error;
        toastr.success = function(msg) { window.ecom360Toast(msg, 'success'); };
        toastr.error = function(msg) { window.ecom360Toast(msg, 'error'); };
        toastr.warning = function(msg) { window.ecom360Toast(msg, 'warning'); };
        toastr.info = function(msg) { window.ecom360Toast(msg, 'info'); };
    }


    // ═══════════════════════════════════════════════════════════════════════
    // APEXCHARTS GLOBAL DEFAULTS
    // ═══════════════════════════════════════════════════════════════════════

    if (typeof ApexCharts !== 'undefined') {
        window.Apex = {
            chart: {
                fontFamily: "'Inter', sans-serif",
                toolbar: { show: false },
                zoom: { enabled: false }
            },
            grid: {
                borderColor: '#E2E8F0',
                strokeDashArray: 4,
                xaxis: { lines: { show: false } }
            },
            colors: ['#1A56DB', '#10B981', '#F59E0B', '#7C3AED', '#0891B2', '#EF4444'],
            stroke: { curve: 'smooth', width: 2 },
            tooltip: {
                theme: 'light',
                style: { fontSize: '13px', fontFamily: "'Inter', sans-serif" }
            },
            xaxis: {
                labels: { style: { colors: '#94A3B8', fontSize: '12px' } },
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: {
                labels: { style: { colors: '#94A3B8', fontSize: '12px' } }
            }
        };
    }


    // ═══════════════════════════════════════════════════════════════════════
    // COUNTUP ANIMATION (lightweight — no library needed)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Animate a number from 0 to target
     * @param {HTMLElement} el - element whose textContent will be animated
     * @param {number} target - target number
     * @param {Object} opts - { prefix, suffix, decimals, duration }
     */
    window.ecom360CountUp = function(el, target, opts) {
        opts = opts || {};
        var prefix = opts.prefix || '';
        var suffix = opts.suffix || '';
        var decimals = opts.decimals || 0;
        var duration = opts.duration || 1200;
        var start = 0;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            // Ease out cubic
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = start + (target - start) * eased;
            el.textContent = prefix + current.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + suffix;
            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }

        requestAnimationFrame(step);
    };


    // ═══════════════════════════════════════════════════════════════════════
    // KEYBOARD SHORTCUTS
    // ═══════════════════════════════════════════════════════════════════════

    document.addEventListener('keydown', function(e) {
        // Cmd/Ctrl + B → toggle sidebar
        if ((e.metaKey || e.ctrlKey) && e.key === 'b') {
            e.preventDefault();
            window.ecom360ToggleSidebar();
        }
    });

})();