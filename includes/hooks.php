<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wpcf7_before_send_mail', 'zibal_cf7_before_send_mail', 10, 3 );

function zibal_cf7_before_send_mail( $contact_form, &$abort, $submission ) {
    $service = Zibal_CF7_Service::get_instance();
    
    if ( ! $service->is_active() ) {
        return;
    }
    
    $post_id = $contact_form->id();
    
    $enabled = get_post_meta( $post_id, '_zibal_enabled', true );
    
    if ( $enabled !== '1' ) {
        return;
    }
    
    $posted_data = $submission->get_posted_data();
    
    $amount = 0;
    
    $amount_field = get_post_meta( $post_id, '_zibal_amount_field', true );
    if ( $amount_field && isset( $posted_data[ $amount_field ] ) ) {
        $amount = absint( $posted_data[ $amount_field ] );
    }
    
    if ( ! $amount ) {
        $amount = absint( get_post_meta( $post_id, '_zibal_amount', true ) );
    }
    
    if ( $amount < 1000 ) {
        $submission->set_response( 
            __( 'خطا: مبلغ پرداخت باید حداقل 1,000 ریال باشد.', 'zibal-cf7' ) 
        );
        $submission->set_status( 'validation_failed' );
        $abort = true;
        return;
    }
    
    $email = isset( $posted_data['your-email'] ) ? sanitize_email( $posted_data['your-email'] ) : '';
    $mobile = isset( $posted_data['your-phone'] ) ? sanitize_text_field( $posted_data['your-phone'] ) : '';
    $message = isset( $posted_data['your-message'] ) ? sanitize_textarea_field( $posted_data['your-message'] ) : '';
    
    $description = get_post_meta( $post_id, '_zibal_description', true );
    if ( empty( $description ) ) {
        $description = sprintf( 
            __( 'پرداخت فرم %s', 'zibal-cf7' ), 
            $contact_form->title() 
        );
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    
    $insert_result = $wpdb->insert( 
        $table_name, 
        array(
            'form_id' => $post_id,
            'amount' => $amount,
            'email' => $email,
            'mobile' => $mobile,
            'description' => $description,
            'posted_data' => maybe_serialize( $posted_data ),
            'status' => 'pending',
        ),
        array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
    );
    
    if ( ! $insert_result ) {
        $submission->set_response( 
            __( 'خطا در ذخیره اطلاعات. لطفا دوباره تلاش کنید.', 'zibal-cf7' ) 
        );
        $submission->set_status( 'validation_failed' );
        $abort = true;
        return;
    }
    
    $transaction_id = $wpdb->insert_id;
    
    $callback_url = add_query_arg( 
        array(
            'zibal_verify' => 1,
            'transaction_id' => $transaction_id,
        ),
        home_url( '/' ) 
    );
    
    $api = new Zibal_CF7_API( $service->get_merchant_id() );
    
    $result = $api->request_payment( $amount, $callback_url, array(
        'mobile' => $mobile,
        'description' => $description,
        'orderId' => $transaction_id,
    ) );
    
    if ( ! $result || $result->result != 100 ) {
        $error_message = $result 
            ? Zibal_CF7_API::get_error_message( $result->result )
            : __( 'خطا در برقراری ارتباط با درگاه پرداخت.', 'zibal-cf7' );
        
        $submission->set_response( $error_message );
        $submission->set_status( 'payment_failed' );
        
        $wpdb->update(
            $table_name,
            array( 'status' => 'failed' ),
            array( 'id' => $transaction_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        $abort = true;
        return;
    }
    
    $wpdb->update(
        $table_name,
        array( 'track_id' => $result->trackId ),
        array( 'id' => $transaction_id ),
        array( '%s' ),
        array( '%d' )
    );
    
    $redirect_url = 'https://gateway.zibal.ir/start/' . $result->trackId;
    $form_id = $contact_form->id();
    
    set_transient( 'zibal_redirect_' . $form_id, $redirect_url, 300 );
    
    $submission->set_response( 
        __( 'در حال انتقال به درگاه پرداخت...', 'zibal-cf7' ) 
    );
    
    $submission->set_status( 'mail_sent' );
    
    if ( ! session_id() ) {
        @session_start();
    }
    $_SESSION['zibal_redirect_' . $form_id] = $redirect_url;
    $_SESSION['zibal_payment_mode_' . $form_id] = true;
    
    $abort = true;
}

add_action( 'template_redirect', 'zibal_cf7_verify_payment' );

function zibal_cf7_verify_payment() {
    if ( ! isset( $_GET['zibal_verify'] ) || ! isset( $_GET['transaction_id'] ) ) {
        return;
    }
    
    $transaction_id = absint( $_GET['transaction_id'] );
    $track_id = isset( $_GET['trackId'] ) ? sanitize_text_field( $_GET['trackId'] ) : '';
    $status = isset( $_GET['status'] ) ? absint( $_GET['status'] ) : 0;
    $success = isset( $_GET['success'] ) ? absint( $_GET['success'] ) : 0;
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    
    $transaction = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $transaction_id
    ) );
    
    if ( ! $transaction ) {
        wp_die( 
            __( 'تراکنش یافت نشد.', 'zibal-cf7' ),
            __( 'خطا', 'zibal-cf7' ),
            array( 'response' => 404 )
        );
    }
    
    if ( $transaction->status === 'success' ) {
        zibal_cf7_display_result( 
            'success',
            __( 'این تراکنش قبلا تایید شده است.', 'zibal-cf7' ),
            $transaction
        );
        exit;
    }
    
    if ( $status != 2 || $success != 1 ) {
        $wpdb->update(
            $table_name,
            array( 'status' => 'cancelled' ),
            array( 'id' => $transaction_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        zibal_cf7_display_result(
            'failed',
            __( 'پرداخت توسط کاربر لغو شد یا با خطا مواجه شد.', 'zibal-cf7' ),
            $transaction
        );
        exit;
    }
    
    $service = Zibal_CF7_Service::get_instance();
    
    if ( ! $service->is_active() ) {
        wp_die( __( 'سرویس زیبال فعال نیست.', 'zibal-cf7' ) );
    }
    
    $api = new Zibal_CF7_API( $service->get_merchant_id() );
    $result = $api->verify_payment( $track_id );
    
    if ( ! $result || $result->result != 100 ) {
        $error_message = $result 
            ? Zibal_CF7_API::get_error_message( $result->result )
            : __( 'خطا در تایید پرداخت.', 'zibal-cf7' );
        
        $wpdb->update(
            $table_name,
            array( 'status' => 'failed' ),
            array( 'id' => $transaction_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        zibal_cf7_display_result( 'failed', $error_message, $transaction );
        exit;
    }
    
    $wpdb->update(
        $table_name,
        array( 
            'status' => 'success',
            'ref_number' => isset( $result->refNumber ) ? $result->refNumber : '',
        ),
        array( 'id' => $transaction_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );
    
    zibal_cf7_send_mail_after_payment( $transaction );
    
    zibal_cf7_display_result(
        'success',
        sprintf(
            __( 'پرداخت با موفقیت انجام شد. شماره پیگیری: %s', 'zibal-cf7' ),
            $result->refNumber
        ),
        $transaction
    );
    exit;
}

function zibal_cf7_display_result( $status, $message, $transaction ) {
    $title = $status === 'success' 
        ? __( 'پرداخت موفق', 'zibal-cf7' )
        : __( 'پرداخت ناموفق', 'zibal-cf7' );
    
    $color = $status === 'success' ? '#4caf50' : '#f44336';
    $icon = $status === 'success' ? '✓' : '✗';
    
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html( $title ); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                background: #f5f5f5;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 20px;
                direction: rtl;
            }
            .result-container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                max-width: 500px;
                width: 100%;
                padding: 40px;
                text-align: center;
            }
            .result-icon {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: <?php echo esc_attr( $color ); ?>;
                color: white;
                font-size: 48px;
                line-height: 80px;
                margin: 0 auto 20px;
            }
            .result-title {
                font-size: 24px;
                color: #333;
                margin-bottom: 15px;
            }
            .result-message {
                font-size: 16px;
                color: #666;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .result-details {
                background: #f9f9f9;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
                text-align: right;
            }
            .result-details-item {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #eee;
            }
            .result-details-item:last-child {
                border-bottom: none;
            }
            .result-details-label {
                color: #999;
                font-size: 14px;
            }
            .result-details-value {
                color: #333;
                font-weight: 500;
            }
            .result-button {
                display: inline-block;
                background: <?php echo esc_attr( $color ); ?>;
                color: white;
                padding: 12px 30px;
                border-radius: 6px;
                text-decoration: none;
                font-size: 16px;
                transition: opacity 0.3s;
            }
            .result-button:hover {
                opacity: 0.9;
            }
        </style>
    </head>
    <body>
        <div class="result-container">
            <div class="result-icon"><?php echo esc_html( $icon ); ?></div>
            <h1 class="result-title"><?php echo esc_html( $title ); ?></h1>
            <p class="result-message"><?php echo esc_html( $message ); ?></p>
            
            <div class="result-details">
                <div class="result-details-item">
                    <span class="result-details-label"><?php echo esc_html__( 'مبلغ:', 'zibal-cf7' ); ?></span>
                    <span class="result-details-value"><?php echo number_format( $transaction->amount ); ?> <?php echo esc_html__( 'ریال', 'zibal-cf7' ); ?></span>
                </div>
                <?php if ( $transaction->track_id ) : ?>
                <div class="result-details-item">
                    <span class="result-details-label"><?php echo esc_html__( 'شماره پیگیری:', 'zibal-cf7' ); ?></span>
                    <span class="result-details-value"><?php echo esc_html( $transaction->track_id ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $transaction->ref_number ) : ?>
                <div class="result-details-item">
                    <span class="result-details-label"><?php echo esc_html__( 'شماره مرجع:', 'zibal-cf7' ); ?></span>
                    <span class="result-details-value"><?php echo esc_html( $transaction->ref_number ); ?></span>
                </div>
                <?php endif; ?>
                <div class="result-details-item">
                    <span class="result-details-label"><?php echo esc_html__( 'تاریخ:', 'zibal-cf7' ); ?></span>
                    <span class="result-details-value"><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $transaction->created_at ) ) ); ?></span>
                </div>
            </div>
            
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="result-button">
                <?php echo esc_html__( 'بازگشت به صفحه اصلی', 'zibal-cf7' ); ?>
            </a>
        </div>
    </body>
    </html>
    <?php
}

function zibal_cf7_send_mail_after_payment( $transaction ) {
    $contact_form = wpcf7_contact_form( $transaction->form_id );
    
    if ( ! $contact_form ) {
        return;
    }
    
    $posted_data = maybe_unserialize( $transaction->posted_data );
    
    if ( ! is_array( $posted_data ) ) {
        return;
    }
    
    $posted_data['_zibal_payment_status'] = 'success';
    $posted_data['_zibal_track_id'] = $transaction->track_id;
    $posted_data['_zibal_ref_number'] = $transaction->ref_number;
    $posted_data['_zibal_amount'] = $transaction->amount;
    
    $submission = WPCF7_Submission::get_instance();
    
    if ( $submission ) {
        $contact_form->submit();
    }
}

add_action( 'rest_api_init', 'zibal_cf7_register_rest_route' );

function zibal_cf7_register_rest_route() {
    register_rest_route( 'zibal-cf7/v1', '/redirect/(?P<form_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'zibal_cf7_get_redirect_url',
        'permission_callback' => '__return_true',
        'args' => array(
            'form_id' => array(
                'required' => true,
                'validate_callback' => function( $param ) {
                    return is_numeric( $param );
                }
            ),
        ),
    ) );
}

