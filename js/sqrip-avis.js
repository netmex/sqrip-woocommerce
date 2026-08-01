/**
 * Setup assistant and "check now" button for the sqrip payment notification service.
 *
 * The markup is rendered server-side by generate_avis_wizard_html(); this file only
 * drives it against Sqrip_Avis via admin-ajax (actions sqrip_avis_onboard and
 * sqrip_avis_reconcile). Strings and the nonce come from the localised `sqrip` object.
 */
(function ($) {
    'use strict';

    $(function () {
        if (typeof window.sqrip === 'undefined' || !window.sqrip.avis_nonce) {
            return;
        }

        var s = window.sqrip;
        var pollTimer = null;
        var pollTries = 0;

        function onboard(step, done) {
            $.post(s.ajax_url, {
                action: 'sqrip_avis_onboard',
                security: s.avis_nonce,
                step: step
            }, done).fail(function () {
                done({ success: false });
            });
        }

        function stopPolling() {
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        $(document).on('click', '.sqrip-avis-start', function () {
            var $code = $('.sqrip-avis-code').text(s.txt_avis_waiting || '');
            $('.sqrip-avis-complete').prop('disabled', true);
            pollTries = 0;
            stopPolling();

            onboard('start', function () {
                // The bank's confirmation mail lands in the service mailbox; poll until
                // the service has read the code out of it (or give up after ~5 minutes).
                pollTimer = window.setInterval(function () {
                    pollTries++;
                    onboard('code', function (res) {
                        if (res && res.success && res.data && res.data.code) {
                            stopPolling();
                            $code.empty().append(
                                $('<strong/>').text((s.txt_avis_code || '') + ' ' + res.data.code)
                            );
                            $('.sqrip-avis-complete').prop('disabled', false);
                        } else if (pollTries >= 60) {
                            stopPolling();
                            $code.text(s.txt_avis_timeout || '');
                        }
                    });
                }, 5000);
            });
        });

        $(document).on('click', '.sqrip-avis-complete', function () {
            stopPolling();
            $(this).prop('disabled', true);
            onboard('complete', function () {
                $('.sqrip-avis-code').text(s.txt_avis_done || '');
            });
        });

        $(document).on('click', '.sqrip-avis-reconcile', function () {
            var $btn = $(this).prop('disabled', true);
            var $result = $('.sqrip-avis-result').text(s.txt_avis_checking || '');

            $.post(s.ajax_url, {
                action: 'sqrip_avis_reconcile',
                security: s.avis_nonce
            }, function (res) {
                if (res && res.success && res.data) {
                    var msg = (res.data.applied || 0) + ' ' + (s.txt_avis_applied || '');
                    if (res.data.warnings && res.data.warnings.length) {
                        msg += ' — ' + res.data.warnings.join(' ');
                    }
                    $result.text(msg);
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : (s.txt_avis_failed || '');
                    $result.text(err);
                }
            }).fail(function () {
                $result.text(s.txt_avis_failed || '');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
