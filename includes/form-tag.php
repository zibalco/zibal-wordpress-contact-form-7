<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wpcf7_init', 'zibal_cf7_add_form_tag', 10, 0 );

function zibal_cf7_add_form_tag() {
    wpcf7_add_form_tag(
        'zibal',
        'zibal_cf7_form_tag_handler',
        array(
            'name-attr' => true,
        )
    );
}

function zibal_cf7_form_tag_handler( $tag ) {
    $amount = $tag->get_option( 'amount', 'int', true );
    $button_text = ! empty( $tag->values ) ? $tag->values[0] : __( 'پرداخت', 'zibal-cf7' );
    $client_token = wp_generate_password( 32, false, false );
    
    $html = sprintf(
        '<div class="zibal-cf7-payment-wrapper">
            <input type="hidden" name="_zibal_amount" value="%d" />
            <input type="hidden" name="_zibal_client_token" value="%s" />
            <button type="submit" class="wpcf7-form-control wpcf7-submit zibal-payment-button">
                <span class="zibal-button-text">%s</span>
                <span class="zibal-button-spinner" style="display:none;">%s</span>
            </button>
        </div>',
        esc_attr( $amount ),
        esc_attr( $client_token ),
        esc_html( $button_text ),
        esc_html__( 'در حال پردازش...', 'zibal-cf7' )
    );
    
    return $html;
}

add_filter( 'wpcf7_editor_panels', 'zibal_cf7_editor_panels' );

function zibal_cf7_editor_panels( $panels ) {
    $panels['zibal-panel'] = array(
        'title' => __( 'تنظیمات پرداخت زیبال', 'zibal-cf7' ),
        'callback' => 'zibal_cf7_editor_panel_content',
    );
    
    return $panels;
}

function zibal_cf7_editor_panel_content( $contact_form ) {
    $post_id = $contact_form->id();
    
    $enabled = get_post_meta( $post_id, '_zibal_enabled', true );
    $amount = get_post_meta( $post_id, '_zibal_amount', true );
    $amount_field = get_post_meta( $post_id, '_zibal_amount_field', true );
    $description = get_post_meta( $post_id, '_zibal_description', true );
    
    ?>
    <h2><?php echo esc_html__( 'تنظیمات پرداخت زیبال', 'zibal-cf7' ); ?></h2>
    
    <fieldset>
        <legend>
            <label for="zibal-enabled">
                <input type="checkbox" 
                       id="zibal-enabled" 
                       name="zibal_enabled" 
                       value="1" 
                       <?php checked( $enabled, '1' ); ?> />
                <?php echo esc_html__( 'فعال‌سازی پرداخت برای این فرم', 'zibal-cf7' ); ?>
            </label>
        </legend>
    </fieldset>
    
    <fieldset>
        <legend><?php echo esc_html__( 'مبلغ پرداخت (ریال)', 'zibal-cf7' ); ?></legend>
        <input type="number" 
               id="zibal-amount" 
               name="zibal_amount" 
               class="large-text" 
               value="<?php echo esc_attr( $amount ); ?>" 
               min="1000"
               step="1000"
               placeholder="10000" />
        <p class="description">
            <?php echo esc_html__( 'مبلغ را به ریال وارد کنید. حداقل مبلغ 1,000 ریال است.', 'zibal-cf7' ); ?>
        </p>
    </fieldset>
    
    <fieldset>
        <legend><?php echo esc_html__( 'یا نام فیلد مبلغ', 'zibal-cf7' ); ?></legend>
        <input type="text" 
               id="zibal-amount-field" 
               name="zibal_amount_field" 
               class="large-text code" 
               value="<?php echo esc_attr( $amount_field ); ?>" 
               placeholder="payment-amount"
               dir="ltr"
               style="text-align: left;" />
        <p class="description">
            <?php echo esc_html__( 'اگر می‌خواهید کاربر مبلغ را وارد کند، نام فیلد را اینجا بنویسید.', 'zibal-cf7' ); ?>
            <br />
            <?php echo esc_html__( 'مثال:', 'zibal-cf7' ); ?> 
            <code>[number payment-amount min:1000 step:1000]</code>
        </p>
    </fieldset>
    
    <fieldset>
        <legend><?php echo esc_html__( 'توضیحات پرداخت', 'zibal-cf7' ); ?></legend>
        <input type="text" 
               id="zibal-description" 
               name="zibal_description" 
               class="large-text" 
               value="<?php echo esc_attr( $description ); ?>" 
               placeholder="<?php echo esc_attr__( 'پرداخت از طریق فرم تماس', 'zibal-cf7' ); ?>" />
        <p class="description">
            <?php echo esc_html__( 'این توضیحات در پنل زیبال نمایش داده می‌شود.', 'zibal-cf7' ); ?>
        </p>
    </fieldset>
    
    <fieldset>
        <legend><?php echo esc_html__( 'نام فیلدهای پیشنهادی', 'zibal-cf7' ); ?></legend>
        <ul style="list-style: disc; margin-right: 20px;">
            <li><code>your-email</code> - <?php echo esc_html__( 'ایمیل کاربر', 'zibal-cf7' ); ?></li>
            <li><code>your-phone</code> - <?php echo esc_html__( 'شماره موبایل', 'zibal-cf7' ); ?></li>
            <li><code>your-message</code> - <?php echo esc_html__( 'پیام/توضیحات', 'zibal-cf7' ); ?></li>
            <li><code>payment-amount</code> - <?php echo esc_html__( 'مبلغ پرداخت (اختیاری)', 'zibal-cf7' ); ?></li>
        </ul>
    </fieldset>
    
    <p class="description">
        <strong><?php echo esc_html__( 'نکته:', 'zibal-cf7' ); ?></strong>
        <?php echo esc_html__( 'برای استفاده از پرداخت، باید تگ [zibal] را در فرم خود قرار دهید.', 'zibal-cf7' ); ?>
        <br />
        <?php echo esc_html__( 'مثال:', 'zibal-cf7' ); ?> 
        <code>[zibal "پرداخت آنلاین"]</code>
    </p>
    <?php
}

add_action( 'wpcf7_after_save', 'zibal_cf7_save_contact_form', 10, 1 );

function zibal_cf7_save_contact_form( $contact_form ) {
    $post_id = $contact_form->id();

    if ( ! current_user_can( 'wpcf7_edit_contact_form', $post_id ) && ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wpcf7-save-contact-form_' . $post_id ) ) {
        return;
    }
    
    $enabled = isset( $_POST['zibal_enabled'] ) ? '1' : '0';
    update_post_meta( $post_id, '_zibal_enabled', $enabled );
    
    if ( isset( $_POST['zibal_amount'] ) ) {
        $amount = absint( wp_unslash( $_POST['zibal_amount'] ) );
        update_post_meta( $post_id, '_zibal_amount', $amount );
    } else {
        update_post_meta( $post_id, '_zibal_amount', '' );
    }
    
    if ( isset( $_POST['zibal_amount_field'] ) ) {
        $amount_field = sanitize_key( wp_unslash( $_POST['zibal_amount_field'] ) );
        update_post_meta( $post_id, '_zibal_amount_field', $amount_field );
    } else {
        update_post_meta( $post_id, '_zibal_amount_field', '' );
    }
    
    if ( isset( $_POST['zibal_description'] ) ) {
        $description = sanitize_text_field( wp_unslash( $_POST['zibal_description'] ) );
        update_post_meta( $post_id, '_zibal_description', $description );
    } else {
        update_post_meta( $post_id, '_zibal_description', '' );
    }
}