function zibal_cf7_get_redirect_url( $request ) {
    $form_id = absint( $request->get_param( 'form_id' ) );
    
    if ( ! $form_id ) {
        return new WP_Error( 
            'invalid_form_id', 
            'Invalid form ID', 
            array( 'status' => 400 ) 
        );
    }
    
    $url = get_transient( 'zibal_redirect_' . $form_id );
    
    if ( $url ) {
        delete_transient( 'zibal_redirect_' . $form_id );
        return array(
            'success' => true,
            'redirect_url' => esc_url_raw( $url ),
            'source' => 'transient'
        );
    }
    
    if ( ! session_id() ) {
        @session_start();
    }
    
    if ( isset( $_SESSION['zibal_redirect_' . $form_id] ) ) {
        $url = $_SESSION['zibal_redirect_' . $form_id];
        unset( $_SESSION['zibal_redirect_' . $form_id] );
        return array(
            'success' => true,
            'redirect_url' => esc_url_raw( $url ),
            'source' => 'session'
        );
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    
    $transaction = $wpdb->get_row( $wpdb->prepare(
        "SELECT track_id FROM $table_name 
         WHERE form_id = %d AND status = 'pending' 
         ORDER BY id DESC LIMIT 1",
        $form_id
    ) );
    
    if ( $transaction && $transaction->track_id ) {
        $url = 'https://gateway.zibal.ir/start/' . sanitize_text_field( $transaction->track_id );
        return array(
            'success' => true,
            'redirect_url' => esc_url_raw( $url ),
            'source' => 'database'
        );
    }
    
    return new WP_Error( 
        'no_redirect_url', 
        'Redirect URL not found', 
        array( 'status' => 404 ) 
    );
}
