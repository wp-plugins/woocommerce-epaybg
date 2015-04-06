<?php

/**
 * Plugin Name: WooCommerce ePay.bg Gateway
 * Plugin URI: https://wordpress.org/plugins/woocommerce-epaybg/
 * Description: epaybg Checkout provides a fully integration with ePay.bg platform, secure way to collect and transmit credit card data to your payment gateway while keeping you in control of the design of your site <a target="_blank" href="https://www.epay.bg/img/x/readme_web.pdf">ePay.bg API integration README_WEB.pdf</a>.
 * Version: 1.0
 * Author: dimitrov.adrian
 * Author URI: http://e01.scifi.bg/
 * Text Domain: woocommerce-epaybg
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

/**
 * Epaybg Checkout main class.
 */
class WC_Epaybg_Checkout {

  /**
   * Plugin version.
   *
   * @var string
   */
  const VERSION = '1.0';

  /**
   * Instance of this class.
   *
   * @var object
   */
  protected static $instance = NULL;

  /**
   * Initialize the plugin public actions.
   */
  private function __construct() {
    // Load plugin text domain
    load_plugin_textdomain('woocommerce-epaybg', FALSE, dirname(plugin_basename(__FILE__)) . '/languages');

    // Checks with WooCommerce is installed.
    if (defined('WC_VERSION') && version_compare(WC_VERSION, '2.1', '>=')) {
      $this->includes();

      add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
    }
    else {
      add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
    }
  }

  /**
   * Return an instance of this class.
   *
   * @return object A single instance of this class.
   */
  public static function get_instance() {
    // If the single instance hasn't been set, set it now.
    if (NULL == self::$instance) {
      self::$instance = new self;
    }

    return self::$instance;
  }

  /**
   * Includes.
   *
   * @return void
   */
  private function includes() {
    include_once 'includes/class-wc-gateway-epaybg.php';
    include_once 'includes/class-wc-gateway-epaybg-directpay.php';
    include_once 'includes/class-wc-gateway-epaybg-easypay.php';
  }

  /**
   * Add the ePay.bg gateways.
   *
   * @param  array $methods WooCommerce payment methods.
   *
   * @return array          epaybg Checkout gateway.
   */
  public function add_gateway($methods) {

    $methods[] = 'WC_Gateway_Epaybg';
    $methods[] = 'WC_Gateway_Epaybg_DirectPay';
    $methods[] = 'WC_Gateway_Epaybg_EasyPay';
    return $methods;
  }

  /**
   * WooCommerce fallback notice.
   *
   * @return string
   */
  public function woocommerce_missing_notice() {
    echo '<div class="error"><p>' . sprintf(__('ePay.bg Checkout depends on the %s or later to work!', 'woocommerce-epaybg'), '<a href="http://wordpress.org/extend/plugins/woocommerce/">' . __('WooCommerce 2.2', 'woocommerce-gateway-epaybg-checkout') . '</a>') . '</p></div>';
  }
}

add_action('plugins_loaded', array('WC_Epaybg_Checkout', 'get_instance'), 0);
