<?php

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Ameria_VPOS_Blocks_Support extends AbstractPaymentMethodType {
    protected $name = 'ameria_vpos';

    protected $settings = array();

    public function initialize() {
        $this->settings = get_option('woocommerce_ameria_vpos_settings', array());
    }

    public function is_active() {
        if (empty($this->settings)) {
            $this->initialize();
        }

        return isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles() {
        $script_path = plugin_dir_path(dirname(__FILE__)) . 'assets/js/ameria-vpos-blocks.js';
        $script_url = plugin_dir_url(dirname(__FILE__)) . 'assets/js/ameria-vpos-blocks.js';

        wp_register_script(
            'ameria-vpos-blocks',
            $script_url,
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
            ),
            file_exists($script_path) ? filemtime($script_path) : '1.0.0',
            true
        );

        return array('ameria-vpos-blocks');
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        if (empty($this->settings)) {
            $this->initialize();
        }

        return array(
            'title' => !empty($this->settings['title'])
                ? $this->settings['title']
                : 'Credit/Debit Card',
            'description' => !empty($this->settings['description'])
                ? $this->settings['description']
                : 'Pay securely by card via Ameriabank.',
            'supports' => array('products'),
        );
    }
}
