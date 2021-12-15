<?php 
/**
 * sqrip Generate new qr code ajax
 *
 * @since 1.0
 */

add_action( 'wp_ajax_sqrip_generate_new_qr_code', 'sqrip_generate_new_qr_code' );

function sqrip_generate_new_qr_code()
{
    check_ajax_referer('sqrip-generate-new-qrcode', 'security');

    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $order    = wc_get_order( $order_id );

    if ( ! $order ) {
        return;
    }

    $user_id   = $order->get_user_id();
    $cur_user_id = get_current_user_id();

    if ($user_id == $cur_user_id) {
        $sqrip_payment = new WC_Sqrip_Payment_Gateway;
        $process_payment = $sqrip_payment->process_payment($order_id);

        wp_send_json($process_payment);
    }
      
    die();
}

/**
 * sqrip preview address
 *
 * @since 1.0.3
 */

add_action( 'wp_ajax_sqrip_preview_address', 'sqrip_preview_address_ajax' );

function sqrip_preview_address_ajax()
{
    if (!$_POST['address']) return;

    $address = $_POST['address'];

    $response = sqrip_get_payable_to_address($address);

    wp_send_json($response);
      
    die();
}


/**
 * sqrip validation IBAN
 *
 * @since 1.0.3
 */

add_action( 'wp_ajax_sqrip_validation_iban', 'sqrip_validation_iban_ajax' );

function sqrip_validation_iban_ajax()
{
    if (!$_POST['iban'] || !$_POST['token']) return;

    $iban = $_POST['iban'];
    $token = $_POST['token'];

    $response = sqrip_validation_iban($iban, $token);
    $result = [];
    switch ($response->message) {
        case 'Valid simple IBAN':
            $result['result'] = true;
            $result['message'] = __( "validiert" , "sqrip" );
            $result['description'] = __('Das ist eine normale IBAN. Der Kunde kann Einzahlungen ohne Vermerk der Referenznummer (RF...) tätigen. Der automatische Abgleich mit den Bestellungen ist daher nicht durchgehend gewährleistet. Eine manuelle Bearbeitung kann nötig sein. Für den automatischen Abgleich ist eine QR-IBAN notwendig. Diese ist für die gleiche Kontoverbindung verfügbar. Informationen dazu gibt es bei deiner Bank.', 'sqrip');
            break;
        
        case 'Valid qr IBAN':
            $result['result'] = true;
            $result['message'] = __( "validiert" , "sqrip" );
            $result['description'] = __('Das ist eine QR-IBAN. Der Kunde kann Zahlungen nur mit Angabe einer QR-Referenz(nummer) ausführen. Du kannst die Einzahlung eindeutig einem Kunden / einer Bestellung zuweisen. Damit wird der automatische Abgleich der eingegangenen Zahlungen mit den Bestellungen möglich. Möchtest du diesen Schritt automatisieren? Kontaktiere uns <a href="mailto:info@sqrip.ch">info@sqrip.ch</a>.', 'sqrip');
            break;

        default:
            $result['result'] = false;
            $result['message'] = __( "fehlerhaft" , "sqrip" );
            $result['description'] = __('Die (QR-)IBAN deines Kontos, auf das die Überweisung erfolgen soll, ist FEHLERHAFT.', 'sqrip');
            break;
    }

    wp_send_json($result);
      
    die();
}

/**
 * sqrip validation IBAN
 *
 * @since 1.0.3
 */

add_action( 'wp_ajax_sqrip_validation_token', 'sqrip_validation_token_ajax' );

function sqrip_validation_token_ajax()
{
    if ( !$_POST['token'] ) return;   

    $response = sqrip_get_user_details( $_POST['token'] );

    if ($response) {
        $address_txt = __('vom sqrip-Konto: ','sqrip');
        $address_txt .= $response['name'].', '.$response['street'].', '.$response['city'].', '.$response['postal_code'].' '.$response['city'];

        $result['result'] = true;
        $result['message'] = __("API Schlüssel bestätigt", "sqrip");
        $result['address'] = $address_txt;
    } else {
        $result['result'] = false;
        $result['message'] = __("API Schlüssel NICHT bestätigt", "sqrip");
    }

    wp_send_json($result);
      
    die();
}


add_action( 'wp_ajax_sqrip_mark_refund_paid', 'sqrip_mark_refund_paid' );

/**
 * Ajax action to mark a sqrip refund as paid
 */
function sqrip_mark_refund_paid()
{
	check_ajax_referer('sqrip-mark-refund-paid', 'security');

	$refund_id = isset( $_POST['refund_id'] ) ? absint( $_POST['refund_id'] ) : 0;
	$refund    = wc_get_order( $refund_id );

	if ( !$refund ) { return; }

	// stores the current date and time
	$date = date('Y-m-d H:i:s');
	$refund->update_meta_data('sqrip_refund_paid', $date);
	$refund->save();

	wp_send_json(['date' => $date, 'result' => 'success']);

	die();
}

add_action( 'wp_ajax_sqrip_mark_refund_unpaid', 'sqrip_mark_refund_unpaid' );

/**
 * Ajax action to mark a sqrip refund as unpaid
 */
function sqrip_mark_refund_unpaid()
{
	check_ajax_referer('sqrip-mark-refund-unpaid', 'security');

	$refund_id = isset( $_POST['refund_id'] ) ? absint( $_POST['refund_id'] ) : 0;
	$refund    = wc_get_order( $refund_id );

	if ( !$refund ) { return; }

	$refund->delete_meta_data('sqrip_refund_paid');
	$refund->save();

	wp_send_json(['result' => 'success']);

	die();
}
