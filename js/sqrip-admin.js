jQuery( document ).ready(function($){

    var ip_file_type = $('#woocommerce_sqrip_file_type'),
    ip_itgr_email = $('#woocommerce_sqrip_integration_email'),
    ip_address = $('#woocommerce_sqrip_address'),
    ip_iban = $('#woocommerce_sqrip_iban'),
    ip_iban_type = $('#woocommerce_sqrip_iban_type');

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


    ip_address.on('change', function(){
        _output = $(this).closest('td.forminp');
        _output.find('.sqrip-preview-address').remove();
        $.ajax({
            type : "post", 
            url : sqrip.ajax_url, 
            data : {
                action: "sqrip_preview_address", 
                address: $(this).val()
            },
            beforeSend: function(){
               $('body').addClass('sqrip-loading');
            },
            success: function(response) {
                
                if(response && response.name) {
                    output_html = '<div class="sqrip-preview-address">';
                    output_html += '<h4>Preview address</h4>';
                    output_html += '<p>Name: <b>' + response.name + '</b></p>';
                    output_html += '<p>Street: <b>' + response.street + '</b></p>';
                    output_html += '<p>City: <b>' + response.city + '</b></p>';
                    output_html += '<p>Postal Code: <b>' + response.postal_code + '</b></p>';
                    output_html += '<p>Country Code: <b>' + response.country_code + '</b></p>';
                    output_html += '</div>';

                    _output.append(output_html);
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

    if (ip_iban.length) {
        bt_check_iban_html = '<button id="btn_sqrip_check_iban" class="button-secondary sqrip-btn-validate">Validate</button>';
        ip_iban.after(bt_check_iban_html);

        bt_check_iban = $('#btn_sqrip_check_iban');
        bt_check_iban.on('click', function(e){
            e.preventDefault();
            _output = $(this).closest('td.forminp');
            _output.find('.sqrip-notice').remove();

            $.ajax({
                type : "post", 
                url : sqrip.ajax_url, 
                data : {
                    action: "sqrip_validation_iban", 
                    iban: ip_iban.val(),
                    iban_type: ip_iban_type.val()
                },
                beforeSend: function(){
                   $('body').addClass('sqrip-loading');
                },
                success: function(response) {
                    if(response) {
                        output_html = '<div class="sqrip-notice">';
                        output_html += '<p>'+response.message+'</p>';
                        output_html += '</div>';
                        _output.append(output_html);
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
});