<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Zibal_CF7_API {
    
    private $merchant_id;
    private $base_url = 'https://gateway.zibal.ir';
    
    public function __construct( $merchant_id ) {
        $this->merchant_id = $merchant_id;
    }
    
    public function request_payment( $amount, $callback_url, $args = array() ) {
        $data = array(
            'merchant' => $this->merchant_id,
            'amount' => absint( $amount ),
            'callbackUrl' => $callback_url,
        );
        
        if ( ! empty( $args['mobile'] ) ) {
            $mobile = $this->sanitize_mobile( $args['mobile'] );
            if ( $mobile ) {
                $data['mobile'] = $mobile;
            }
        }
        
        if ( ! empty( $args['description'] ) ) {
            $data['description'] = sanitize_text_field( $args['description'] );
        }
        
        if ( ! empty( $args['orderId'] ) ) {
            $data['orderId'] = sanitize_text_field( $args['orderId'] );
        }
        
        $response = $this->post( '/v1/request', $data );
        
        return $response;
    }
    
    public function verify_payment( $track_id ) {
        $data = array(
            'merchant' => $this->merchant_id,
            'trackId' => absint( $track_id ),
        );
        
        $response = $this->post( '/v1/verify', $data );
        
        return $response;
    }
    
    public function inquiry( $track_id ) {
        $data = array(
            'merchant' => $this->merchant_id,
            'trackId' => absint( $track_id ),
        );
        
        return $this->post( '/v1/inquiry', $data );
    }
    
    private function post( $endpoint, $data ) {
        $url = $this->base_url . $endpoint;
        
        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $data ),
            'timeout' => 30,
            'sslverify' => true,
        ) );
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $result = json_decode( $body );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return false;
        }
        
        return $result;
    }
    
    private function sanitize_mobile( $mobile ) {
        $mobile = preg_replace( '/[^0-9]/', '', $mobile );
        
        if ( strlen( $mobile ) === 11 && substr( $mobile, 0, 2 ) === '09' ) {
            return $mobile;
        }
        
        if ( strlen( $mobile ) === 10 && substr( $mobile, 0, 1 ) === '9' ) {
            return '0' . $mobile;
        }
        
        return false;
    }
    
    public static function get_error_message( $result_code ) {
        $messages = array(
            100 => __( 'عملیات موفق', 'zibal-cf7' ),
            102 => __( 'merchant یافت نشد', 'zibal-cf7' ),
            103 => __( 'merchant غیرفعال است', 'zibal-cf7' ),
            104 => __( 'merchant نامعتبر است', 'zibal-cf7' ),
            105 => __( 'amount بایستی بزرگتر از 1,000 ریال باشد', 'zibal-cf7' ),
            106 => __( 'callbackUrl نامعتبر است', 'zibal-cf7' ),
            113 => __( 'amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است', 'zibal-cf7' ),
            201 => __( 'قبلا تایید شده است', 'zibal-cf7' ),
            202 => __( 'سفارش پرداخت نشده یا ناموفق بوده است', 'zibal-cf7' ),
            203 => __( 'trackId نامعتبر است', 'zibal-cf7' ),
        );
        
        return isset( $messages[ $result_code ] ) 
            ? $messages[ $result_code ] 
            : sprintf( __( 'خطای ناشناخته (کد: %d)', 'zibal-cf7' ), $result_code );
    }
}
