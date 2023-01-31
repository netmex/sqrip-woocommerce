jQuery( document ).ready(function($){

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
    ip_refund_token = $('#woocommerce_sqrip_return_token'),
    btn_toggle_stt = $('.sqrip-toggle-order-satus'),
    ip_enb_new_status = $('#woocommerce_sqrip_enabled_new_status'),
    ip_ft_new_status = $('#woocommerce_first_time_new_status');

    if (ip_token.length) {
        bt_check_token_html = '<button id="btn_sqrip_check_token" class="button-secondary sqrip-btn-validate-token">'+sqrip.txt_check_connection+'</button>';
        ip_token.siblings('.description').after(bt_check_token_html);

        bt_check_token = $('#btn_sqrip_check_token');
        bt_check_token.on('click', function(e){
            e.preventDefault();
            _this = $(this);
            _output = $(this).closest('td.forminp');
            _output.find('.sqrip-notice').remove();

            if( ip_token.val().trim().length < 1 ) {
                ip_token.focus();
                return; 
            }

            $.ajax({
                type : "post", 
                url : sqrip.ajax_url, 
                data : {
                    action: "sqrip_validation_token", 
                    token: ip_token.val()
                },
                beforeSend: function(){
                   $('body').addClass('sqrip-loading');
                },
                success: function(response) {
                    if(response) {
                        if (response.result) {
                            result = "updated";
                        } else {
                            result = "error";
                        }

                        output_html = '<div class="sqrip-notice mt-10 '+result+'">';
                        output_html += '<p>'+response.message+'</p>';
                        output_html += '</div>';
                        _this.after(output_html);

                        if (response.address) {
                            ip_address.find('option[value="sqrip"]').text(response.address);
                        }
                    }
                },
                error: function( jqXHR, textStatus, errorThrown ){
                    console.log( 'The following error occured: ' + textStatus, errorThrown );
                },
                complete: function(){
                    $('body').removeClass('sqrip-loading');
                }
            })
        })
    }

    ip_file_type.on('change',function(){
        ip_itgr_email.prop('selectedIndex',0);
        if( this.value == "png" ){
            ip_itgr_email.find('option[value="body"]').show();
            ip_itgr_email.find('option[value="both"]').show();
        }
        else{
            ip_itgr_email.find('option[value="body"]').hide();
            ip_itgr_email.find('option[value="both"]').hide();
        }
    });

    init_individual_address(ip_address.val());

    ip_address.on('change', function(){
        _val =  $(this).val();
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
        bt_check_iban_html = '<button id="btn_sqrip_check_iban" class="button-secondary sqrip-btn-validate">'+sqrip.txt_validate_iban+'</button>';
        ip_iban.after(bt_check_iban_html);

        bt_check_iban = $('#btn_sqrip_check_iban');
        bt_check_iban.on('click', function(e){
            e.preventDefault();
            _this = $(this);
            _output = _this.closest('td.forminp');
            _output.find('.sqrip-notice').remove();
            _output.find('.sqrip-description').remove();
            _output.find('.sqrip-bank').remove();

            $.ajax({
                type : "post", 
                url : sqrip.ajax_url, 
                data : {
                    action: "sqrip_validation_iban", 
                    iban: ip_iban.val(),
                    token: ip_token.val()
                },
                beforeSend: function(){
                   $('body').addClass('sqrip-loading');
                },
                success: function(response) {
                    if(response) {
                        if (response.result) {
                            result = "updated";
                        } else {
                            result = "error";
                        }
                        output_html = '<div class="sqrip-notice '+result+'">';
                        output_html += '<p>'+response.message+'</p>';
                        output_html += '</div><p class="sqrip-bank"></p><p class="sqrip-description"></p>';
                        _this.after(output_html);
                        _this.siblings('.sqrip-description').html(response.description);
                        _this.siblings('.sqrip-bank').html(response.bank);

                        if (response.qriban) {
                            ip_qrref_format.closest('tr').show();
                        } else {
                            ip_qrref_format.closest('tr').hide();
                        }
                    }
                },
                error: function( jqXHR, textStatus, errorThrown ){
                    console.log( 'The following error occured: ' + textStatus, errorThrown );
                },
                complete: function(){
                    $('body').removeClass('sqrip-loading');
                }
            })
        })
    }

    ip_test_email = $('#woocommerce_sqrip_test_email');

    ip_test_email.closest('tr').hide();

    if (btn_save.length){
        bt_test_email_html = '<button id="btn_test_email" class="button-secondary qrinvoice-tab sqrip-btn-send-test-email">'+sqrip.txt_send_test_email+'</button>';
        btn_save.after(bt_test_email_html);

        bt_test_email = $("#btn_test_email");
        bt_test_email.on('click', function(e){
            e.preventDefault();
            $('#woocommerce_sqrip_test_email').prop('checked', true);

            btn_save.trigger('click');
        });
    }

    tab_active = window.location.hash.slice(1);
    if (!tab_active) tab_active = "qrinvoice";
    sqrip_tab_init(tab_active);
    
    tab.on('click', function(e){
        e.preventDefault();
        sqrip_tab_init($(this).data('tab'));
    }) 

    $('.sqrip-tabs').siblings('table.form-table').find('td p.description').each(function(i, e){
        wrap = $(this).closest('tr').find('th');
        
        wrap.append($(this));
    })

    if(sqrip_additional_information.length) {
        sqrip_additional_information.attr("maxlength",140);
        sqrip_additional_information.attr("rows", 4);
    }

    function init_individual_address(_val){
        if (_val == 'individual') {
            $('.sqrip-address-individual').prop('required', true).closest('tr').show();
        } else {
            $('.sqrip-address-individual').prop('required', false).closest('tr').hide();
        }
    }

    function sqrip_tab_init(data){
        window.location.hash = data;
        tab.removeClass('active');
        $('.sqrip-tab[data-tab="'+data+'"]').addClass('active');
        tab_des = $('.sqrip-tabs-description');
        tab_des.find('.sqrip-tab-description').hide();
        tab_des.find('.sqrip-tab-description[data-tab="'+data+'"]').show();

        table = $('.sqrip-tabs').siblings('.form-table');
        table.find('tr').hide();
        $('.sqrip-btn-send-test-email').hide();
        table.find('.'+data+'-tab').closest('tr').show();

        if (data == "qrinvoice") {
            init_individual_address(ip_address.val());
            init_ip_qrref_format();
            $('.sqrip-btn-send-test-email').show();
        }

        // else if (data == "comparison") {
        //     toggle_service_options(ebics_service.is(':checked'), $('.ebics-service'));
        //     toggle_service_options(camt_service.is(':checked'), $('.camt-service'));
        // }
    }

    function init_ip_qrref_format(){
        if (ip_qrref_format.hasClass('hide')) {
            ip_qrref_format.closest('tr').hide();
        }
    }

    if (ip_refund_token.length) {
        bt_check_refund_token_html = '<button id="btn_sqrip_check_refund_token" class="button-secondary sqrip-btn sqrip-btn-validate-token">'+sqrip.txt_check_connection+'</button>';
        ip_refund_token.after(bt_check_refund_token_html);

        bt_check_refund_token = $('#btn_sqrip_check_refund_token');
        bt_check_refund_token.on('click', function(e){
            e.preventDefault();
            _this = $(this);
            _output = $(this).closest('td.forminp');
            _output.find('.sqrip-notice').remove();

            if( ip_refund_token.val().trim().length < 1 ) {
                ip_refund_token.focus();
                return; 
            }

            $.ajax({
                type : "post", 
                url : sqrip.ajax_url, 
                data : {
                    action: "sqrip_validation_refund_token", 
                    token: ip_refund_token.val()
                },
                beforeSend: function(){
                   $('body').addClass('sqrip-loading');
                },
                success: function(response) {
                    if(response) {
                        if (response.success) {
                            success = "updated";
                        } else {
                            success = "error";
                        }

                        output_html = '<div class="sqrip-notice '+success+'">';
                        output_html += '<p>'+response.message+'</p>';
                        output_html += '</div>';
                        _this.after(output_html);

                    }
                },
                error: function( jqXHR, textStatus, errorThrown ){
                    console.log( 'The following error occured: ' + textStatus, errorThrown );
                },
                complete: function(){
                    $('body').removeClass('sqrip-loading');
                }
            })
        })
    }

    if (ip_order_stt.length) {
        ip_order_stt.closest('tr').addClass('sqrip-order-status');
        ip_ft_new_status.prop('checked', false);

        btn_create_order_html = '<button id="btn_create_order_stt" class="button-secondary sqrip-btn sqrip-btn-create-order-stt">'+sqrip.txt_create+'</button>';
        ip_order_stt.after(btn_create_order_html);

        btn_toggle_stt.on('click', function(e){
            e.preventDefault();

            $(this).closest('tr').toggleClass('sqrip-show');
        })

        btn_create_order = $('#btn_create_order_stt');

        btn_create_order.on('click', function(e){
            e.preventDefault();

            if (!ip_order_stt.val()) {
                ip_order_stt.focus();
            } else {
                ip_enb_new_status.prop('checked', true);
                ip_ft_new_status.prop('checked', true);
                setTimeout(function(){
                    btn_save.trigger('click');
                }, 200);
            }
        })
    }
});