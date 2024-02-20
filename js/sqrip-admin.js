jQuery(document).ready(function ($) {

    var ip_file_type = $('#woocommerce_sqrip_file_type'),
        ip_itgr_email = $('#woocommerce_sqrip_integration_email'),
        ip_address = $('#woocommerce_sqrip_address'),
        ip_iban = $('#woocommerce_sqrip_iban'),
        ip_iban_type = $('#woocommerce_sqrip_iban_type'),
        ip_token = $('#woocommerce_sqrip_token'),
        btn_save = $('button.woocommerce-save-button'),
        sqrip_additional_information = $('#woocommerce_sqrip_additional_information'),
        tab = $('.sqrip-tab'),
        ip_qrref_format = $('#woocommerce_sqrip_qr_reference_format'),
        ip_order_stt = $('#woocommerce_sqrip_new_status'),
        ip_aworder_stt = $('#woocommerce_sqrip_new_awaiting_status'),
        ip_suorder_stt = $('#woocommerce_sqrip_new_suppressed_status'),
        ip_default_order_stt = $('#woocommerce_sqrip_status_suppressed'),
        ip_refund_token = $('#woocommerce_sqrip_return_token'),
        btn_toggle_stt = $('.sqrip-toggle-order-status'),
        btn_toggle_awaiting_stt = $('.sqrip-toggle-awaiting-status'),
        btn_toggle_suppressed_stt = $('.sqrip-toggle-suppressed-status'),
        ip_enb_new_status = $('#woocommerce_sqrip_enabled_new_status'),
        ip_ft_new_status = $('#woocommerce_sqrip_first_time_new_status'),
        ip_enb_new_awstatus = $('#woocommerce_sqrip_enabled_new_awstatus'),
        ip_ft_new_awstatus = $('#woocommerce_sqrip_first_time_new_awstatus'),
        ip_enb_new_sustatus = $('#woocommerce_sqrip_enabled_new_sustatus'),
        ip_ft_new_sustatus = $('#woocommerce_sqrip_first_time_new_sustatus'),
        ip_suppress_generation = $('#woocommerce_sqrip_suppress_generation'),
        ip_integration_order = $('#woocommerce_sqrip_integration_order'),
        ip_enb_new_qrstatus = $('#woocommerce_sqrip_enabled_new_qrstatus'),
        ip_qr_order_stt = $('#woocommerce_sqrip_qr_order_status'),
        ip_new_qr_order_stt = $('#woocommerce_sqrip_new_qr_order_status'),
        ip_ft_new_qrstatus = $('#woocommerce_sqrip_first_time_new_qrstatus'),
        btn_toggle_qr_stt = $('.sqrip-toggle-qr-order-status'),
        shop_name,
        nh = $('.sqrip-no-height'),
        default_order_status = $('select[id*="status_suppressed"]'),
        status_text = $('strong:contains("test-email-status")'),
        ip_payment_comparison_enabled = $('#woocommerce_sqrip_payment_comparison_enabled'),
        ip_sqrip_status_awaiting = $('#woocommerce_sqrip_status_awaiting'),
        ip_sqrip_enabled = $('#woocommerce_sqrip_enabled'),
        ip_sqrip_refund_enabled = $('#woocommerce_sqrip_return_enabled'),
        ip_sqrip_status_completed = $('#woocommerce_sqrip_status_completed'),
        ip_sqrip_remaining_credits = $('#woocommerce_sqrip_remaining_credits'),
        ip_sqrip_turn_off_if_error = $('#woocommerce_sqrip_turn_off_if_error'),
        ip_sqrip_current_status = $('#woocommerce_sqrip_current_status');
        ip_sqrip_remaining_credits.prop("readonly", true);
        ip_sqrip_current_status.prop("readonly", true);
        ip_sqrip_current_status.addClass('sqrip-no-border');
        ip_sqrip_remaining_credits.addClass('sqrip-no-border');
        ip_sqrip_turn_off_if_error.closest('fieldset').addClass('negative-top-margin')
        ip_sqrip_remaining_credits.closest('fieldset').addClass('negative-top-margin')

    function handleResponseMessage(message) {
        let displayMessage = message.replace('. ', '.<br/>');
        displayMessage = displayMessage.replace('settings', "<a href='https://api.sqrip.ch' target='_blank'>settings</a>");
        displayMessage = displayMessage.replace('support', "<a href='mailto:support@sqrip.ch'>support</a>");

        return displayMessage;
    }

    $('select[id*="delete_invoice_status"]').select2({
        allowClear: true
    });

    $.ajax({
        type: "post",
        url: sqrip.ajax_url,
        data: {
            action: "sqrip_get_shop_name"
        },
        success: function (response) {
            shop_name = response;
        },
        error: function (jqXHR, textStatus, errorThrown) {
            console.log('The following error occured: ' + textStatus, errorThrown);
        }
    })
    $.ajax({
        type: "post",
        url: sqrip.ajax_url,
        data: {
            action: "sqrip_validation_token",
            token: ip_token.val()
        },
        success: function (response) {
            if (response) {
                if (response.credits_left) {
                    // console.log({response}, response.credits_left)
                    ip_sqrip_remaining_credits.val(response.credits_left+"");
                }
                else if (response.credits_left == 0) {
                    ip_sqrip_remaining_credits.val("0");
                }
                else {
                    ip_sqrip_remaining_credits.val("N/A");
                }

                let displayMessage = handleResponseMessage(response.message);
                // displayMessage = displayMessage.replace('support', '')
                let hasError = false;
                let statusList = '';

                if (response.credits_left == 0) {
                    // output_html = '<p class="sqrip-description">'+ errorResolveText + '<br/><ul><li>No Credits left. Please purchase Credits here <a href="https://www.sqrip.ch/#pricing" target="_blank">https://www.sqrip.ch/#pricing</a></li></ul></p>';
                    // ip_sqrip_turn_off_if_error.closest('td.forminp').append(output_html);
                    hasError = true;
                    statusList += '<li><span></span> Not enough Credits available. Please purchase Credits here <a href="https://www.sqrip.ch/#pricing" target="_blank">https://www.sqrip.ch/#pricing</a></li>';
                } else if (response.credits_left > 0) {
                    statusList += '<li><span class="status-success"></span> Enough Credits available.</li>';
                } else {
                    // if !response.credits_left
                    statusList += '<li><span></span> Unable to fetch Credits available.</li>';
                }
                
                if (response.result == false) {
                    // output_html = '<p class="sqrip-description">'+ errorResolveText + '<br/><ul><li>'+displayMessage+'</li></ul></p>';
                    // ip_sqrip_turn_off_if_error.closest('td.forminp').append(output_html);
                    hasError = true;
                    statusList += '<li><span></span> '+displayMessage+'</li>';
                } else {
                    statusList += '<li><span class="status-success"></span> API key is correct and active.</li>';
                }

                if (hasError && response.response_code == "") {                    
                    statusList += '<li><span></span> sqrip-server not reachable</li>';
                }else {
                    statusList += '<li><span class="status-success"></span> Connected to sqrip-server</li>';                        
                }
                
                function displayStatusList (statusList) {

                    if (!ip_sqrip_enabled.is(':checked')) {
                        statusList += '<li><span></span> sqrip is turned off</li>';
                    } else {
                        statusList += '<li><span class="status-success"></span> sqrip is turned on</li>';
                    }
                    
                    statusList = '<ul class="sqrip-status-list">'+statusList+'</ul>';
                    // ip_sqrip_turn_off_if_error.closest('td.forminp').append(statusList);
                    ip_sqrip_current_status.after(statusList);
                    ip_sqrip_current_status.hide()
                }

                // Check IBAN validity
                $.ajax({
                    type: "post",
                    url: sqrip.ajax_url,
                    data: {
                        action: "sqrip_validation_iban",
                        iban: ip_iban.val(),
                        token: ip_token.val()
                    },
                    success: function (response) {
                        if (response) {
                            if (response.result) {
                                statusList += '<li><span class="status-success"></span> (QR)-IBAN validated.</li>';
                            } else {
                                statusList += '<li><span></span> (QR)-IBAN incorrect.</li>';
                            }
                        }
                        displayStatusList(statusList)
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        displayStatusList(statusList)
                        console.log('The following error occured: ' + textStatus, errorThrown);
                    }
                })
                

                if (ip_sqrip_turn_off_if_error.is(':checked')) {
                    // const label_for_turn_off = $('label[for*=woocommerce_sqrip_turn_off_if_error]');
                    const sqripEnabledStatus = ip_sqrip_enabled.is(':checked') ? ' active.' : ' deactivated.';
                    const errorResolveText = 'The plugin is currently'+ sqripEnabledStatus + 
                        ' These errors prevent sqrip from working properly.<br/>Please resolve the following issues:';

                    if (hasError && ip_sqrip_enabled.is(':checked')) {
                        ip_sqrip_enabled.prop('checked', false);
                        setTimeout(function () {
                            btn_save.trigger('click');
                        }, 200);
                    }
                }

            }
        },
        error: function (jqXHR, textStatus, errorThrown) {
            console.log('The following error occured: ' + textStatus, errorThrown);
        },
    })

    if (status_text.html()) {
        status_text.html(status_text.html().replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
    }

    if (ip_token.length) {
        bt_check_token_html = '<button id="btn_sqrip_check_token" class="button-secondary sqrip-btn-validate-token">' + sqrip.txt_check_connection + '</button>';
        ip_token.siblings('.description').after(bt_check_token_html);

        bt_check_token = $('#btn_sqrip_check_token');
        bt_check_token.on('click', function (e) {
            e.preventDefault();
            _this = $(this);
            _output = $(this).closest('td.forminp');
            _output.find('.sqrip-notice').remove();

            if (ip_token.val().trim().length < 1) {
                ip_token.focus();
                return;
            }

            $.ajax({
                type: "post",
                url: sqrip.ajax_url,
                data: {
                    action: "sqrip_validation_token",
                    token: ip_token.val()
                },
                beforeSend: function () {
                    $('body').addClass('sqrip-loading');
                },
                success: function (response) {
                    if (response) {
                        if (response.result) {
                            result = "updated";
                        } else {
                            result = "error";
                        }

                        let displayMessage = handleResponseMessage(response.message);

                        output_html = '<br/><div class="sqrip-notice mt-10 ' + result + '">';
                        output_html += '<p>' + displayMessage + '</p>';
                        output_html += '</div>';
                        _this.after(output_html);

                        if (response.address) {
                            ip_address.find('option[value="sqrip"]').text(response.address);
                        }
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.log('The following error occured: ' + textStatus, errorThrown);
                },
                complete: function () {
                    $('body').removeClass('sqrip-loading');
                }
            })
        })
    }

    ip_file_type.on('change', function () {
        ip_itgr_email.prop('selectedIndex', 0);
        if (this.value == "png") {
            ip_itgr_email.find('option[value="body"]').show();
            ip_itgr_email.find('option[value="both"]').show();
        } else {
            ip_itgr_email.find('option[value="body"]').hide();
            ip_itgr_email.find('option[value="both"]').hide();
        }
    });

    init_individual_address(ip_address.val());

    ip_address.on('change', function () {
        _val = $(this).val();
        init_individual_address(_val);

        // @Deprecated 19-10-2021
        // _output = $(this).closest('td.forminp');
        // _output.find('.sqrip-preview-address').remove();
        // $.ajax({
        //     type : "post",
        //     url : sqrip.ajax_url,
        //     data : {
        //         action: "sqrip_preview_address",
        //         address: $(this).val()
        //     },
        //     beforeSend: function(){
        //        $('body').addClass('sqrip-loading');
        //     },
        //     success: function(response) {

        //         if(response && response.name) {
        //             output_html = '<div class="sqrip-preview-address">';
        //             output_html += '<p>Name: <b>' + response.name + '</b></p>';
        //             output_html += '<p>Street: <b>' + response.street + '</b></p>';
        //             output_html += '<p>City: <b>' + response.city + '</b></p>';
        //             output_html += '<p>Postal Code: <b>' + response.postal_code + '</b></p>';
        //             output_html += '<p>Country Code: <b>' + response.country_code + '</b></p>';
        //             output_html += '</div>';

        //             _output.append(output_html);
        //         }

        //     },
        //     error: function( jqXHR, textStatus, errorThrown ){
        //         console.log( 'The following error occured: ' + textStatus, errorThrown );
        //     },
        //     complete: function(){
        //         $('body').removeClass('sqrip-loading');
        //     }
        // })
    })

    if (ip_iban.length) {
        bt_check_iban_html = '<button id="btn_sqrip_check_iban" class="button-secondary sqrip-btn-validate">' + sqrip.txt_validate_iban + '</button>';
        ip_iban.after(bt_check_iban_html);

        bt_check_iban = $('#btn_sqrip_check_iban');
        bt_check_iban.on('click', function (e) {
            e.preventDefault();
            _this = $(this);
            _output = _this.closest('td.forminp');
            _output.find('.sqrip-notice').remove();
            _output.find('.sqrip-description').remove();
            _output.find('.sqrip-bank').remove();

            $.ajax({
                type: "post",
                url: sqrip.ajax_url,
                data: {
                    action: "sqrip_validation_iban",
                    iban: ip_iban.val(),
                    token: ip_token.val()
                },
                beforeSend: function () {
                    $('body').addClass('sqrip-loading');
                },
                success: function (response) {
                    if (response) {
                        if (response.result) {
                            result = "updated";
                        } else {
                            result = "error";
                        }
                        output_html = '<div class="sqrip-notice ' + result + '">';
                        output_html += '<p>' + response.message + '</p>';
                        output_html += '</div><p class="sqrip-bank"></p><p class="sqrip-description"></p>';
                        _this.after(output_html);
                        _this.siblings('.sqrip-description').html(response.description);
                        _this.siblings('.sqrip-bank').html(response.bank);

                        const label_qr_reference_format = $('label[for*=sqrip_qr_reference_format]').filter(function () {
                            return $(this).parent().hasClass('titledesc');
                        });
                        if (response.result && response.qriban) {
                            ip_qrref_format.closest('tr').show();
                            ip_qrref_format.addClass('qr-iban');
                            ip_qrref_format.removeClass('simple-iban');
                            label_qr_reference_format.text('Initiate QR-Ref# with these 6 digits');
                            $('#simple-iban-description').remove();
                        }
                        if (response.result && !response.qriban) {
                            ip_qrref_format.closest('tr').show();
                            ip_qrref_format.addClass('simple-iban');
                            ip_qrref_format.removeClass('qr-iban');
                            label_qr_reference_format.text('Use these 6 characters (numbers or letters) as characters 5-10 in CR-references');
                            if (!$('#simple-iban-description').is(':visible')) {
                                label_qr_reference_format.after('<p id="simple-iban-description" class="description">This is an example of a CR reference with this option enabled, with X-es representing the 6 characters: RF18 XXXX XX12 1231 1231 4703 4</p>')
                            }
                        }
                        if (!response.result) {
                            ip_qrref_format.closest('tr').hide();
                        }

                        $("input[id*='qr_reference_format']").trigger('input');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.log('The following error occured: ' + textStatus, errorThrown);
                },
                complete: function () {
                    $('body').removeClass('sqrip-loading');
                }
            })
        })
    }

    ip_test_email = $('#woocommerce_sqrip_test_email');

    ip_test_email.closest('tr').hide();

    if (btn_save.length && sqrip.details) {
        $disabled = $warning = "";

        if (!sqrip.details.free_credits && !sqrip.details.credits_left) {
            $disabled = "disabled";
            $warning = '<span class="sqrip-test-warning">' + sqrip.txt_send_test_email_no_credit + '</span>';
        } else if (!sqrip.details.free_credits && sqrip.details.credits_left) {
            $warning = '<span class="sqrip-test-warning">' + sqrip.txt_send_test_email_warning + '</span>';
        }

        bt_test_email_html = '<button id="btn_test_email" class="button-secondary qrinvoice-tab sqrip-btn-send-test-email" ' + $disabled + '>' + sqrip.txt_send_test_email + '</button>' + $warning;
        btn_save.after(bt_test_email_html);

        bt_test_email = $("#btn_test_email");
        bt_test_email.on('click', function (e) {
            e.preventDefault();
            $('#woocommerce_sqrip_test_email').prop('checked', true);

            btn_save.trigger('click');
        });
    }

    //Hide other tabs if sqrip is not enabled
    if (!ip_sqrip_enabled.is(':checked')) {
        $('.sqrip-tab').each(function (i, e) {
            if ($(this).data('tab') !== "services") {
                $(this).hide();
            }
        })
    }

    ip_sqrip_enabled.on('change', function (e) {
        if ($(this).is(':checked')) {
            $('.sqrip-tab').each(function (i, e) {
                if ($(this).data('tab') !== "services") {
                    // console.log($(this).data('tab'))
                    $(this).show();
                }
            })
        } else {
            $('.sqrip-tab').each(function (i, e) {
                if ($(this).data('tab') !== "services") {
                    $(this).hide();
                }
            })
        }
    });

    const toggleFeatures = [
        {
            text: "comparison",
            item: ip_payment_comparison_enabled
        },
        {
            text: "refunds",
            item: ip_sqrip_refund_enabled
        },
    ];

    toggleFeatures.forEach((feature) => {
        //Hide tab if feature is not enabled
        if (!feature.item.is(':checked')) {
            $('.sqrip-tab[data-tab="' + feature.text + '"]').hide();
        }
        //toggle tab on feature change
        feature.item.on('change', function (e) {
            if ($(this).is(':checked')) {
                $('.sqrip-tab[data-tab="' + feature.text + '"]').show();
            } else {
                $('.sqrip-tab[data-tab="' + feature.text + '"]').hide();
            }
        })
    });

    tab_active = window.location.hash.slice(1);
    if (!tab_active) tab_active = "services";
    sqrip_tab_init(tab_active);

    tab.on('click', function (e) {
        e.preventDefault();
        tab_active = $(this).data('tab');
        sqrip_tab_init($(this).data('tab'));
    })

    $('.sqrip-tabs').siblings('table.form-table').find('td p.description').each(function (i, e) {
        wrap = $(this).closest('tr').find('th');

        wrap.append($(this));
    })

    function init_individual_address(_val) {
        if (_val == 'individual') {
            $('.sqrip-address-individual').prop('required', true).closest('tr').show();
        } else {
            $('.sqrip-address-individual').prop('required', false).closest('tr').hide();
        }
    }

    $("input[id*='qr_reference_format']").on('input', function () {
        var inputVal = $(this).val();

        if (ip_qrref_format.hasClass('qr-iban')) {
            if (!/^(|\d{6})$/.test(inputVal)) {
                $(this).addClass('sqrip-rounded-red');
            } else {
                $(this).removeClass('sqrip-rounded-red');
            }
        }
        if (ip_qrref_format.hasClass('simple-iban')) {
            if (!/^(|.{6})$/.test(inputVal)) {
                $(this).addClass('sqrip-rounded-red');
            } else {
                $(this).removeClass('sqrip-rounded-red');
            }
        }

        validate_form();
    });

    $("textarea[id*='file_name']").on('input', function () {
        let inputVal = $(this).val();
        const order_date = new Date().toISOString().split('T')[0];
        const order_number = '000001';

        inputVal = inputVal.replace('[order_date]', order_date);
        inputVal = inputVal.replace('[order_number]', order_number);
        inputVal = inputVal.replace('[shop_name]', shop_name);
        inputVal = inputVal.replace(/ /g, '-');

        if (!/^([\w-]+)(?=\.[\w]+$)/.test(`${inputVal}.pdf`)) {
            $(this).addClass('sqrip-rounded-red');
        } else {
            $(this).removeClass('sqrip-rounded-red');
        }

        validate_form();
    });

    $("textarea[id*='additional_information']").on('input', function () {
        let inputVal = $(this).val();
        const order_date = '06. September 2022';
        const order_number = '000001';

        inputVal = inputVal.replace(/\[due_date format=".*?"]/, order_date);
        inputVal = inputVal.replace('[order_number]', order_number);

        if (inputVal.length >= 140 || (inputVal.match(/\n/g) || []).length > 4) {
            $(this).addClass('sqrip-rounded-red');
        } else {
            $(this).removeClass('sqrip-rounded-red');
        }

        validate_form();
    });

    $(ip_iban).on('input', function () {
        let inputVal = $(this).val();

        const tempInputVal = inputVal.replace(/ /g, '');

        const inputValFormatted =
            tempInputVal.slice(0, 4) + " " +
            tempInputVal.slice(4, 8) + " " +
            tempInputVal.slice(8, 12) + " " +
            tempInputVal.slice(12, 16) + " " +
            tempInputVal.slice(16, 20) + " " +
            tempInputVal.slice(20, 24) + " " +
            tempInputVal.slice(24, 25);

        ip_iban.val(inputValFormatted.trim());

        $('#btn_sqrip_check_iban').siblings('.sqrip-notice, .sqrip-bank, .sqrip-description').remove();
    });

    $(ip_token).on('input', function () {
        $('#btn_sqrip_check_token').siblings('.sqrip-notice, .sqrip-bank, .sqrip-description').remove();
    });

    $(ip_refund_token).on('input', function () {
        $('#btn_sqrip_check_refund_token').siblings('.sqrip-notice, .sqrip-bank, .sqrip-description').remove();
    });

    function validate_form() {
        if ($(".sqrip-rounded-red")[0]) {
            btn_save.attr('disabled', true);
        } else {
            btn_save.attr('disabled', false);
        }
    }

    function sqrip_tab_init(data) {
        window.location.hash = data;
        tab.removeClass('active');
        $('.sqrip-tab[data-tab="' + data + '"]').addClass('active');
        tab_des = $('.sqrip-tabs-description');
        tab_des.find('.sqrip-tab-description').hide();
        tab_des.find('.sqrip-tab-description[data-tab="' + data + '"]').show();

        table = $('.sqrip-tabs').siblings('.form-table');
        table.find('tr').hide();
        $('.sqrip-btn-send-test-email').hide();
        $('.sqrip-test-warning').hide();
        table.find('.' + data + '-tab').closest('tr').show();

        if (data == "qrinvoice") {
            init_individual_address(ip_address.val());
            init_ip_qrref_format();
            $('.sqrip-btn-send-test-email').show();
            $('.sqrip-test-warning').show();

            if (ip_suppress_generation.is(':checked') && tab_active === 'qrinvoice') {
                $('.sqrip-new-order-status').show();
                $('.sqrip-new-default-order-status').show();
            } else {
                $('.sqrip-new-order-status').hide();
                $('.sqrip-new-default-order-status').hide();
            }
        }

        if (data === 'comparison') {
            if (!ip_payment_comparison_enabled.is(':checked')) {
                ip_sqrip_status_awaiting.css({
                    'pointer-events': 'none',
                    'background-color': '#f2f2f2',
                    'cursor': 'not-allowed',
                    'opacity': '0.7'
                });
                ip_sqrip_status_completed.css({
                    'pointer-events': 'none',
                    'background-color': '#f2f2f2',
                    'cursor': 'not-allowed',
                    'opacity': '0.7'
                });
                ip_aworder_stt.prop('readonly', true);
                ip_order_stt.prop('readonly', true);
                $('.sqrip-btn-create-order-stt').attr('disabled', true);
            } else {
                ip_sqrip_status_awaiting.css({
                    'pointer-events': 'auto',
                    'background-color': '',
                    'cursor': '',
                    'opacity': ''
                });
                ip_sqrip_status_completed.css({
                    'pointer-events': 'auto',
                    'background-color': '',
                    'cursor': '',
                    'opacity': ''
                });
                ip_aworder_stt.prop('readonly', false);
                ip_order_stt.prop('readonly', false);
                $('.sqrip-btn-create-order-stt').attr('disabled', false);
            }
        }
    }

    function init_ip_qrref_format() {
        const label_qr_reference_format = $('label[for*=sqrip_qr_reference_format]').filter(function () {
            return $(this).parent().hasClass('titledesc');
        });
        if (ip_qrref_format.hasClass('qr-iban')) {
            ip_qrref_format.closest('tr').show();
        }
        if (ip_qrref_format.hasClass('simple-iban')) {
            ip_qrref_format.closest('tr').show();
            label_qr_reference_format.text('Use these 6 characters (numbers or letters) as characters 5-10 in CR-references');
            if (!$('#simple-iban-description').is(':visible')) {
                label_qr_reference_format.after('<p id="simple-iban-description" class="description">This is an example of a CR reference with this option enabled, with X-es representing the 6 characters: RF18 XXXX XX12 1231 1231 4703 4</p>')
            }
        }
        if (ip_qrref_format.hasClass('hide')) {
            ip_qrref_format.closest('tr').hide();
        }

        if (ip_suppress_generation.is(':checked')) {
            ip_integration_order.closest('tr').hide();
        } else {
            ip_integration_order.closest('tr').show();
        }
    }

    if (ip_refund_token.length) {
        bt_check_refund_token_html = '<button id="btn_sqrip_check_refund_token" class="button-secondary sqrip-btn sqrip-btn-validate-token">' + sqrip.txt_check_connection + '</button>';
        ip_refund_token.after(bt_check_refund_token_html);

        bt_check_refund_token = $('#btn_sqrip_check_refund_token');
        bt_check_refund_token.on('click', function (e) {
            e.preventDefault();
            _this = $(this);
            _output = $(this).closest('td.forminp');
            _output.find('.sqrip-notice').remove();

            if (ip_refund_token.val().trim().length < 1) {
                ip_refund_token.focus();
                return;
            }

            $.ajax({
                type: "post",
                url: sqrip.ajax_url,
                data: {
                    action: "sqrip_validation_refund_token",
                    token: ip_refund_token.val()
                },
                beforeSend: function () {
                    $('body').addClass('sqrip-loading');
                },
                success: function (response) {
                    if (response) {
                        if (response.success) {
                            success = "updated";
                        } else {
                            success = "error";
                        }

                        let displayMessage = handleResponseMessage(response.message);

                        output_html = '<br/><div class="sqrip-notice ' + success + '">';
                        output_html += '<p>' + displayMessage + '</p>';
                        output_html += '</div>';
                        _this.after(output_html);

                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.log('The following error occured: ' + textStatus, errorThrown);
                },
                complete: function () {
                    $('body').removeClass('sqrip-loading');
                }
            })
        })
    }

    if (ip_order_stt.length) {
        ip_order_stt.closest('tr').addClass('sqrip-order-status');
        ip_ft_new_status.prop('checked', false);

        btn_create_order_html = '<button id="btn_create_order_stt" class="button-secondary sqrip-btn sqrip-btn-create-order-stt">' + sqrip.txt_create + '</button>';
        ip_order_stt.after(btn_create_order_html);

        btn_toggle_stt.on('click', function (e) {
            e.preventDefault();

            $(this).closest('tr').toggleClass('sqrip-show');
        })

        btn_create_order = $('#btn_create_order_stt');

        btn_create_order.on('click', function (e) {
            e.preventDefault();

            if (!ip_order_stt.val()) {
                ip_order_stt.focus();
            } else {
                ip_enb_new_status.prop('checked', true);
                ip_ft_new_status.prop('checked', true);
                setTimeout(function () {
                    btn_save.trigger('click');
                }, 200);
            }
        })
    }

    if (ip_aworder_stt.length) {
        ip_aworder_stt.closest('tr').addClass('sqrip-order-status');
        ip_ft_new_awstatus.prop('checked', false);

        btn_create_aworder_html = '<button id="btn_create_aworder_stt" class="button-secondary sqrip-btn sqrip-btn-create-order-stt">' + sqrip.txt_awaiting_create + '</button>';
        ip_aworder_stt.after(btn_create_aworder_html);

        btn_toggle_awaiting_stt.on('click', function (e) {
            e.preventDefault();

            $(this).closest('tr').toggleClass('sqrip-show');
        })

        btn_create_aworder = $('#btn_create_aworder_stt');

        btn_create_aworder.on('click', function (e) {
            e.preventDefault();

            if (!ip_aworder_stt.val()) {
                ip_aworder_stt.focus();
            } else {
                ip_enb_new_awstatus.prop('checked', true);
                ip_ft_new_awstatus.prop('checked', true);
                setTimeout(function () {
                    btn_save.trigger('click');
                }, 200);
            }
        })
    }

    if (ip_suorder_stt.length) {
        ip_suorder_stt.closest('tr').addClass('sqrip-new-order-status');
        ip_default_order_stt.closest('tr').addClass('sqrip-new-default-order-status');
        ip_ft_new_sustatus.prop('checked', false);

        btn_create_suorder_html = '<button id="btn_create_suorder_stt" class="button-secondary sqrip-btn sqrip-btn-create-order-stt">' + sqrip.txt_suppressed_create + '</button>';
        ip_suorder_stt.after(btn_create_suorder_html);

        btn_toggle_suppressed_stt.on('click', function (e) {
            e.preventDefault();

            $(this).closest('tr').toggleClass('sqrip-show');
        })

        btn_create_suorder = $('#btn_create_suorder_stt');

        btn_create_suorder.on('click', function (e) {
            e.preventDefault();

            if (!ip_suorder_stt.val()) {
                ip_suorder_stt.focus();
            } else {
                ip_enb_new_sustatus.prop('checked', true);
                ip_ft_new_sustatus.prop('checked', true);
                setTimeout(function () {
                    btn_save.trigger('click');
                }, 200);
            }
        });

        if (ip_suppress_generation.is(':checked') && tab_active === 'qrinvoice') {
            $('.sqrip-new-order-status').show();
            $('.sqrip-new-default-order-status').show();
        } else {
            $('.sqrip-new-order-status').hide();
            $('.sqrip-new-default-order-status').hide();
        }
    }
    
    if (ip_new_qr_order_stt.length) {
        ip_new_qr_order_stt.closest('tr').addClass('sqrip-order-status');
        ip_qr_order_stt.closest('tr').addClass('sqrip-qr-order-status');
        ip_ft_new_qrstatus.prop('checked', false);

        btn_create_qrorder_html = '<button id="btn_create_qrorder_stt" class="button-secondary sqrip-btn sqrip-btn-create-qrorder-stt">' + sqrip.txt_qr_create + '</button>';
        ip_new_qr_order_stt.after(btn_create_qrorder_html);

        btn_toggle_qr_stt.on('click', function (e) {
            e.preventDefault();

            $(this).closest('tr').toggleClass('sqrip-show');
        })

        btn_create_qrorder = $('#btn_create_qrorder_stt');

        btn_create_qrorder.on('click', function (e) {
            e.preventDefault();

            if (!ip_new_qr_order_stt.val()) {
                ip_new_qr_order_stt.focus();
            } else {
                ip_enb_new_qrstatus.prop('checked', true);
                ip_ft_new_qrstatus.prop('checked', true);
                setTimeout(function () {
                    btn_save.trigger('click');
                }, 200);
            }
        });
    }

    if (ip_suppress_generation.length) {
        ip_suppress_generation.on('change', function () {
            if ($(this).is(':checked') && tab_active === 'qrinvoice') {
                ip_integration_order.closest('tr').hide();
                $('.sqrip-new-order-status').show();
                $('.sqrip-new-default-order-status').show();
            } else {
                ip_integration_order.closest('tr').show();
                $('.sqrip-new-order-status').hide();
                $('.sqrip-new-default-order-status').hide();
            }

            if (default_order_status.val() === 'wc-sqrip-default-status' && ip_suppress_generation.is(':checked')) {
                default_order_status.addClass('sqrip-rounded-red');
            } else {
                default_order_status.removeClass('sqrip-rounded-red');
            }

            validate_form()
        });
    }

    if (ip_payment_comparison_enabled.length) {
        ip_payment_comparison_enabled.on('change', function () {
            if (!$(this).is(':checked')) {
                ip_sqrip_status_awaiting.css({
                    'pointer-events': 'none',
                    'background-color': '#f2f2f2',
                    'cursor': 'not-allowed',
                    'opacity': '0.7'
                });
                ip_sqrip_status_completed.css({
                    'pointer-events': 'none',
                    'background-color': '#f2f2f2',
                    'cursor': 'not-allowed',
                    'opacity': '0.7'
                });
                ip_aworder_stt.prop('readonly', true);
                ip_order_stt.prop('readonly', true);
                $('.sqrip-btn-create-order-stt').attr('disabled', true);
            } else {
                ip_sqrip_status_awaiting.css({
                    'pointer-events': 'auto',
                    'background-color': '',
                    'cursor': '',
                    'opacity': ''
                });
                ip_sqrip_status_completed.css({
                    'pointer-events': 'auto',
                    'background-color': '',
                    'cursor': '',
                    'opacity': ''
                });
                ip_aworder_stt.prop('readonly', false);
                ip_order_stt.prop('readonly', false);
                $('.sqrip-btn-create-order-stt').attr('disabled', false);
            }
        });
    }

    default_order_status.on('change', function () {
        if ($(this).val() === 'wc-sqrip-default-status') {
            default_order_status.addClass('sqrip-rounded-red');
        } else {
            default_order_status.removeClass('sqrip-rounded-red');
        }

        validate_form()
    });

    if (nh.length) {
        nh.closest('tr').addClass('sqrip-no-height');
    }
});
