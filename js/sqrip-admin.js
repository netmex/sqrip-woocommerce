jQuery( document ).ready(function($){

    var ip_file_type = $('#woocommerce_sqrip_file_type'),
    ip_itgr_email = $('#woocommerce_sqrip_integration_email'),
    ip_address = $('#woocommerce_sqrip_address'),
    ip_iban = $('#woocommerce_sqrip_iban'),
    ip_iban_type = $('#woocommerce_sqrip_iban_type'),
    ip_token = $('#woocommerce_sqrip_token'),
    btn_save = $('button.woocommerce-save-button'),
    tab = $('.sqrip-tab'),
    tab_active = $('.sqrip-tabs').find('.sqrip-tab.active'),
    cn_service = $('#woocommerce_sqrip_ebics_service'),
    pm_frequence = $('#woocommerce_sqrip_payment_frequence'),
    rem_creds = $('#woocommerce_sqrip_remaining_credits');

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

                        output_html = '<div class="sqrip-notice '+result+'">';
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
                        output_html += '</div>';
                        _this.after(output_html);
                        _this.siblings('.description').html(response.description);
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
        bt_test_email_html = '<button id="btn_test_email" class="button-secondary sqrip-btn-send-test-email">'+sqrip.txt_send_test_email+'</button>';
        btn_save.after(bt_test_email_html);

        bt_test_email = $("#btn_test_email");
        bt_test_email.on('click', function(e){
            e.preventDefault();
            $('#woocommerce_sqrip_test_email').prop('checked', true);

            btn_save.trigger('click');
        });
    }
   

    tab.on('click', function(e){
        e.preventDefault();
        tab.removeClass('active');
        $(this).addClass('active');
        sqrip_tab_init($(this).data('tab'));

    })


    if ( cn_service.length ) {

        if (cn_service.is(':checked')) {

            // cn_service.prop('disabled', true);

        } else {

            cn_sv_row = cn_service.closest('tr');
            cn_sv_label = cn_sv_row.find('th label');
            cn_sv_label.hide();
            cn_sv_row.find('td > fieldset').hide();

            bt_cn_sv_html = '<button id="btn_sqrip_connect_service" class="button-connect-service">'+cn_sv_label.text()+'</button>';

            cn_sv_row.find('th').append(bt_cn_sv_html);

            bt_cn_sv = $('#btn_sqrip_connect_service');
            bt_cn_sv.on('click', function(e){
                e.preventDefault();

                _this = $(this);
                _output = cn_sv_row.find('td.forminp');
                _output.find('.sqrip-notice').remove();

                if( ip_token.val().trim().length < 1 ) {
                    ip_token.focus();
                    return; 
                }

                $.ajax({
                    type : "post", 
                    url : sqrip.ajax_url, 
                    data : {
                        action: "sqrip_connect_ebics_service", 
                        token: ip_token.val()
                    },
                    beforeSend: function(){
                       $('body').addClass('sqrip-loading');
                    },
                    success: function(response) {
                        if(response) {
                            result = "updated";
                            cn_service.prop('checked', true);
                            message = response.message;

                            if (response.remaining_credits) {
                                rem_creds.val(response.remaining_credits);
                            } 

                        } else {
                            result = "error";
                            cn_service.prop('checked', false);
                            message = 'Error';
                        }


                        output_html = '<div class="sqrip-notice '+result+'">';
                        output_html += '<p>'+message+'</p>';
                        output_html += '</div>';
                        _output.append(output_html);

                        sqrip_tab_init('comparison');
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
    }

    sqrip_tab_init(tab_active.data('tab'));

    function init_individual_address(_val){
        if (_val == 'individual') {
            $('.sqrip-address-individual').prop('required', true).closest('tr').show();
        } else {
            $('.sqrip-address-individual').prop('required', false).closest('tr').hide();
        }
    }

    function sqrip_tab_init(data){

        tab_des = $('.sqrip-tabs-description');

        tab_des.find('.sqrip-tab-description').hide();
        tab_des.find('.sqrip-tab-description[data-tab="'+data+'"]').show();

        table = $('.sqrip-tabs').siblings('.form-table');
        table.find('tr').hide();
        table.find('.'+data+'-tab').closest('tr').show();

        if (data == "qrinvoice") {
            init_individual_address(ip_address.val());
        }

        else if (data == "comparison") {
            init_comparison_tab(cn_service, table);
        }
    }

    function init_comparison_tab(connect, table) {
        _val = connect.is(':checked');
        if (_val === false) {
            table.find('tr').hide();
            connect.closest('tr').show();
        }
    }

});