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
                order_id: order_id
            },
            beforeSend: function(){
                _this.text('Generating...');
            },
            success: function(response) {
                if (response.result == 'success') {
                    _this.text('Success generated! Redirecting...');
                    window.location.href = response.redirect;
                }  else {
                    _this.text('Some error occurred! Please try again later.');
                } 
            },
            error: function( jqXHR, textStatus, errorThrown ){
                _this.text('Some error occurred! Please try again later.');
                console.log( 'The following error occured: ' + textStatus, errorThrown );
            }
        })
    })

});