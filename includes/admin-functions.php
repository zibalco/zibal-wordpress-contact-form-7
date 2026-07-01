<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'zibal_cf7_admin_menu', 20 );

function zibal_cf7_admin_menu() {
    add_submenu_page(
        'wpcf7',
        __( 'تراکنش‌های زیبال', 'zibal-cf7' ),
        __( 'تراکنش‌های زیبال', 'zibal-cf7' ),
        'wpcf7_read_contact_forms',
        'zibal-cf7-transactions',
        'zibal_cf7_transactions_page'
    );

    add_submenu_page(
        'wpcf7',
        __( 'خطاهای زیبال', 'zibal-cf7' ),
        __( 'خطاهای زیبال', 'zibal-cf7' ),
        'wpcf7_read_contact_forms',
        'zibal-cf7-errors',
        'zibal_cf7_errors_page'
    );
}

function zibal_cf7_log_error( $source, $message, $context = array(), $level = 'error' ) {
    $allowed_levels = array( 'debug', 'info', 'warning', 'error', 'critical' );
    $level = in_array( $level, $allowed_levels, true ) ? $level : 'error';

    $logs = get_option( 'zibal_cf7_error_logs', array() );
    if ( ! is_array( $logs ) ) {
        $logs = array();
    }

    array_unshift(
        $logs,
        array(
            'time' => current_time( 'mysql' ),
            'level' => $level,
            'source' => sanitize_key( $source ),
            'message' => sanitize_text_field( $message ),
            'context' => zibal_cf7_sanitize_log_context( $context ),
        )
    );

    $logs = array_slice( $logs, 0, 100 );
    update_option( 'zibal_cf7_error_logs', $logs, false );
}

function zibal_cf7_sanitize_log_context( $context ) {
    if ( is_object( $context ) ) {
        $context = get_object_vars( $context );
    }

    if ( ! is_array( $context ) ) {
        return sanitize_text_field( (string) $context );
    }

    $sanitized = array();

    foreach ( $context as $key => $value ) {
        $safe_key = sanitize_key( (string) $key );

        if ( '' === $safe_key ) {
            continue;
        }

        if ( is_array( $value ) || is_object( $value ) ) {
            $sanitized[ $safe_key ] = zibal_cf7_sanitize_log_context( $value );
            continue;
        }

        if ( is_bool( $value ) ) {
            $sanitized[ $safe_key ] = $value ? 'true' : 'false';
            continue;
        }

        if ( is_scalar( $value ) || null === $value ) {
            $string_value = (string) $value;
            $string_value = function_exists( 'mb_substr' )
                ? mb_substr( $string_value, 0, 500 )
                : substr( $string_value, 0, 500 );

            $sanitized[ $safe_key ] = sanitize_text_field( $string_value );
        }
    }

    return $sanitized;
}

