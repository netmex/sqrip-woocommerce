jQuery( document ).ready(function($){

    $('#sqripGenerateNewQRCode').on('click', function(e){
        e.preventDefault();
        _this = $(this);
        order_id = _this.data('order');
        $.ajax({
            type : "post", 
            url : sqrip.ajax_url, 
            data : {
                action: "sqrip_generate_new_qr_code", 
                security: sqrip.ajax_nonce,
                order_id: order_id
            },
            beforeSend: function(){
                _this.prop( "disabled", true );
                _this.text('Generating...');
            },
            success: function(response) {
                if (response.result == 'success') {
                    _this.text('Success generated! Redirecting...');
                    window.location.href = response.redirect;
                }  else {
                    _this.text('Some error occurred! Try gain.');
                } 
            },
            error: function( jqXHR, textStatus, errorThrown ){
                _this.text('Some error occurred! Try gain.');
            },
            complete: function(){
                _this.prop( "disabled", false );
            }
        })
    })

    // While QR-invoice generation at checkout is suppressed there is nothing for the
    // customer to pay yet, and following the "Pay" button ends in an error. Show the
    // button but make it inert. The orders list is handled server side (see
    // sqrip_disable_pay_action_when_suppressed); this covers the single order view,
    // where WooCommerce renders the button from its own template.
    let invoice_info = $('#sqrip-invoice-info');
    if (invoice_info.length && $(invoice_info).data('suppressed') == 'yes') {
        $(".woocommerce-button.pay")
            .addClass('sqrip-pay-disabled')
            .attr('aria-disabled', 'true');
    }

    // Keyboard activation still fires on a focused link, so block it explicitly.
    $(document).on('click', '.sqrip-pay-disabled', function (e) {
        e.preventDefault();
    });

});