jQuery( document ).ready(function($){
    $('#woocommerce_sqrip_file_type').on('change',function(){
        $('#woocommerce_sqrip_integration_email').prop('selectedIndex',0);
        if( this.value == "png" ){
            $('#woocommerce_sqrip_integration_email option[value="body"]').show();
            $('#woocommerce_sqrip_integration_email option[value="both"]').show();
        }
        else{
            $('#woocommerce_sqrip_integration_email option[value="body"]').hide();
            $('#woocommerce_sqrip_integration_email option[value="both"]').hide();
        }
    });

});