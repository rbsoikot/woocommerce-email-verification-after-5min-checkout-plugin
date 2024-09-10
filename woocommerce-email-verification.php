<?php
/*
Plugin Name: WooCommerce Email Verification
Description: Adds email verification functionality to WooCommerce.
Version: 1.0
Author: RB Soikot
*/

// Hook into WooCommerce Checkout Process
add_action('woocommerce_checkout_order_processed', 'create_user_account_after_order', 10, 1);

function create_user_account_after_order($order_id) {
    $order = wc_get_order($order_id);
    $user_email = $order->get_billing_email();
    $first_name = $order->get_billing_first_name();
    $last_name = $order->get_billing_last_name();
    
    if (email_exists($user_email)) {
        return;
    }
    
    $username = sanitize_user(current(explode('@', $user_email)), true);
    $password = wp_generate_password();
    $user_id = wp_create_user($username, $password, $user_email);
    
    wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
    update_user_meta($user_id, '_email_verified', false);
    
    send_verification_email($user_id, $user_email);
}

// Handle email verification
add_action('init', 'handle_email_verification');

function handle_email_verification() {
    if (isset($_GET['user_id']) && isset($_GET['verification_code'])) {
        $user_id = intval($_GET['user_id']);
        $verification_code = sanitize_text_field($_GET['verification_code']);
        
        $saved_code = get_user_meta($user_id, '_email_verification_code', true);
        
        if ($saved_code === $verification_code) {
            update_user_meta($user_id, '_email_verified', true);
            wp_redirect(home_url('/my-account/'));
            exit;
        } else {
            wp_redirect(home_url('/verification-failed/'));
            exit;
        }
    }
}

// Add a custom message to the order confirmation page
add_action('woocommerce_thankyou', 'add_email_verification_message', 10, 1);

function add_email_verification_message($order_id) {
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    $user_id = $order->get_user_id();

    if ($user_id) {
        $is_verified = get_user_meta($user_id, '_email_verified', true);

        if (!$is_verified) {
            // Resend verification email
            send_verification_email($user_id, get_userdata($user_id)->user_email);
            echo '<div class="woocommerce-info">Verify your email first. A verification email has been sent to your email address.</div>';
        }
    }
}

function send_verification_email($user_id, $user_email) {
    $verification_code = md5(uniqid($user_email, true));
    update_user_meta($user_id, '_email_verification_code', $verification_code);
    
    $verification_link = add_query_arg(array(
        'user_id' => $user_id,
        'verification_code' => $verification_code
    ), home_url('/verify-email/'));
    
    $subject = 'Please verify your email';
    $message = 'Click the following link to verify your email: ' . $verification_link;
    
    wp_mail($user_email, $subject, $message);
}

// Prevent login if email is not verified and resend verification email
add_action('wp_authenticate_user', 'prevent_unverified_login', 10, 2);

function prevent_unverified_login($user, $password) {
    $is_verified = get_user_meta($user->ID, '_email_verified', true);
    if (!$is_verified) {
        send_verification_email($user->ID, $user->user_email);
        return new WP_Error('email_not_verified', 'Your email has not been verified. Please check your inbox for the verification email.');
    }
    return $user;
}

// Customize login error message
add_filter('login_errors', 'customize_login_error_message');

function customize_login_error_message($error) {
    if (strpos($error, 'email_not_verified') !== false) {
        return 'Your email address has not been verified. A verification email has been sent. Please check your inbox.';
    }
    return $error;
}

















