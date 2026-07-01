<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WPCF7_Service' ) ) {
    return;
}

class Zibal_CF7_Service extends WPCF7_Service {
    
    private static $instance;
    private $merchant_id;
    
    public static function get_instance() {
        if ( empty( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $option = WPCF7::get_option( 'zibal' );
        
        if ( isset( $option['merchant_id'] ) ) {
            $this->merchant_id = $option['merchant_id'];
        }
    }
    
    public function get_title() {
        return __( 'زیبال', 'zibal-cf7' );
    }
    
    public function is_active() {
        return ! empty( $this->merchant_id );
    }
    
    public function get_merchant_id() {
        return $this->merchant_id;
    }
    
    public function get_categories() {
        return array( 'payments' );
    }
    
    public function icon() {
        echo '<img src="' . esc_url( ZIBAL_CF7_PLUGIN_URL . 'assets/zibal-icon.png' ) . '" 
                   alt="Zibal" 
                   style="max-width: 200px; height: auto;" 
                   onerror="this.style.display=\'none\'" />';
    }
    
    public function link() {
        echo '<a href="https://zibal.ir" target="_blank" rel="noopener noreferrer">zibal.ir</a>';
    }
    
    protected function menu_page_url( $args = '' ) {
        $args = wp_parse_args( $args, array() );
        $url = menu_page_url( 'wpcf7-integration', false );
        $url = add_query_arg( array( 'service' => 'zibal' ), $url );
        
        if ( ! empty( $args ) ) {
            $url = add_query_arg( $args, $url );
        }
        
        return $url;
    }
    
    protected function save_data() {
        WPCF7::update_option( 'zibal', array(
            'merchant_id' => $this->merchant_id,
        ) );
    }
    
    protected function reset_data() {
        $this->merchant_id = null;
        $this->save_data();
    }
    
    public function load( $action = '' ) {
        if ( 'setup' === $action && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            if ( ! current_user_can( 'wpcf7_manage_integration' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'شما اجازه انجام این عملیات را ندارید.', 'zibal-cf7' ) );
            }

            check_admin_referer( 'wpcf7-zibal-setup' );
            
            if ( ! empty( $_POST['reset'] ) ) {
                $this->reset_data();
                $redirect_to = $this->menu_page_url( 'action=setup' );
            } else {
                $merchant_id = isset( $_POST['merchant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_id'] ) ) : '';
                
                if ( $merchant_id ) {
                    $this->merchant_id = $merchant_id;
                    $this->save_data();
                    
                    $redirect_to = $this->menu_page_url( array(
                        'message' => 'success',
                    ) );
                } else {
                    $redirect_to = $this->menu_page_url( array(
                        'action' => 'setup',
                        'message' => 'invalid',
                    ) );
                }
            }
            
            wp_safe_redirect( $redirect_to );
            exit();
        }
    }
    
    public function admin_notice( $message = '' ) {
        if ( 'invalid' === $message ) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html__( 'خطا: کد مرچنت نامعتبر است.', 'zibal-cf7' );
            echo '</p></div>';
        }
        
