<?php

  global $wpdb;
  global $postid;
        
    $wpcf7 = WPCF7_ContactForm::get_current();
        $submission = WPCF7_Submission::get_instance();
        $user_email = '';
        $user_mobile = '';
        $description = '';
        $user_price = '';

        if ($submission) {
            $data = $submission->get_posted_data();
            $user_email = isset($data['user_email']) ? $data['user_email'] : "";
            $user_mobile = isset($data['user_mobile']) ? $data['user_mobile'] : "";
            $description = isset($data['description']) ? $data['description'] : "";
            $user_price = isset($data['user_price']) ? $data['user_price'] : "";
        }
        
        $price = get_post_meta($postid, "_cf7pp_price", true);
                if ($price == "") {
                    $price = $user_price;
                }
                $options = get_option('cf7pp_options');
                foreach ($options as $k => $v) {
                    $value[$k] = $v;
                }
                $active_gateway = 'Zibal';
                if($value['gateway_merchantid']) {
                    $merchantId = $value['gateway_merchantid'];
                } else {
                    echo 'لطفا کد درگاه (مرچنت) زیبال را در تنظیمات وارد نمایید.';
                    die();
                }
                $url_return = $value['return'];


//$user_email;
// Set Data -> Table Trans_ContantForm7
                $table_name = $wpdb->prefix . "zibal_contact_form_7";
                $table = array();
                $table['idform'] = $postid;
                $table['transid'] = ''; // create dynamic or id_get
                $table['gateway'] = $active_gateway; // name gateway
                $table['cost'] = $price;
                $table['created_at'] = time();
                $table['email'] = $user_email;
                $table['user_mobile'] = $user_mobile;
                $table['description'] = $description;
                $table['status'] = 'none';
                $table_fill = array('%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s');

                if ($active_gateway == 'Zibal') {
                            $callbackUrl = get_site_url().'/'.$url_return; // Required
                        
                            $data = [
                                'merchant' => $merchantId,
                                'amount' => $price,
                                'mobile' => $user_mobile,
                                'description' => $description,
                                'callbackUrl' => $callbackUrl
                            ];

                            $result = postToZibal('/v1/request', $data);

                    if ($result->result == 100 && $Amount == $result->amount) {

                        $table['transid'] = $result->trackId;
                        
                        $sql = $wpdb->insert($table_name, $table, $table_fill);

                        Header('Location: https://gateway.zibal.ir/start/' . $result->trackId);
						
                    } else {
                        $tmp = 'خطایی رخ داده در اطلاعات پرداختی درگاه' . '<br>Error:' . $result->status . '<br> لطفا به مدیر اطلاع دهید <br><br>';
                        $tmp .= '<a href="' . get_option('siteurl') . '" class="mrbtn_red" > بازگشت به سایت </a>';
                        echo CreatePage_cf7('خطا در عملیات پرداخت', $tmp);
                    }
                }

?>