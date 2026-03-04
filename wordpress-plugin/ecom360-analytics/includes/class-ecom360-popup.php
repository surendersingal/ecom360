<?php
/**
 * Popup Capture Widget — renders lead capture popup on frontend.
 *
 * Supports triggers: time_delay, scroll, exit_intent, page_load
 * Collects: name, email, phone, DOB + arbitrary custom fields
 * Frequency: once, once_per_session, always
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Popup {

    /** @var array<string, mixed> */
    private $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    /**
     * Check if popup is enabled.
     */
    public function is_enabled(): bool {
        return ! empty( $this->settings['popup_enabled'] )
            && ! empty( $this->settings['endpoint'] )
            && ! empty( $this->settings['api_key'] );
    }

    /**
     * Hook into wp_footer to render the popup.
     */
    public function enqueue(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }
        add_action( 'wp_footer', [ $this, 'render' ], 99 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    /**
     * Enqueue popup CSS.
     */
    public function enqueue_styles(): void {
        wp_enqueue_style(
            'ecom360-popup',
            ECM360_PLUGIN_URL . 'assets/css/ecom360-popup.css',
            [],
            ECM360_VERSION
        );
    }

    /**
     * Render the popup HTML + JS inline.
     */
    public function render(): void {
        $s = $this->settings;
        $submit_url = rest_url( 'ecom360/v1/popup-submit' );
        $nonce = wp_create_nonce( 'wp_rest' );

        $config = wp_json_encode( [
            'trigger'        => $s['popup_trigger'] ?? 'time_delay',
            'delay'          => (int) ( $s['popup_delay_seconds'] ?? 15 ),
            'scrollPercent'  => (int) ( $s['popup_scroll_percent'] ?? 50 ),
            'frequency'      => $s['popup_show_frequency'] ?? 'once_per_session',
            'collectName'    => ! empty( $s['popup_collect_name'] ),
            'collectEmail'   => ! empty( $s['popup_collect_email'] ),
            'collectPhone'   => ! empty( $s['popup_collect_phone'] ),
            'collectDob'     => ! empty( $s['popup_collect_dob'] ),
            'submitUrl'      => $submit_url,
            'nonce'          => $nonce,
        ], JSON_UNESCAPED_SLASHES );

        $title = esc_html( $s['popup_title'] ?? 'Get 10% Off Your First Order!' );
        $desc  = esc_html( $s['popup_description'] ?? 'Subscribe to our newsletter and receive exclusive offers.' );
        ?>
        <!-- Ecom360 Popup Capture Widget -->
        <div id="ecom360-popup-overlay" class="ecom360-popup-overlay" style="display:none;">
            <div class="ecom360-popup-container">
                <button type="button" class="ecom360-popup-close" aria-label="Close">&times;</button>
                <h2 class="ecom360-popup-title"><?php echo $title; ?></h2>
                <p class="ecom360-popup-desc"><?php echo $desc; ?></p>
                <form id="ecom360-popup-form" class="ecom360-popup-form" novalidate>
                    <?php if ( ! empty( $s['popup_collect_name'] ) ): ?>
                        <input type="text" name="name" placeholder="<?php esc_attr_e( 'Your Name', 'ecom360-analytics' ); ?>" class="ecom360-popup-input" />
                    <?php endif; ?>
                    <?php if ( ! empty( $s['popup_collect_email'] ) ): ?>
                        <input type="email" name="email" placeholder="<?php esc_attr_e( 'Your Email', 'ecom360-analytics' ); ?>" class="ecom360-popup-input" required />
                    <?php endif; ?>
                    <?php if ( ! empty( $s['popup_collect_phone'] ) ): ?>
                        <input type="tel" name="phone" placeholder="<?php esc_attr_e( 'Phone Number', 'ecom360-analytics' ); ?>" class="ecom360-popup-input" />
                    <?php endif; ?>
                    <?php if ( ! empty( $s['popup_collect_dob'] ) ): ?>
                        <input type="date" name="dob" placeholder="<?php esc_attr_e( 'Date of Birth', 'ecom360-analytics' ); ?>" class="ecom360-popup-input" />
                    <?php endif; ?>
                    <!-- Dynamic custom fields container — populated via dashboard config -->
                    <div id="ecom360-popup-custom-fields"></div>
                    <button type="submit" class="ecom360-popup-submit"><?php esc_html_e( 'Subscribe', 'ecom360-analytics' ); ?></button>
                    <p class="ecom360-popup-success" style="display:none;"><?php esc_html_e( 'Thank you! Check your inbox for your offer.', 'ecom360-analytics' ); ?></p>
                    <p class="ecom360-popup-error" style="display:none;"></p>
                </form>
            </div>
        </div>
        <script>
        (function(){
            'use strict';
            var CFG = <?php echo $config; ?>;
            var overlay = document.getElementById('ecom360-popup-overlay');
            var form = document.getElementById('ecom360-popup-form');
            var closeBtn = overlay.querySelector('.ecom360-popup-close');
            var shown = false;
            var COOKIE_KEY = 'ecom360_popup_shown';

            function shouldShow() {
                if (CFG.frequency === 'once' && getCookie(COOKIE_KEY)) return false;
                if (CFG.frequency === 'once_per_session' && sessionStorage.getItem(COOKIE_KEY)) return false;
                return true;
            }

            function show() {
                if (shown || !shouldShow()) return;
                shown = true;
                overlay.style.display = 'flex';
                if (CFG.frequency === 'once') setCookie(COOKIE_KEY, '1', 365);
                if (CFG.frequency === 'once_per_session') sessionStorage.setItem(COOKIE_KEY, '1');
                if (window.ecom360) window.ecom360.track('popup_shown', {trigger: CFG.trigger});
            }

            function hide() {
                overlay.style.display = 'none';
                if (window.ecom360) window.ecom360.track('popup_closed', {});
            }

            closeBtn.addEventListener('click', hide);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) hide();
            });

            // Triggers
            if (CFG.trigger === 'page_load') {
                show();
            } else if (CFG.trigger === 'time_delay') {
                setTimeout(show, CFG.delay * 1000);
            } else if (CFG.trigger === 'scroll') {
                window.addEventListener('scroll', function onScroll() {
                    var h = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight) - window.innerHeight;
                    if (h <= 0) return;
                    if ((window.scrollY / h) * 100 >= CFG.scrollPercent) {
                        show();
                        window.removeEventListener('scroll', onScroll);
                    }
                }, {passive: true});
            } else if (CFG.trigger === 'exit_intent') {
                document.addEventListener('mouseout', function onMouse(e) {
                    if (e.clientY < 10) {
                        show();
                        document.removeEventListener('mouseout', onMouse);
                    }
                });
            }

            // Form submit
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var data = {};
                var extra = {};
                var inputs = form.querySelectorAll('input, select, textarea');
                for (var i = 0; i < inputs.length; i++) {
                    var inp = inputs[i];
                    if (!inp.name) continue;
                    if (['name','email','phone','dob'].indexOf(inp.name) !== -1) {
                        data[inp.name] = inp.value;
                    } else {
                        extra[inp.name] = inp.value;
                    }
                }
                data.extra_data = extra;
                data.page_url = location.href;
                data.session_id = window.ecom360 ? window.ecom360.getSessionId() : '';

                fetch(CFG.submitUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': CFG.nonce,
                    },
                    body: JSON.stringify(data),
                }).then(function(r) { return r.json(); }).then(function(res) {
                    if (res.success) {
                        form.querySelector('.ecom360-popup-success').style.display = 'block';
                        form.querySelector('.ecom360-popup-submit').style.display = 'none';
                        if (window.ecom360) window.ecom360.track('popup_submitted', data);
                        setTimeout(hide, 3000);
                    } else {
                        var errEl = form.querySelector('.ecom360-popup-error');
                        errEl.textContent = res.message || 'Something went wrong.';
                        errEl.style.display = 'block';
                    }
                }).catch(function() {
                    var errEl = form.querySelector('.ecom360-popup-error');
                    errEl.textContent = 'Network error. Please try again.';
                    errEl.style.display = 'block';
                });
            });

            function getCookie(n) {
                var m = document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));
                return m ? m[1] : null;
            }
            function setCookie(n,v,d) {
                var dt = new Date(); dt.setTime(dt.getTime()+d*864e5);
                document.cookie = n+'='+v+';path=/;expires='+dt.toUTCString()+';SameSite=Lax';
            }
        })();
        </script>
        <?php
    }
}