        if ( 'success' === $message ) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__( 'تنظیمات با موفقیت ذخیره شد.', 'zibal-cf7' );
            echo '</p></div>';
        }
    }
    
    public function display( $action = '' ) {
        ?>
        <h3><?php echo esc_html__( 'درباره زیبال', 'zibal-cf7' ); ?></h3>
        <p>
            <?php echo esc_html__( 'زیبال یک درگاه پرداخت اینترنتی ایرانی است که امکان پرداخت آنلاین و دریافت وجه از کاربران را فراهم می‌کند.', 'zibal-cf7' ); ?>
        </p>
        <p>
            <?php echo esc_html__( 'با استفاده از این افزونه می‌توانید فرم‌های Contact Form 7 خود را به درگاه پرداخت زیبال متصل کنید.', 'zibal-cf7' ); ?>
        </p>
        
        <?php if ( $this->is_active() ) : ?>
            <p class="dashicons-before dashicons-yes" style="color: #46b450;">
                <?php echo esc_html__( 'زیبال در این سایت فعال است.', 'zibal-cf7' ); ?>
            </p>
        <?php endif; ?>
        
        <?php if ( 'setup' === $action ) : ?>
            <?php $this->display_setup(); ?>
        <?php else : ?>
            <p>
                <a href="<?php echo esc_url( $this->menu_page_url( 'action=setup' ) ); ?>" class="button">
                    <?php echo esc_html__( 'تنظیم اتصال', 'zibal-cf7' ); ?>
                </a>
            </p>
        <?php endif; ?>
        
        <hr />
        
        <h3><?php echo esc_html__( 'راهنمای استفاده', 'zibal-cf7' ); ?></h3>
        <ol>
            <li><?php echo esc_html__( 'کد مرچنت خود را از پنل زیبال دریافت کنید.', 'zibal-cf7' ); ?></li>
            <li><?php echo esc_html__( 'در فرم Contact Form 7 خود، تگ [zibal] را اضافه کنید.', 'zibal-cf7' ); ?></li>
            <li><?php echo esc_html__( 'مبلغ پرداخت را در تنظیمات فرم مشخص کنید.', 'zibal-cf7' ); ?></li>
        </ol>
        
        <h4><?php echo esc_html__( 'نام فیلدهای پیشنهادی:', 'zibal-cf7' ); ?></h4>
        <ul style="list-style: disc; margin-right: 20px;">
            <li><code>your-email</code> - <?php echo esc_html__( 'برای دریافت ایمیل کاربر', 'zibal-cf7' ); ?></li>
            <li><code>your-phone</code> - <?php echo esc_html__( 'برای دریافت شماره موبایل', 'zibal-cf7' ); ?></li>
            <li><code>your-message</code> - <?php echo esc_html__( 'برای توضیحات پرداخت', 'zibal-cf7' ); ?></li>
            <li><code>payment-amount</code> - <?php echo esc_html__( 'برای دریافت مبلغ از کاربر (اختیاری)', 'zibal-cf7' ); ?></li>
        </ul>
        <?php
    }
    
    private function display_setup() {
        $merchant_id = $this->get_merchant_id();
        ?>
        <form method="post" action="<?php echo esc_url( $this->menu_page_url( 'action=setup' ) ); ?>">
            <?php wp_nonce_field( 'wpcf7-zibal-setup' ); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="merchant_id"><?php echo esc_html__( 'کد مرچنت (Merchant ID)', 'zibal-cf7' ); ?></label>
                        </th>
                        <td>
                            <?php if ( $this->is_active() ) : ?>
                                <code><?php echo esc_html( $merchant_id ); ?></code>
                                <input type="hidden" name="merchant_id" value="<?php echo esc_attr( $merchant_id ); ?>" />
                            <?php else : ?>
                                <input type="text" 
                                       id="merchant_id" 
                                       name="merchant_id" 
                                       class="regular-text code" 
                                       value="<?php echo esc_attr( $merchant_id ); ?>" 
                                       placeholder="zibal" 
                                       dir="ltr" 
                                       style="text-align: left;" />
                                <p class="description">
                                    <?php echo esc_html__( 'کد مرچنت خود را از پنل زیبال دریافت کنید.', 'zibal-cf7' ); ?>
                                    <br />
                                    <?php echo esc_html__( 'برای تست می‌توانید از کد', 'zibal-cf7' ); ?> 
                                    <code>zibal</code> 
                                    <?php echo esc_html__( 'استفاده کنید.', 'zibal-cf7' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php
            if ( $this->is_active() ) {
                submit_button( 
                    __( 'حذف تنظیمات', 'zibal-cf7' ), 
                    'small delete', 
                    'reset' 
                );
            } else {
                submit_button( __( 'ذخیره تغییرات', 'zibal-cf7' ) );
            }
            ?>
        </form>
        <?php
    }
}
