<?php
/**
 * Push Notification — renders web push opt-in prompt.
 *
 * Supports Firebase Cloud Messaging and OneSignal.
 *
 * @package Ecom360_Analytics
 */

defined('ABSPATH') || exit;

final class Ecom360_Push {

    /** @var array<string, mixed> */
    private $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    public function is_enabled(): bool {
        return ! empty( $this->settings['push_enabled'] )
            && ! empty( $this->settings['endpoint'] )
            && ! empty( $this->settings['api_key'] );
    }

    public function enqueue(): void {
        if ( ! $this->is_enabled() ) return;
        add_action( 'wp_footer', [ $this, 'render' ], 100 );
    }

    public function render(): void {
        $s = $this->settings;
        $provider = $s['push_provider'] ?? 'firebase';
        $delay = (int) ( $s['push_prompt_delay'] ?? 10 );
        $subscribe_url = rest_url( 'ecom360/v1/push-subscribe' );
        $nonce = wp_create_nonce( 'wp_rest' );

        $config = wp_json_encode( [
            'provider'         => $provider,
            'firebaseApiKey'   => $s['push_firebase_api_key'] ?? '',
            'firebaseSenderId' => $s['push_firebase_sender_id'] ?? '',
            'onesignalAppId'   => $s['push_onesignal_app_id'] ?? '',
            'promptDelay'      => $delay,
            'subscribeUrl'     => $subscribe_url,
            'nonce'            => $nonce,
        ], JSON_UNESCAPED_SLASHES );
        ?>
        <!-- Ecom360 Push Notifications -->
        <script>
        (function(){
            'use strict';
            var CFG = <?php echo $config; ?>;
            if (!('Notification' in window) || !('serviceWorker' in navigator)) return;
            if (Notification.permission === 'granted' || Notification.permission === 'denied') return;

            setTimeout(function() {
                if (CFG.provider === 'onesignal' && CFG.onesignalAppId) {
                    // OneSignal SDK
                    var s = document.createElement('script');
                    s.src = 'https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js';
                    s.defer = true;
                    s.onload = function() {
                        window.OneSignalDeferred = window.OneSignalDeferred || [];
                        window.OneSignalDeferred.push(function(OneSignal) {
                            OneSignal.init({ appId: CFG.onesignalAppId });
                        });
                    };
                    document.head.appendChild(s);
                } else {
                    // Firebase / native Notification API
                    Notification.requestPermission().then(function(perm) {
                        if (perm !== 'granted') return;
                        navigator.serviceWorker.register('/ecom360-sw.js').then(function(reg) {
                            return reg.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: CFG.firebaseApiKey
                            });
                        }).then(function(sub) {
                            // Send subscription to server
                            fetch(CFG.subscribeUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': CFG.nonce,
                                },
                                body: JSON.stringify({
                                    endpoint: sub.endpoint,
                                    subscription_data: JSON.stringify(sub.toJSON()),
                                    token: btoa(sub.endpoint),
                                    provider: 'firebase',
                                    user_agent: navigator.userAgent,
                                }),
                            });
                            if (window.ecom360) window.ecom360.track('push_subscribed', {provider: 'firebase'});
                        });
                    });
                }
            }, CFG.promptDelay * 1000);
        })();
        </script>
        <?php
    }
}
