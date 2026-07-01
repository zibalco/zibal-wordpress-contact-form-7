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
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'payment_validation',
                __( 'مبلغ پرداخت کمتر از حداقل مجاز بود.', 'zibal-cf7' ),
                array(
                    'form_id' => $post_id,
                    'amount' => $amount,
                ),
                'warning'
            );
        }

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

    if ( function_exists( 'zibal_cf7_install_schema' ) ) {
        zibal_cf7_install_schema();
    }

    if ( function_exists( 'zibal_cf7_ensure_transaction_schema' ) ) {
        zibal_cf7_ensure_transaction_schema();
    }
    
    $callback_token = wp_generate_password( 32, false, false );
    $client_token = isset( $posted_data['_zibal_client_token'] )
        ? sanitize_text_field( wp_unslash( $posted_data['_zibal_client_token'] ) )
        : '';

    if ( empty( $client_token ) ) {
        $client_token = wp_generate_password( 32, false, false );
    }

    $insert_result = $wpdb->insert( 
        $table_name, 
        array(
            'form_id' => $post_id,
            'callback_token' => $callback_token,
            'amount' => $amount,
            'email' => $email,
            'mobile' => $mobile,
            'description' => $description,
            'posted_data' => maybe_serialize( zibal_cf7_prepare_posted_data_for_storage( $posted_data ) ),
            'status' => 'pending',
        ),
        array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
    );
    
    if ( ! $insert_result ) {
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'database',
                __( 'ذخیره تراکنش در دیتابیس ناموفق بود.', 'zibal-cf7' ),
                array(
                    'form_id' => $post_id,
                    'amount' => $amount,
                    'email' => $email,
                    'db_error' => $wpdb->last_error,
                    'last_query' => $wpdb->last_query,
                ),
                'critical'
            );
        }

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
            'zibal_token' => $callback_token,
        ),
        home_url( '/' ) 
    );
    
    $api = new Zibal_CF7_API( $service->get_merchant_id() );
    
    $result = $api->request_payment( $amount, $callback_url, array(
        'mobile' => $mobile,
        'description' => $description,
        'orderId' => $transaction_id,
    ) );
    
    if ( ! $result || ! isset( $result->result ) || (int) $result->result !== 100 || empty( $result->trackId ) ) {
        $error_message = $result 
            ? Zibal_CF7_API::get_error_message( isset( $result->result ) ? (int) $result->result : 0 )
            : __( 'خطا در برقراری ارتباط با درگاه پرداخت.', 'zibal-cf7' );

        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'payment_request',
                __( 'درخواست ساخت پرداخت زیبال ناموفق بود.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'form_id' => $post_id,
                    'amount' => $amount,
                    'zibal_result' => is_object( $result ) && isset( $result->result ) ? (int) $result->result : '',
                    'zibal_message' => $error_message,
                    'has_track_id' => is_object( $result ) && ! empty( $result->trackId ),
                ),
                'error'
            );
        }
        
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
        array( 'track_id' => sanitize_text_field( $result->trackId ) ),
        array( 'id' => $transaction_id ),
        array( '%s' ),
        array( '%d' )
    );

    if ( $wpdb->last_error && function_exists( 'zibal_cf7_log_error' ) ) {
        zibal_cf7_log_error(
            'database',
            __( 'ذخیره trackId تراکنش ناموفق بود.', 'zibal-cf7' ),
            array(
                'transaction_id' => $transaction_id,
                'track_id' => sanitize_text_field( $result->trackId ),
                'db_error' => $wpdb->last_error,
            ),
            'error'
        );
    }
    
    $redirect_url = 'https://gateway.zibal.ir/start/' . rawurlencode( sanitize_text_field( $result->trackId ) );
    $form_id = $contact_form->id();
    $redirect_key = 'zibal_redirect_' . $form_id . '_' . wp_hash( $client_token );

    set_transient(
        $redirect_key,
        array(
            'transaction_id' => $transaction_id,
            'token' => $callback_token,
            'url' => $redirect_url,
        ),
        5 * MINUTE_IN_SECONDS
    );

    set_transient(
        'zibal_redirect_' . $form_id,
        array(
            'transaction_id' => $transaction_id,
            'token' => $callback_token,
            'url' => $redirect_url,
        ),
        90
    );
    
    $submission->set_response( 
        __( 'در حال انتقال به درگاه پرداخت...', 'zibal-cf7' ) 
    );

    if ( method_exists( $submission, 'add_result_props' ) ) {
        $submission->add_result_props(
            array(
                'zibal' => array(
                    'redirect_url' => esc_url_raw( $redirect_url ),
                    'transaction_id' => absint( $transaction_id ),
                    'track_id' => sanitize_text_field( $result->trackId ),
                ),
            )
        );
    } elseif ( function_exists( 'zibal_cf7_log_error' ) ) {
        zibal_cf7_log_error(
            'redirect',
            __( 'نسخه Contact Form 7 از add_result_props پشتیبانی نمی‌کند؛ redirect فقط از fallback انجام می‌شود.', 'zibal-cf7' ),
            array(
                'transaction_id' => $transaction_id,
                'form_id' => $form_id,
            ),
            'warning'
        );
    }
    
    $submission->set_status( 'mail_sent' );
    
    if ( ! session_id() ) {
        @session_start();
    }
    $_SESSION['zibal_redirect_' . $form_id] = array(
        'transaction_id' => $transaction_id,
        'token' => $callback_token,
        'url' => $redirect_url,
    );
    
    $abort = true;
}

