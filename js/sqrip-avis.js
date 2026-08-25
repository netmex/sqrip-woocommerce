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

        // Show the "Activate automatic payment reconciliation" switch only while the
        // payment comparison itself is on (same rule as the camt reconciliation).
        var $comparison = $('#woocommerce_sqrip_payment_comparison_enabled');
        var $avisRow = $('#woocommerce_sqrip_avis_enabled').closest('tr');
        function toggleAvisRow() {
            $avisRow.toggle($comparison.is(':checked'));
        }
        if ($comparison.length && $avisRow.length) {
            toggleAvisRow();
            $comparison.on('change', toggleAvisRow);
        }

        // Hide the setup details on the "camt Reconciliation" tab unless the service is
        // actually switched on (both the payment comparison and its own switch).
        var $avisEnabled = $('#woocommerce_sqrip_avis_enabled');
        function toggleAvisDetail() {
            var on = $comparison.is(':checked') && $avisEnabled.is(':checked');
            $('.sqrip-avis-detail').closest('tr').toggleClass('sqrip-hide-avis', !on);
        }
        if ($avisEnabled.length) {
            toggleAvisDetail();
            $avisEnabled.on('change', toggleAvisDetail);
            $comparison.on('change', toggleAvisDetail);
        }

        $(document).on('click', '.sqrip-avis-copy', function () {
            var addr = $('.sqrip-avis-address').text();
            if (!addr) {
                return;
            }
            var done = function () {
                var $c = $('.sqrip-avis-copied').show();
                window.setTimeout(function () { $c.fadeOut(); }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(addr).then(done, done);
            } else {
                var $tmp = $('<input>').val(addr).appendTo('body');
                $tmp.trigger('select');
                try { document.execCommand('copy'); } catch (e) {}
                $tmp.remove();
                done();
            }
        });

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

        // Turn a failed request into a fix, not a raw error dump. A WordPress nonce
        // failure comes back as HTTP 403 with the body "-1": the page's security token
        // expired (e.g. the tab was open across a plugin update). Offer a one-click
        // reload instead of reporting the code. Anything else: a short, actionable hint
        // plus a small status number for support — never the raw response text.
        function avisFailMessage(jqXHR) {
            var status = jqXHR ? jqXHR.status : 0;
            var body = jqXHR && jqXHR.responseText ? String(jqXHR.responseText).trim() : '';

            if (status === 403 && body.slice(0, 2) === '-1') {
                return (s.txt_avis_session_expired || '')
                    + ' <a href="#" class="sqrip-avis-reload">' + (s.txt_avis_reload_link || '') + '</a>';
            }

            return (s.txt_avis_failed || '')
                + (s.txt_avis_retry ? ' ' + s.txt_avis_retry : '')
                + ' [' + (status || '?') + ']';
        }

        $(document).on('click', '.sqrip-avis-reload', function (e) {
            e.preventDefault();
            window.location.reload();
        });

        $(document).on('click', '.sqrip-avis-reconcile', function () {
            var $btn = $(this).prop('disabled', true);
            var $result = $('.sqrip-avis-result').text(s.txt_avis_checking || '');

            $.post(s.ajax_url, {
                action: 'sqrip_avis_reconcile',
                security: s.avis_nonce
            }, function (res) {
                if (res && res.success && res.data && res.data.html) {
                    $result.html(res.data.html);
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : (s.txt_avis_failed || '');
                    $result.text(err);
                }
            }).fail(function (jqXHR) {
                $result.html(avisFailMessage(jqXHR));
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
})(jQuery);