function zibal_cf7_errors_page() {
    if ( ! current_user_can( 'wpcf7_read_contact_forms' ) && ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'شما اجازه مشاهده این صفحه را ندارید.', 'zibal-cf7' ) );
    }

    $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

    if ( 'clear' === $action ) {
        check_admin_referer( 'zibal_clear_error_logs' );
        delete_option( 'zibal_cf7_error_logs' );

        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__( 'خطاهای زیبال پاک شدند.', 'zibal-cf7' ) .
            '</p></div>';
    }

    $logs = get_option( 'zibal_cf7_error_logs', array() );
    if ( ! is_array( $logs ) ) {
        $logs = array();
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html__( 'خطاهای زیبال', 'zibal-cf7' ); ?></h1>
        <a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=zibal-cf7-errors&action=clear' ), 'zibal_clear_error_logs' ) ); ?>">
            <?php echo esc_html__( 'پاک کردن خطاها', 'zibal-cf7' ); ?>
        </a>
        <hr class="wp-header-end">

        <p><?php echo esc_html__( 'این بخش آخرین خطاهای پرداخت، اتصال به زیبال، ذخیره تراکنش، REST redirect و تایید پرداخت را نشان می‌دهد.', 'zibal-cf7' ); ?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;"><?php echo esc_html__( 'زمان', 'zibal-cf7' ); ?></th>
                    <th style="width: 90px;"><?php echo esc_html__( 'سطح', 'zibal-cf7' ); ?></th>
                    <th style="width: 150px;"><?php echo esc_html__( 'بخش', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'پیام', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'جزئیات', 'zibal-cf7' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr>
                        <td colspan="5" style="text-align: center;">
                            <?php echo esc_html__( 'فعلا خطایی ثبت نشده است.', 'zibal-cf7' ); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $logs as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( isset( $log['time'] ) ? $log['time'] : '' ); ?></td>
                            <td><?php echo esc_html( isset( $log['level'] ) ? $log['level'] : '' ); ?></td>
                            <td><code><?php echo esc_html( isset( $log['source'] ) ? $log['source'] : '' ); ?></code></td>
                            <td><?php echo esc_html( isset( $log['message'] ) ? $log['message'] : '' ); ?></td>
                            <td>
                                <?php if ( ! empty( $log['context'] ) ) : ?>
                                    <pre style="white-space: pre-wrap; max-height: 220px; overflow: auto; direction: ltr; text-align: left;"><?php echo esc_html( wp_json_encode( $log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function zibal_cf7_transactions_page() {
    global $wpdb;
    
    $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

    if ( 'delete' === $action && isset( $_GET['id'] ) ) {
        if ( ! current_user_can( 'wpcf7_read_contact_forms' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'شما اجازه انجام این عملیات را ندارید.', 'zibal-cf7' ) );
        }

        $delete_id = absint( wp_unslash( $_GET['id'] ) );

        check_admin_referer( 'zibal_delete_transaction_' . $delete_id );
        
        $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
        $wpdb->delete( $table_name, array( 'id' => $delete_id ), array( '%d' ) );
        
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             esc_html__( 'تراکنش با موفقیت حذف شد.', 'zibal-cf7' ) . 
             '</p></div>';
    }
    
    $paged = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
    $per_page = 20;
    $offset = ( $paged - 1 ) * $per_page;
    
    $status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
    $allowed_statuses = array( 'pending', 'success', 'failed', 'cancelled' );
    if ( $status_filter && ! in_array( $status_filter, $allowed_statuses, true ) ) {
        $status_filter = '';
    }

    $form_filter = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
    
    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    $where = array( '1=1' );
    
    if ( $status_filter ) {
        $where[] = $wpdb->prepare( 'status = %s', $status_filter );
    }
    
    if ( $form_filter ) {
        $where[] = $wpdb->prepare( 'form_id = %d', $form_filter );
    }
    
    $where_clause = implode( ' AND ', $where );
    
    $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE $where_clause" );
    
    $transactions = $wpdb->get_results( 
        "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT $offset, $per_page" 
    );
    
    $forms = get_posts( array(
        'post_type' => 'wpcf7_contact_form',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ) );
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php echo esc_html__( 'تراکنش‌های زیبال', 'zibal-cf7' ); ?></h1>
        
        <hr class="wp-header-end">
        
        <div class="tablenav top">
            <form method="get" action="">
                <input type="hidden" name="page" value="zibal-cf7-transactions" />
                
                <select name="status" id="status-filter">
                    <option value=""><?php echo esc_html__( 'همه وضعیت‌ها', 'zibal-cf7' ); ?></option>
                    <option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php echo esc_html__( 'در انتظار', 'zibal-cf7' ); ?></option>
                    <option value="success" <?php selected( $status_filter, 'success' ); ?>><?php echo esc_html__( 'موفق', 'zibal-cf7' ); ?></option>
                    <option value="failed" <?php selected( $status_filter, 'failed' ); ?>><?php echo esc_html__( 'ناموفق', 'zibal-cf7' ); ?></option>
                    <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>><?php echo esc_html__( 'لغو شده', 'zibal-cf7' ); ?></option>
                </select>
                
                <select name="form_id" id="form-filter">
                    <option value=""><?php echo esc_html__( 'همه فرم‌ها', 'zibal-cf7' ); ?></option>
                    <?php foreach ( $forms as $form ) : ?>
                        <option value="<?php echo esc_attr( $form->ID ); ?>" <?php selected( $form_filter, $form->ID ); ?>>
                            <?php echo esc_html( $form->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <input type="submit" class="button" value="<?php echo esc_attr__( 'فیلتر', 'zibal-cf7' ); ?>" />
                
                <?php if ( $status_filter || $form_filter ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=zibal-cf7-transactions' ) ); ?>" class="button">
                        <?php echo esc_html__( 'پاک کردن فیلتر', 'zibal-cf7' ); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'شناسه', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'فرم', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'مبلغ', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'ایمیل', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'موبایل', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'شماره پیگیری', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'شماره کارت', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'وضعیت', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'تاریخ', 'zibal-cf7' ); ?></th>
                    <th><?php echo esc_html__( 'عملیات', 'zibal-cf7' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $transactions ) ) : ?>
                    <tr>
                            <td colspan="10" style="text-align: center;">
                            <?php echo esc_html__( 'هیچ تراکنشی یافت نشد.', 'zibal-cf7' ); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $transactions as $transaction ) : ?>
                        <tr>
                            <td><?php echo esc_html( $transaction->id ); ?></td>
                            <td><?php echo esc_html( get_the_title( $transaction->form_id ) ); ?></td>
                            <td><?php echo number_format( $transaction->amount ); ?> ریال</td>
                            <td><?php echo esc_html( $transaction->email ); ?></td>
                            <td><?php echo esc_html( $transaction->mobile ); ?></td>
                            <td>
                                <?php if ( $transaction->track_id ) : ?>
                                    <code><?php echo esc_html( $transaction->track_id ); ?></code>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $transaction->payer_card ) ) : ?>
                                    <code><?php echo esc_html( $transaction->payer_card ); ?></code>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo zibal_cf7_get_status_badge( $transaction->status ); ?></td>
                            <td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $transaction->created_at ) ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=zibal-cf7-transactions&action=delete&id=' . $transaction->id ), 'zibal_delete_transaction_' . $transaction->id ) ); ?>" 
                                   onclick="return confirm('<?php echo esc_js( __( 'آیا مطمئن هستید؟', 'zibal-cf7' ) ); ?>');"
                                   style="color: #a00;">
                                    <?php echo esc_html__( 'حذف', 'zibal-cf7' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php
        $total_pages = ceil( $total / $per_page );
        
        if ( $total_pages > 1 ) {
            $page_links = paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%' ),
                'format' => '',
                'prev_text' => __( '&laquo;' ),
                'next_text' => __( '&raquo;' ),
                'total' => $total_pages,
                'current' => $paged,
            ) );
            
            if ( $page_links ) {
                echo '<div class="tablenav bottom"><div class="tablenav-pages">' . $page_links . '</div></div>';
            }
        }
        ?>
        
        <div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h3><?php echo esc_html__( 'آمار کلی', 'zibal-cf7' ); ?></h3>
            <?php
            $stats = $wpdb->get_results( "
                SELECT 
                    status,
                    COUNT(*) as count,
                    SUM(amount) as total_amount
                FROM $table_name
                GROUP BY status
            " );
            ?>
            <table class="widefat" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th><?php echo esc_html__( 'وضعیت', 'zibal-cf7' ); ?></th>
                        <th><?php echo esc_html__( 'تعداد', 'zibal-cf7' ); ?></th>
                        <th><?php echo esc_html__( 'مجموع مبلغ', 'zibal-cf7' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $stats as $stat ) : ?>
                        <tr>
                            <td><?php echo zibal_cf7_get_status_badge( $stat->status ); ?></td>
                            <td><?php echo number_format( $stat->count ); ?></td>
                            <td><?php echo number_format( $stat->total_amount ); ?> ریال</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function zibal_cf7_get_status_badge( $status ) {
    $statuses = array(
        'pending' => array(
            'label' => __( 'در انتظار', 'zibal-cf7' ),
            'color' => '#f0ad4e',
        ),
        'success' => array(
            'label' => __( 'موفق', 'zibal-cf7' ),
            'color' => '#5cb85c',
        ),
        'failed' => array(
            'label' => __( 'ناموفق', 'zibal-cf7' ),
            'color' => '#d9534f',
        ),
        'cancelled' => array(
            'label' => __( 'لغو شده', 'zibal-cf7' ),
            'color' => '#999',
        ),
    );
    
    $status_info = isset( $statuses[ $status ] ) ? $statuses[ $status ] : array(
        'label' => $status,
        'color' => '#999',
    );
    
    return sprintf(
        '<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; background: %s; color: white; font-size: 11px;">%s</span>',
        esc_attr( $status_info['color'] ),
        esc_html( $status_info['label'] )
    );
}

add_filter( 'plugin_action_links_' . ZIBAL_CF7_PLUGIN_BASENAME, 'zibal_cf7_plugin_action_links' );

function zibal_cf7_plugin_action_links( $links ) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url( 'admin.php?page=wpcf7-integration&service=zibal' ),
        __( 'تنظیمات', 'zibal-cf7' )
    );
    
    $transactions_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url( 'admin.php?page=zibal-cf7-transactions' ),
        __( 'تراکنش‌ها', 'zibal-cf7' )
    );

    $errors_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url( 'admin.php?page=zibal-cf7-errors' ),
        __( 'خطاها', 'zibal-cf7' )
    );
    
    array_unshift( $links, $settings_link, $transactions_link, $errors_link );
    
    return $links;
}
