jQuery( document ).ready(function($){

    const refundActions = $('.refund-actions');
    const customerIBAN = document.querySelector('#sqrip-customer-iban')?.value;
    const ip_token = $('#sqrip-refund-token');
    const order_id = $('#post_ID')
    const refundBtns = document.querySelectorAll('.do-api-refund');
    let sqripRefundBtn = null;

    refundBtns.forEach((btn) => {
        if (btn.innerHTML.includes('sqrip')) {
            sqripRefundBtn = btn;
        }
    });

    if (sqripRefundBtn && (!customerIBAN || !ip_token.val())) {
        sqripRefundBtn.setAttribute('disabled', 'true');
    }


    const ibanLabel = document.createElement("label");
    ibanLabel.textContent = "Customer IBAN:";
    const ibanBox = document.createElement("div");
    ibanBox.classList.add('sqrip-iban-check-container')
    const ibanInput = document.createElement('input');
    ibanInput.setAttribute('id', 'sqrip-customer-iban-input');
    const ibanCheckBtn = document.createElement('button');
    ibanCheckBtn.textContent = "Check";
    ibanCheckBtn.classList.add("button-secondary");
    ibanCheckBtn.setAttribute('id', 'sqrip-iban-check-store');
    if (customerIBAN) {
        ibanInput.value = customerIBAN;
    }

    ibanCheckBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const customerIBAN = document.querySelector('#sqrip-customer-iban-input')?.value;
        _this = $(this);
        _output = _this.closest('.sqrip-iban-check-container');
        _output.find('.sqrip-notice').remove();
        _output.find('.sqrip-description').remove();
        _output.find('.sqrip-bank').remove();

        $.ajax({
            type: "post",
            url: sqrip.ajax_url,
            data: {
                action: "sqrip_validation_iban",
                iban: customerIBAN,
                token: ip_token.val(),
                store_iban: true,
                security: sqrip.ajax_refund_nonce,
                order_id: order_id.val(),
            },
            beforeSend: function () {
                $('body').addClass('sqrip-loading');
            },
            success: function (response) {
                if (response) {
                    if (response.result) {
                        result = "updated";

                        if (sqripRefundBtn && sqripRefundBtn.hasAttribute('disabled')) {
                            sqripRefundBtn.removeAttribute('disabled');
                        }
                    } else {
                        result = "error";
                    }
                    output_html = '<div class="sqrip-notice ' + result + '">';
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
    });

    ibanLabel.setAttribute('for', 'sqrip-customer-iban-input');
    ibanLabel.style.marginRight = "8px";

    ibanInput.setAttribute('type', 'text');
    ibanInput.style.marginBottom = "1rem";
    ibanBox.appendChild(ibanLabel);
    ibanBox.appendChild(ibanInput);
    ibanBox.appendChild(ibanCheckBtn);
    refundActions.prepend(ibanBox);

});