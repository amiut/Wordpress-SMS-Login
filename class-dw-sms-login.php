<?php
/**
 * Dornaweb like system for posts and comments
 *
 * @author Dornaweb
 * @contribute Am!n <dornaweb.com>
 */

class dw_sms_login{
	public function __construct(){
        $this->phone_fields();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(){
        register_rest_route('otp-login/v1', 'login', [
            'methods'               => 'POST',
            'callback'              => [$this, 'rest_cb'],
            'permission_callback'   => [$this, "permission_cb"]
        ]);

        register_rest_route('otp-login/v1', 'verify', [
            'methods'               => 'POST',
            'callback'              => [$this, 'verify_cb'],
            'permission_callback'   => [$this, "permission_cb"]
        ]);

        register_rest_route('otp-login/v1', 'change-phone', [
            'methods'               => 'POST',
            'callback'              => [$this, 'change_phone'],
            'permission_callback'   => [$this, "change_phone_permission_cb"]
        ]);
    }

    public function email_allowed() {
        return $this->allow_email;
    }

    public function registration_allowed() {
        return $this->allow_registration;
    }

    /**
     * Get user by identifier (email / phone)
     */
    public function get_user($identifier) {
        if (is_email($identifier) && $this->email_allowed()) {
            return get_user_by('email', $identifier);

        } else {
            $phone = $this->prepare_phone($identifier);

            $users = get_users([
                'meta_key'   => 'mobile',
                'meta_value' => $identifier
            ]);

            if ($users) {
                return $users[0];
            }

        }

        return false;
    }

    /**
     * Create new user
     *
     * @param string $identifier phone number or email address
     */
    public function create_user($identifier) {
        if (! $identifier || $this->get_user($identifier)) {
            return false;
        }

        if (is_email($identifier) && $this->email_allowed()) {
            $user_id = wp_insert_user([
                'user_login'    => $identifier,
                'user_email'    => $identifier,
            ]);

        } else {
            $login_phone = $this->prepare_phone($identifier);

            if (! $login_phone) return false; // return if invalid phone number

            $user_id = wp_insert_user([
                'user_login'    => $login_phone,
                'role'          => 'contributer',
            ]);

            update_user_meta($user_id, 'mobile_number', $login_phone);
        }

        if ($user_id && ! is_WP_Error($user_id)) {
            return $user_id;
        }

        return false;
    }

    public function prepare_phone($phone) {
        $phone = str_replace([' ', '-', '_'], '', $phone);
        $phone = str_replace('+9809', '+989', $phone);

        if (strlen($phone) < 11 || preg_match("/[a-z]/i", $phone)) {
            return false;
        }

        return $phone;
    }

    /**
     * Generate a hash
     */
    public function gen_hash() {
        $token = $this->generate_token();
        $hash_method = 'sha256';
        $hash = unpack('N2', hash( $hash_method, $token ));
        $hash = $hash[1] & 0x000FFFFF;

        // One more time if it's not 6 digits
        if ($hash < 100000) {
            $hash += rand(100000, 500000) - $hash;
        }

        return $hash;
    }

	/**
	 * Generate a cryptographically-secure, url-friendly verification token
	 *
	 */
	public function generate_token(){
		$bytes = random_bytes( 16 );
		return bin2hex( $bytes );
    }

    public function send_otp($user_id, $identifier) {
        $user = get_user_by('id', $user_id);

        if (! $user) return false;
        if ((! is_email($identifier) && $this->email_allowed()) && ! $this->prepare_phone($identifier)) return false;

        $otp = $this->gen_hash();

        if (is_email($identifier) && $this->email_allowed()) {
            $this->set_otp_meta($user_id, $otp);
            $this->send_otp_by_email($user_id, $otp);

        } else {
            // $this->set_otp_meta($user_id, $otp);
            // $this->send_otp_by_email($user_id, $otp);
            /**
             * ^^^^^^^
             * Above lines are just for testing purpose when you don't have any sms sender
             * Uncomment lines above and use fakemail apps like papercut to get the OTP code and test it
             */
            
            $phone = get_user_meta($user_id, 'mobile_number', true);
            $this->set_otp_meta($user_id, $otp);
            $this->send_otp_by_sms($user_id, $otp);
        }

        return $otp;
    }

    public function permission_cb() {
        return ! is_user_logged_in();
    }

    public function change_phone_permission_cb() {
        return is_user_logged_in();
    }

    public function get_from_address() {
        return 'users@farsroid.com';
    }

    public function get_from_name() {
        return 'فارسروید';
    }

    public function get_content_type() {
        return 'text/html';
    }

    public function send_otp_by_email($user_id, $otp) {
        $email = get_userdata($user_id)->user_email;

        if (! $email) {
            return false;
        }

        $headers = 'Content-Type: text/html' . "\r\n";
		add_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
		add_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );
        wp_mail($email, $this->get_mail_subject(), $this->get_email_body($otp), $headers);
        remove_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		remove_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
		remove_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );
    }

    public function send_otp_by_sms($user_id, $otp, $phone = false) {
        if (! $phone && is_user_logged_in()) {
            $phone = get_user_meta($user_id, 'mobile_number', true);
        } else {
            return;
        }

        global $sms;
        if ($sms) {
            $sms->to = [str_replace('+98', '0', $phone)];
            $sms->msg = $this->get_sms_body($otp);
            $sms->isflash = apply_filters('dw_sms_send_flash_message', true);
            $sms->SendSMS();
        }
    }

    public function get_sms_body($otp) {
        return "کد تایید شما: $otp
        فارسروید
        ";
    }

    public function get_mail_subject() {
        return '[فارسروید] کد تایید ورود به حساب کاربری';
    }

    public function get_email_body($otp) {
        return "
        <html>
            <body style='background-color: #fff;'>
                <div style='background-color: #f2f2f2; padding: 50px 25px; margin: 40px; font-family: tahoma, arial, sans-serif; font-size: 13px; font-weight: 500; direction: rtl; text-align: center;'>
                کد تایید ورود به حساب کاربری شما :
                <br>
                <span style='background-color: #333; color: #fff; border-radius: 10px; display: block; font-weight: 700; width: 200px; margin: 30px auto; padding: 30px; font-size: 22px;'>$otp</span>

                فارسروید
                </div>
            </body>
        </html>
        ";
    }

    public function set_otp_meta($user_id, $otp) {
        update_user_meta($user_id, '_otp', $otp);
        update_user_meta($user_id, '_otp_tried', '0');
        update_user_meta($user_id, '_otp_request_time', time());
        update_user_meta($user_id, '_otp_expire', time() + 1200);
    }

    public function rest_cb($data) {
        $identifier = $data->get_param('identifier');
        $register = (bool) $data->get_param('register');

        if (! is_email($identifier) && ! $this->prepare_phone($identifier)) {
            wp_send_json_error([
                'message'   => $this->email_allowed() ? 'شماره یا ایمیل خود را وارد کنید' : 'لطفا شماره خود را وارد کنید'
            ]);
        }

        $identifier = is_email($identifier) ? $identifier : $this->prepare_phone($identifier);
        $user = $this->get_user($identifier);

        // Login if current user exists
        if ($user && ! is_WP_Error($user)) {
            $user_id = $user->ID;
            $last_request_time = absint(get_user_meta($user->ID, '_otp_request_time', true));

            if ($last_request_time && (time() - $last_request_time) < 4) {
                $time = 120 - (time() - $last_request_time);
                wp_send_json_error([
                    'message'   => "برای ارسال مجدد کد باید حداقل $time ثانیه صبر کنید"
                ]);
            }

            $otp = $this->send_otp($user->ID, $identifier);
        }

        // Or?! Register a new user
        elseif ($register && $this->registration_allowed()) {
            $user_id = $this->create_user($identifier);
            $otp = $this->send_otp($user_id, $identifier);
        }

        wp_send_json_success([
            'message'   => 'کد فعالسازی ارسال شد',
            'user_id'   => $user_id,
        ]);
    }

    public function verify_cb($data) {
        $identifier = $data->get_param('identifier');
        $otp = $data->get_param('otp');
        $identifier = is_email($identifier) ? $identifier : $this->prepare_phone($identifier);
        $user = $this->get_user($identifier);
        $user_id = $user->ID;

        if (! $otp) {
            wp_send_json_error([
                'message'   => 'کد تایید را وارد کنید'
            ]);
        }

        if (! $user_id) {
            wp_send_json_error([
                'message'   => 'کاربر یافت نشد'
            ]);
        }

        $last_request_time = absint(get_user_meta($user_id, '_otp_request_time', true));
        $otp_expire = absint(get_user_meta($user_id, '_otp_expire', true));
        $user_otp = get_user_meta($user_id, '_otp', true);
        $tries = absint(get_user_meta($user_id, '_otp_tried', true)) + 1;
        update_user_meta($user_id, '_otp_tried', $tries);

        if ($tries > 5) {
            wp_send_json_error([
                'message' => "تعداد دفعات تلاش برای ورود بیش از حد مجاز بوده است لطفا دوباره امتحان کنید"
            ]);
        }

        if ($user_otp === $otp) {
            if ($otp_expire && $otp_expire < time()) {
                wp_send_json_error([
                    'message' => "کد فعالسازی منقضی شده است"
                ]);
            }

            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            $user = get_user_by('id', $user_id);
            do_action( 'wp_login', $user->user_login, $user );
            wp_send_json_success(apply_filters('dornaweb_success_login_message', [
                'user_id'   => $user_id,
                'message'   => 'شما با موفقیت وارد شدید',
                'redirect'  => get_permalink(dw_option('page_account'))
            ], $user_id));

        } else {
            wp_send_json_error([
                'message'   => 'کد وارد شده اشتباه است'
            ]);
        }
    }

    public function change_phone($request) {
        $phone = $request->get_param('identifier');
        $otp = $request->get_param('otp');
        $current_user = wp_get_current_user();

        // Verify
        if ($otp) {

        } else { // Send verification code
            $last_request_time = absint(get_user_meta($current_user->ID, '_otp_request_time', true));

            if ($last_request_time && (time() - $last_request_time) < 4) {
                $time = 120 - (time() - $last_request_time);
                wp_send_json_error([
                    'message'   => "برای ارسال مجدد کد باید حداقل $time ثانیه صبر کنید"
                ]);
            }

            $realOtp = $this->gen_hash();
            $this->set_otp_meta($current_user->ID, $realOtp);
            $this->send_otp_by_sms($current_user->ID, $realOtp);
        }
    }

    public function phone_fields() {
        add_action('show_user_profile', [$this, 'phone_field_html']);
        add_action('edit_user_profile', [$this, 'phone_field_html']);

        add_action('personal_options_update', [$this, 'phone_field_save']);
        add_action('edit_user_profile_update', [$this, 'phone_field_save']);
    }

    public function phone_field_html($user) { ?>
        <h3>اطلاعات کاربر</h3>

        <table class="form-table">
        <tr>
            <th><label for="mobile"><?php _e("شماره موبایل"); ?></label></th>
            <td>
                <input type="text" name="mobile" id="mobile" value="<?php echo esc_attr( get_the_author_meta( 'mobile', $user->ID ) ); ?>" class="regular-text" /><br />
                <span class="description">شماره موبایل خود را وارد کنید</span>
            </td>
        </tr>
        </table>
    <?php
    }

    public function phone_field_save($user_id) {
        if ( !current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }

        update_user_meta( $user_id, 'mobile', $_POST['mobile'] );
    }
}

$login = new dw_sms_login();
