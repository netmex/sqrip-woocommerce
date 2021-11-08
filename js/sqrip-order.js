jQuery( document ).ready(function($){

    btn_regenerate_qrcode = $('button.sqrip-re-generate-qrcode');

    btn_regenerate_qrcode.on('click', function(e){

        e.preventDefault();

        _form = $('form#post');

        $('body').addClass('sqrip-loading');
  
        if ( _form.length ) {

            _form.prepend('<input type="hidden" id="_sqrip_regenerate_qrcode" name="_sqrip_regenerate_qrcode" value="1">');
            _form.trigger('submit');

        }
        
    });


});