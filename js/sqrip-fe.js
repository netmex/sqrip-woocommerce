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

});