add_action( 'template_redirect', 'zibal_cf7_verify_payment' );

function zibal_cf7_verify_payment() {
    if ( ! isset( $_GET['zibal_verify'] ) || ! isset( $_GET['transaction_id'] ) ) {
        return;
    }
    
    $transaction_id = absint( wp_unslash( $_GET['transaction_id'] ) );
    $track_id = isset( $_GET['trackId'] ) ? sanitize_text_field( wp_unslash( $_GET['trackId'] ) ) : '';
    $status = isset( $_GET['status'] ) ? absint( wp_unslash( $_GET['status'] ) ) : 0;
    $success = isset( $_GET['success'] ) ? absint( wp_unslash( $_GET['success'] ) ) : 0;
    $callback_token = isset( $_GET['zibal_token'] ) ? sanitize_text_field( wp_unslash( $_GET['zibal_token'] ) ) : '';
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    
    $transaction = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d",
        $transaction_id
    ) );
    
    if ( ! $transaction ) {
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'callback',
                __( 'callback زیبال برای تراکنش ناموجود دریافت شد.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'track_id' => $track_id,
                ),
                'warning'
            );
        }

        wp_die( 
            __( 'تراکنش یافت نشد.', 'zibal-cf7' ),
            __( 'خطا', 'zibal-cf7' ),
            array( 'response' => 404 )
        );
    }

    if (
        empty( $transaction->callback_token )
        || empty( $callback_token )
        || ! hash_equals( (string) $transaction->callback_token, (string) $callback_token )
    ) {
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'callback',
                __( 'توکن callback پرداخت معتبر نبود.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'track_id' => $track_id,
                    'has_stored_token' => ! empty( $transaction->callback_token ),
                    'has_callback_token' => ! empty( $callback_token ),
                ),
                'warning'
            );
        }

        wp_die(
            esc_html__( 'درخواست تایید پرداخت نامعتبر است.', 'zibal-cf7' ),
            esc_html__( 'خطا', 'zibal-cf7' ),
            array( 'response' => 403 )
        );
    }

    if (
        empty( $track_id )
        || empty( $transaction->track_id )
        || ! hash_equals( (string) $transaction->track_id, (string) $track_id )
    ) {
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'callback',
                __( 'trackId برگشتی زیبال با تراکنش محلی تطبیق نداشت.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'callback_track_id' => $track_id,
                    'stored_track_id' => $transaction->track_id,
                ),
                'warning'
            );
        }

        wp_die(
            esc_html__( 'شماره پیگیری پرداخت معتبر نیست.', 'zibal-cf7' ),
            esc_html__( 'خطا', 'zibal-cf7' ),
            array( 'response' => 400 )
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
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'callback',
                __( 'پرداخت توسط زیبال موفق اعلام نشد یا کاربر پرداخت را لغو کرد.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'track_id' => $track_id,
                    'status' => $status,
                    'success' => $success,
                ),
                'info'
            );
        }

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
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'verify',
                __( 'callback دریافت شد اما سرویس زیبال فعال نبود.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'track_id' => $track_id,
                ),
                'error'
            );
        }

        wp_die( __( 'سرویس زیبال فعال نیست.', 'zibal-cf7' ) );
    }
    
    $api = new Zibal_CF7_API( $service->get_merchant_id() );

    $lock_key = 'zibal_cf7_verify_lock_' . $transaction_id;
    if ( get_transient( $lock_key ) ) {
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'verify',
                __( 'درخواست verify تکراری در زمان قفل دریافت شد.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'track_id' => $track_id,
                ),
                'info'
            );
        }

        zibal_cf7_display_result(
            'failed',
            __( 'پرداخت در حال بررسی است. لطفا چند لحظه بعد دوباره صفحه را بررسی کنید.', 'zibal-cf7' ),
            $transaction
        );
        exit;
    }

    set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

    $result = $api->verify_payment( $track_id );
    
    if ( ! $result || ! isset( $result->result ) || (int) $result->result !== 100 ) {
        $error_message = $result 
            ? Zibal_CF7_API::get_error_message( isset( $result->result ) ? (int) $result->result : 0 )
            : __( 'خطا در تایید پرداخت.', 'zibal-cf7' );

        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'verify',
                __( 'تایید پرداخت زیبال ناموفق بود.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'track_id' => $track_id,
                    'zibal_result' => is_object( $result ) && isset( $result->result ) ? (int) $result->result : '',
                    'zibal_message' => $error_message,
                ),
                'error'
            );
        }
        
        $wpdb->update(
            $table_name,
            array( 'status' => 'failed' ),
            array( 'id' => $transaction_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        delete_transient( $lock_key );
        zibal_cf7_display_result( 'failed', $error_message, $transaction );
        exit;
    }

    if ( ! isset( $result->amount ) || absint( $result->amount ) !== absint( $transaction->amount ) ) {
        if ( function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'verify',
                __( 'مبلغ تایید شده با مبلغ تراکنش محلی مطابقت نداشت.', 'zibal-cf7' ),
                array(
                    'transaction_id' => $transaction_id,
                    'track_id' => $track_id,
                    'verified_amount' => isset( $result->amount ) ? absint( $result->amount ) : '',
                    'stored_amount' => absint( $transaction->amount ),
                ),
                'critical'
            );
        }

        $wpdb->update(
            $table_name,
            array( 'status' => 'failed' ),
            array( 'id' => $transaction_id ),
            array( '%s' ),
            array( '%d' )
        );

        delete_transient( $lock_key );
        zibal_cf7_display_result(
            'failed',
            __( 'مبلغ تایید شده با مبلغ تراکنش مطابقت ندارد.', 'zibal-cf7' ),
            $transaction
        );
        exit;
    }
    
    $wpdb->update(
        $table_name,
        array( 
            'status' => 'success',
            'ref_number' => isset( $result->refNumber ) ? sanitize_text_field( $result->refNumber ) : '',
            'payer_card' => zibal_cf7_extract_payer_card( $result ),
        ),
        array( 'id' => $transaction_id ),
        array( '%s', '%s', '%s' ),
        array( '%d' )
    );

    if ( $wpdb->last_error && function_exists( 'zibal_cf7_log_error' ) ) {
        zibal_cf7_log_error(
            'database',
            __( 'ثبت وضعیت موفق تراکنش ناموفق بود.', 'zibal-cf7' ),
            array(
                'transaction_id' => $transaction_id,
                'track_id' => $track_id,
                'db_error' => $wpdb->last_error,
            ),
            'error'
        );
    }
    
    zibal_cf7_send_mail_after_payment( $transaction );
    
    zibal_cf7_display_result(
        'success',
        sprintf(
            __( 'پرداخت با موفقیت انجام شد. شماره پیگیری: %s', 'zibal-cf7' ),
            isset( $result->refNumber ) ? sanitize_text_field( $result->refNumber ) : ''
        ),
        $transaction
    );
    delete_transient( $lock_key );
    exit;
}

