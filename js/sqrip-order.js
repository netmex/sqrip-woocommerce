jQuery( document ).ready(function($){

    var btn_regenerate_qrcode = $('button.sqrip-re-generate-qrcode'),
    btn_confirm = $('button.sqrip-payment-confirmed');

    btn_regenerate_qrcode.on('click', function(e){

        e.preventDefault();

        _form = $('form#post');

        $('body').addClass('sqrip-loading');
  
        if ( _form.length ) {

            _form.prepend('<input type="hidden" id="_sqrip_regenerate_qrcode" name="_sqrip_regenerate_qrcode" value="1">');
            _form.trigger('submit');

        }
        
    });

    btn_initiate_payment = $('button.sqrip-initiate-payment');

    btn_initiate_payment.on('click', function(e){

        e.preventDefault();

        _form = $('form#post');

        $('body').addClass('sqrip-loading');
  
        if ( _form.length ) {

            _form.prepend('<input type="hidden" id="_sqrip_initiate_payment" name="_sqrip_initiate_payment" value="1">');
            _form.trigger('submit');

        }
        
    });

    // returns
    // show / hide QR code in returns
    $(".woocommerce_sqrip_toggle_qr").on("click", function(e) {
        e.preventDefault();
        var qr_div = $(this).siblings('.woocommerce_sqrip_qr_wrapper').first();
        var title;

        // check visibility
        if( qr_div.is(":visible") ) {
            qr_div.hide();
            title = $(this).data('title');
        } else {
            qr_div.show();
            title = $(this).data('titleHide');
        }

        // change display text in link
        $(this).attr('title', title);
        $(this).text(title);
    });

    // mark sqrip refund as paid
    $(".woocommerce_sqrip_refund_paid").on("click", function(e) {
       e.preventDefault();
        var _this = $(this);
        var refund_id = _this.data('refund');
        var title = _this.text();
        $.ajax({
            type : "post",
            url : sqrip.ajax_url,
            data : {
                action: "sqrip_mark_refund_paid",
                security: sqrip.ajax_refund_paid_nonce,
                refund_id: refund_id
            },
            beforeSend: function(){
                _this.prop( "disabled", true );
                _this.text('Loading...');
            },
            success: function(response) {
                if (response.result === 'success') {
                    _this.text('Success generated! Redirecting...');
                    _this.siblings('.woocommerce_sqrip_toggle_qr').first().hide();
                    _this.siblings('.woocommerce_sqrip_qr_wrapper').first().hide();
                    _this.hide();
                    _this.siblings('.woocommerce_sqrip_refund_unpaid').first().show();
                    var status_div = _this.siblings('.woocommerce_sqrip_refund_status').first();
                    status_div.text("[" + status_div.data('paid') + " " + response.date + "]");
                }  else {
                    _this.text(title);
                    alert('An error occurred. Please try again.');
                }
            },
            error: function( jqXHR, textStatus, errorThrown ){
                _this.text(title);
                alert('An error occurred. Please try again.');
            },
            complete: function(){
                _this.text(title);
                _this.prop( "disabled", false );
            }
        })
    });
    
    btn_confirm.on('click', function(e){

        e.preventDefault();

        _form = $('form#post');

        $('body').addClass('sqrip-loading');
  
        if ( _form.length ) {

            jQuery('#order_status').val(sqrip.status_completed);            
            _form.trigger('submit');

        }
        
    });

    // mark sqrip refund as unpaid
    $(".woocommerce_sqrip_refund_unpaid").on("click", function(e) {
        e.preventDefault();
        var _this = $(this);
        var refund_id = _this.data('refund');
        var title = _this.text();
        $.ajax({
            type : "post",
            url : sqrip.ajax_url,
            data : {
                action: "sqrip_mark_refund_unpaid",
                security: sqrip.ajax_refund_unpaid_nonce,
                refund_id: refund_id
            },
            beforeSend: function(){
                _this.prop( "disabled", true );
                _this.text('Loading...');
            },
            success: function(response) {
                if (response.result === 'success') {
                    _this.siblings('.woocommerce_sqrip_toggle_qr').first().show();
                    _this.siblings('.woocommerce_sqrip_qr_wrapper').first().hide();
                    _this.hide();
                    _this.siblings('.woocommerce_sqrip_refund_paid').first().show();

                    var status_div = _this.siblings('.woocommerce_sqrip_refund_status').first();
                    status_div.text("[" + status_div.data('unpaid') + "]");

                }  else {
                    _this.text(title);
                    alert('An error occurred. Please try again.');
                }
            },
            error: function( jqXHR, textStatus, errorThrown ){
                _this.text(title);
                alert('An error occurred. Please try again.');
            },
            complete: function(){
                _this.text(title);
                _this.prop( "disabled", false );
            }
        })
    });

});

