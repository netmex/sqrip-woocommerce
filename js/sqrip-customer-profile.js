jQuery( document ).ready(function($){
    const ibanCheckBtn = $('#btn_sqrip_check_iban');
    const ip_token = $('#sqrip-refund-token');

    ibanCheckBtn.on('click', function (e) {
        e.preventDefault();

        const customerIBAN = document.querySelector('#iban')?.value;
        _this = $(this);
        _output = _this.closest('td');
        _output.find('.sqrip-notice').remove();
        _output.find('.sqrip-description').remove();
        _output.find('.sqrip-bank').remove();

        $.ajax({
            type: "post",
            url: sqrip.ajax_url,
            data: {
                action: "sqrip_validation_iban",
                iban: customerIBAN,
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
                    output_html = '<div style="margin-top:10px;" class="sqrip-notice ' + result + '">';
                    output_html += '<p>' + response.message + '</p>';
                    output_html += '</div><p class="sqrip-bank"></p><p class="sqrip-description"></p>';
                    _this.after(output_html);
                    _this.siblings('.sqrip-description').html(response.description);
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

});