function zibal_cf7_extract_payer_card( $result ) {
    if ( ! is_object( $result ) ) {
        return '';
    }

    $possible_fields = array( 'cardNumber', 'card_number', 'payerCard', 'payer_card', 'pan' );

    foreach ( $possible_fields as $field ) {
        if ( isset( $result->{$field} ) && '' !== (string) $result->{$field} ) {
            return zibal_cf7_sanitize_payer_card( $result->{$field} );
        }
    }

    return '';
}

function zibal_cf7_sanitize_payer_card( $card ) {
    $card = preg_replace( '/[^0-9*Xx-]/', '', (string) $card );
    return substr( sanitize_text_field( $card ), 0, 32 );
}

function zibal_cf7_prepare_posted_data_for_storage( $posted_data ) {
    if ( ! is_array( $posted_data ) ) {
        return array();
    }

    $allowed_keys = apply_filters(
        'zibal_cf7_stored_posted_data_keys',
        array( 'your-email', 'your-phone' )
    );

    $stored = array();

    foreach ( $allowed_keys as $key ) {
        if ( ! is_string( $key ) || ! array_key_exists( $key, $posted_data ) ) {
            continue;
        }

        $value = $posted_data[ $key ];

        if ( is_array( $value ) ) {
            $stored[ $key ] = array_map( 'sanitize_text_field', wp_unslash( $value ) );
            continue;
        }

        $stored[ $key ] = sanitize_text_field( wp_unslash( $value ) );
    }

    return $stored;
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
    $client_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
    
    if ( ! $form_id ) {
        return new WP_Error( 
            'invalid_form_id', 
            'Invalid form ID', 
            array( 'status' => 400 ) 
        );
    }

    if ( $client_token ) {
        $redirect_key = 'zibal_redirect_' . $form_id . '_' . wp_hash( $client_token );
        $redirect = get_transient( $redirect_key );

        if ( is_array( $redirect ) ) {
            delete_transient( $redirect_key );

            if ( empty( $redirect['url'] ) || empty( $redirect['token'] ) || empty( $redirect['transaction_id'] ) ) {
                return new WP_Error(
                    'invalid_redirect_url',
                    'Invalid redirect URL',
                    array( 'status' => 404 )
                );
            }

            return array(
                'success' => true,
                'redirect_url' => esc_url_raw( $redirect['url'] ),
                'source' => 'token'
            );
        }
    }

    $redirect = get_transient( 'zibal_redirect_' . $form_id );

    if ( is_array( $redirect ) ) {
        delete_transient( 'zibal_redirect_' . $form_id );

        if ( empty( $redirect['url'] ) || empty( $redirect['token'] ) || empty( $redirect['transaction_id'] ) ) {
            return new WP_Error(
                'invalid_redirect_url',
                'Invalid redirect URL',
                array( 'status' => 404 )
            );
        }

        return array(
            'success' => true,
            'redirect_url' => esc_url_raw( $redirect['url'] ),
            'source' => 'transient'
        );
    }
    
    if ( ! session_id() ) {
        @session_start();
    }
    
    if ( isset( $_SESSION['zibal_redirect_' . $form_id] ) && is_array( $_SESSION['zibal_redirect_' . $form_id] ) ) {
        $redirect = $_SESSION['zibal_redirect_' . $form_id];
        unset( $_SESSION['zibal_redirect_' . $form_id] );

        if ( empty( $redirect['url'] ) || empty( $redirect['token'] ) || empty( $redirect['transaction_id'] ) ) {
            return new WP_Error(
                'invalid_redirect_url',
                'Invalid redirect URL',
                array( 'status' => 404 )
            );
        }

        return array(
            'success' => true,
            'redirect_url' => esc_url_raw( $redirect['url'] ),
            'source' => 'session'
        );
    }

    if ( function_exists( 'zibal_cf7_log_error' ) ) {
        zibal_cf7_log_error(
            'redirect',
            __( 'REST redirect URL برای فرم پیدا نشد.', 'zibal-cf7' ),
            array(
                'form_id' => $form_id,
                'has_client_token' => ! empty( $client_token ),
            ),
            'warning'
        );
    }
    
    return new WP_Error( 
        'no_redirect_url', 
        'Redirect URL not found', 
        array( 'status' => 404 ) 
    );
}
