/**
 * Ecom360 Analytics — Admin JS (settings page interactions).
 */
;
(function($) {
    'use strict';

    var $btn = $('#ecom360-test-connection');
    var $result = $('#ecom360-test-result');
    var $status = $('#ecom360-status');

    /* ═══════════════════════ Test Connection ═════════════════════════ */

    $btn.on('click', function() {
        $btn.prop('disabled', true);
        $result.text('Testing…').attr('class', 'loading');

        $.ajax({
                url: ecom360Admin.restUrl + 'test-connection',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ecom360Admin.nonce);
                },
            })
            .done(function(data) {
                $result.text(data.message || 'Connected!').attr('class', 'success');
                $status.attr('class', 'ecom360-status connected');
                $status.find('.label').text('Connected');
            })
            .fail(function(xhr) {
                var msg = 'Connection failed';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $result.text(msg).attr('class', 'error');
                $status.attr('class', 'ecom360-status disconnected');
                $status.find('.label').text('Not connected');
            })
            .always(function() {
                $btn.prop('disabled', false);
            });
    });

    /* ═══════════════════════ Toggle API key visibility ═══════════════ */

    var $apiField = $('#api_key');
    $('<button type="button" class="button button-small" style="margin-left:6px">👁</button>')
        .insertAfter($apiField)
        .on('click', function() {
            var type = $apiField.attr('type') === 'password' ? 'text' : 'password';
            $apiField.attr('type', type);
        });

})(jQuery);