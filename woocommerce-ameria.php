<?php
/**
 * Plugin Name: Ameria vPOS Gateway for WooCommerce
 * Description: WooCommerce payment gateway integration for Ameriabank vPOS 3.1 REST API.
 * Version: 1.0.4
 * Author: Ashot Karapetyan
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'ameria_vpos_init_gateway');

function ameria_vpos_init_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Ameria_VPOS extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'ameria_vpos';
            $this->method_title = 'Ameria vPOS';
            $this->method_description = 'Pay securely with Ameriabank vPOS.';
            $this->has_fields = false;
            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                array($this, 'process_admin_options')
            );

            add_action(
                'woocommerce_api_ameria_vpos_return',
                array($this, 'handle_return')
            );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Ameria vPOS',
                    'default' => 'no',
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'Credit/Debit Card',
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'default' => 'Pay securely by card via Ameriabank.',
                ),
                'client_id' => array(
                    'title' => 'Client ID',
                    'type' => 'text',
                    'default' => '',
                ),
                'username' => array(
                    'title' => 'Username',
                    'type' => 'text',
                    'default' => '',
                ),
                'password' => array(
                    'title' => 'Password',
                    'type' => 'password',
                    'default' => '',
                ),
                'base_url' => array(
                    'title' => 'Ameria vPOS Base URL',
                    'type' => 'text',
                    'default' => 'https://servicestest.ameriabank.am/VPOS',
                    'description' => 'Use test URL for testing. Replace with live URL when Ameriabank gives it.',
                ),
                'currency' => array(
                    'title' => 'Currency',
                    'type' => 'select',
                    'default' => '051',
                    'options' => array(
                        '051' => 'AMD',
                        '840' => 'USD',
                        '978' => 'EUR',
                        '643' => 'RUB',
                    ),
                ),
                'language' => array(
                    'title' => 'Payment Page Language',
                    'type' => 'select',
                    'default' => 'en',
                    'options' => array(
                        'en' => 'English',
                        'ru' => 'Russian',
                        'am' => 'Armenian',
                    ),
                ),
                'test_mode' => array(
                    'title' => 'Ameria test mode',
                    'type' => 'checkbox',
                    'label' => 'Use Ameria test rules: OrderID 30299001-30300000 and Amount 10 AMD',
                    'default' => 'yes',
                ),
                'two_stage' => array(
                    'title' => 'Two-stage payment',
                    'type' => 'checkbox',
                    'label' => 'Call ConfirmPayment after successful authorization',
                    'default' => 'no',
                ),
                'debug_notes' => array(
                    'title' => 'Debug order notes',
                    'type' => 'checkbox',
                    'label' => 'Save Ameria debug info in order notes',
                    'default' => 'yes',
                ),
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                wc_add_notice('Invalid order.', 'error');
                return array('result' => 'failure');
            }

            $payment = $this->init_payment($order);

            if (is_wp_error($payment)) {
                $message = $payment->get_error_message();
                wc_add_notice($message, 'error');
                $order->update_status('failed', $message);

                return array('result' => 'failure');
            }

            $payment_id = isset($payment['PaymentID']) ? sanitize_text_field($payment['PaymentID']) : '';

            if (!$payment_id) {
                $message = 'Ameria did not return PaymentID.';
                wc_add_notice($message, 'error');
                $order->update_status('failed', $message);

                return array('result' => 'failure');
            }

            $order->update_meta_data('_ameria_payment_id', $payment_id);
            $order->save();

            $redirect_url = trailingslashit($this->get_base_url()) . 'Payments/Pay?' . http_build_query(array(
                'id' => $payment_id,
                'lang' => $this->get_option('language', 'en'),
            ));

            $order->update_status('pending', 'Customer redirected to Ameria vPOS.');

            $this->debug_note($order, 'Ameria InitPayment response: ' . wc_print_r($payment, true));
            $this->debug_note($order, 'Ameria PaymentID: ' . $payment_id);
            $this->debug_note($order, 'Ameria redirect URL: ' . $redirect_url);

            return array(
                'result' => 'success',
                'redirect' => esc_url_raw($redirect_url),
            );
        }

        private function init_payment(WC_Order $order) {
            $back_url = add_query_arg(
                array(
                    'wc-api' => 'ameria_vpos_return',
                    'order_id' => $order->get_id(),
                    'key' => $order->get_order_key(),
                ),
                home_url('/')
            );

            $is_test_mode = $this->get_option('test_mode', 'yes') === 'yes';

            if ($is_test_mode) {
                $current_test_order_id = (int) get_option('ameria_vpos_test_order_id', 30299000);
                $ameria_order_id = $current_test_order_id + 1;

                if ($ameria_order_id > 30300000 || $ameria_order_id < 30299001) {
                    $ameria_order_id = 30299001;
                }

                update_option('ameria_vpos_test_order_id', $ameria_order_id);

                $amount = 10;
                $currency = '051';
                $description = 'WooCommerce test order #' . $order->get_order_number();
            } else {
                $attempt = (int) $order->get_meta('_ameria_payment_attempt');
                $attempt++;

                $ameria_order_id = (int) ($order->get_id() . str_pad((string) $attempt, 2, '0', STR_PAD_LEFT));

                $order->update_meta_data('_ameria_payment_attempt', $attempt);

                $amount = (float) $order->get_total();
                $currency = $this->get_option('currency', '051');
                $description = 'WooCommerce order #' . $order->get_order_number() . ', attempt #' . $attempt;
            }

            $order->update_meta_data('_ameria_order_id', $ameria_order_id);
            $order->save();

            $payload = array(
                'ClientID' => $this->get_option('client_id'),
                'Username' => $this->get_option('username'),
                'Password' => $this->get_option('password'),
                'Currency' => $currency,
                'Description' => $description,
                'OrderID' => $ameria_order_id,
                'Amount' => $amount,
                'BackURL' => $back_url,
                'Opaque' => (string) $order->get_id(),
                'Timeout' => 1200,
            );

            $this->debug_note($order, 'Ameria InitPayment payload: ' . wc_print_r($this->mask_payload($payload), true));

            $response = $this->post_to_ameria('/api/VPOS/InitPayment', $payload);

            if (is_wp_error($response)) {
                return $response;
            }

            if (
                empty($response['PaymentID']) ||
                !isset($response['ResponseCode']) ||
                (string) $response['ResponseCode'] !== '1'
            ) {
                $message = !empty($response['ResponseMessage'])
                    ? $response['ResponseMessage']
                    : 'Ameria InitPayment failed.';

                return new WP_Error('ameria_init_failed', $message);
            }

            return $response;
        }

        public function handle_return() {
            $order_id = isset($_GET['order_id'])
                ? absint($_GET['order_id'])
                : (isset($_GET['orderID']) ? absint($_GET['orderID']) : 0);

            $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

            $order = wc_get_order($order_id);

            if (!$order) {
                wp_die('Order not found.');
            }

            if ($key && $key !== $order->get_order_key()) {
                wp_die('Invalid order key.');
            }

            $return_response_code = isset($_GET['responseCode'])
                ? sanitize_text_field(wp_unslash($_GET['responseCode']))
                : (isset($_GET['resposneCode']) ? sanitize_text_field(wp_unslash($_GET['resposneCode'])) : '');

            $payment_id_from_url = isset($_GET['paymentID'])
                ? sanitize_text_field(wp_unslash($_GET['paymentID']))
                : '';

            $saved_payment_id = $order->get_meta('_ameria_payment_id');

            $this->debug_note($order, 'Ameria return GET params: ' . wc_print_r($this->mask_payload($_GET), true));

            if (!$payment_id_from_url) {
                $order->update_status('failed', 'Ameria return did not include paymentID.');
                wc_add_notice('Payment was not completed. Missing payment ID.', 'error');
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }

            if ($saved_payment_id && strtolower($payment_id_from_url) !== strtolower($saved_payment_id)) {
                $order->update_status('failed', 'Ameria paymentID mismatch.');
                wc_add_notice('Payment verification failed. Payment ID mismatch.', 'error');
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }

            if ($return_response_code !== '00') {
                $description = isset($_GET['description'])
                    ? sanitize_text_field(wp_unslash($_GET['description']))
                    : '';

                $message = 'Ameria returned unsuccessful responseCode: ' . $return_response_code;

                if ($description) {
                    $message .= '. Description: ' . $description;
                }

                $order->update_status('failed', $message);
                wc_add_notice('Payment was not successful.', 'error');
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }

            $details = $this->get_payment_details($payment_id_from_url);

            if (is_wp_error($details)) {
                $message = $details->get_error_message();
                $order->update_status('failed', $message);
                wc_add_notice($message, 'error');
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }

            $this->debug_note($order, 'Ameria GetPaymentDetails response: ' . wc_print_r($details, true));

            $response_code = isset($details['ResponseCode']) ? (string) $details['ResponseCode'] : '';
            $order_status = isset($details['OrderStatus']) ? (string) $details['OrderStatus'] : '';
            $details_order_id = isset($details['OrderID']) ? (string) $details['OrderID'] : '';
            $saved_ameria_order_id = $order->get_meta('_ameria_order_id');

            if (
                $details_order_id &&
                $saved_ameria_order_id &&
                (string) $details_order_id !== (string) $saved_ameria_order_id
            ) {
                $order->update_status('failed', 'Ameria OrderID mismatch.');
                wc_add_notice('Payment verification failed. Order mismatch.', 'error');
                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }

            $is_single_stage_success = $response_code === '00' && $order_status === '2';

            $is_two_stage_authorized = $response_code === '00'
                && $this->get_option('two_stage') === 'yes'
                && in_array($order_status, array('1', '5'), true);

            if (!$is_single_stage_success && !$is_two_stage_authorized) {
                $message = isset($details['TrxnDescription']) && $details['TrxnDescription']
                    ? $details['TrxnDescription']
                    : 'Ameria payment was not successful. ResponseCode: ' . $response_code . ', OrderStatus: ' . $order_status;

                $order->update_status('failed', $message);
                wc_add_notice($message, 'error');

                wp_safe_redirect($order->get_checkout_payment_url());
                exit;
            }

            if ($is_two_stage_authorized) {
                $confirm = $this->confirm_payment($payment_id_from_url, (float) $order->get_total());

                if (is_wp_error($confirm)) {
                    $message = $confirm->get_error_message();
                    $order->update_status('on-hold', $message);
                    wc_add_notice($message, 'error');

                    wp_safe_redirect($order->get_checkout_payment_url());
                    exit;
                }

                $this->debug_note($order, 'Ameria ConfirmPayment response: ' . wc_print_r($confirm, true));
            }

            if (!$order->is_paid()) {
                $order->payment_complete($payment_id_from_url);
                $order->add_order_note('Ameria vPOS payment completed. PaymentID: ' . $payment_id_from_url);
            }

            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            wp_safe_redirect($this->get_return_url($order));
            exit;
        }

        private function get_payment_details($payment_id) {
            $payload = array(
                'PaymentID' => $payment_id,
                'Username' => $this->get_option('username'),
                'Password' => $this->get_option('password'),
            );

            return $this->post_to_ameria('/api/VPOS/GetPaymentDetails', $payload);
        }

        private function confirm_payment($payment_id, $amount) {
            $payload = array(
                'PaymentID' => $payment_id,
                'Username' => $this->get_option('username'),
                'Password' => $this->get_option('password'),
                'Amount' => $amount,
            );

            $response = $this->post_to_ameria('/api/VPOS/ConfirmPayment', $payload);

            if (is_wp_error($response)) {
                return $response;
            }

            if (empty($response['ResponseCode']) || (string) $response['ResponseCode'] !== '00') {
                $message = !empty($response['ResponseMessage'])
                    ? $response['ResponseMessage']
                    : 'Ameria ConfirmPayment failed.';

                return new WP_Error('ameria_confirm_failed', $message);
            }

            return $response;
        }

        private function post_to_ameria($path, array $payload) {
            $url = trailingslashit($this->get_base_url()) . ltrim($path, '/');

            $response = wp_remote_post($url, array(
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ),
                'body' => wp_json_encode($payload),
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);

            if ($status_code < 200 || $status_code >= 300) {
                return new WP_Error(
                    'ameria_http_error',
                    'Ameria HTTP error: ' . $status_code . '. Response: ' . $body
                );
            }

            if (!is_array($decoded)) {
                return new WP_Error(
                    'ameria_invalid_json',
                    'Invalid Ameria response: ' . $body
                );
            }

            return $decoded;
        }

        private function get_base_url() {
            return untrailingslashit($this->get_option('base_url'));
        }

        private function debug_note(WC_Order $order, $message) {
            if ($this->get_option('debug_notes', 'yes') === 'yes') {
                $order->add_order_note($message);
            }
        }

        private function mask_payload(array $payload) {
            if (isset($payload['Password'])) {
                $payload['Password'] = '***';
            }

            if (isset($payload['password'])) {
                $payload['password'] = '***';
            }

            return $payload;
        }
    }

    add_filter('woocommerce_payment_gateways', 'ameria_vpos_add_gateway');

    function ameria_vpos_add_gateway($gateways) {
        $gateways[] = 'WC_Gateway_Ameria_VPOS';
        return $gateways;
    }
}

add_action('woocommerce_blocks_payment_method_type_registration', 'ameria_vpos_register_blocks_support');

function ameria_vpos_register_blocks_support($payment_method_registry) {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-ameria-vpos-blocks-support.php';

    $payment_method_registry->register(new Ameria_VPOS_Blocks_Support());
}
