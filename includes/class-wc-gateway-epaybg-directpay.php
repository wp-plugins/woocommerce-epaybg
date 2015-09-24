<?php

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

/**
 * ePay.bg BORICA Checkout Gateway
 *
 * Provides WooCommerce with ePay.bg Checkout integration.
 *
 * @class   WC_Gateway_Epaybg_DirectPay
 * @extends WC_Gateway_Epaybg
 * @version 1.0.0
 * @package WooCommerce/Classes/Payment
 * @author  dimitrov.adrian
 */
class WC_Gateway_Epaybg_DirectPay extends WC_Gateway_Epaybg {

  /**
   * Constructor for the gateway.
   *
   * @access public
   */
  public function __construct() {

    // Load main settings.
    parent::init_user_settings();

    // Gateway settings.
    $this->id                             = 'epaybg_directpay';
    $this->liveurl                        = 'https://www.epay.bg/';
    $this->testurl                        = 'https://demo.epay.bg/';
    $this->method_title                   = __('ePay.bg - BORICA', 'woocommerce-epaybg');
    $this->method_description             = __('ePay.bg - BORICA derivate allow customers to pay directly with their credit or debit cards. It is works by sending the user to BORICA platform to enter their payment information.', 'woocommerce-epaybg');
    $this->epaybg_pay_method              = 'credit_paydirect';

    // Init gateway.
    $this->init_settings();
    $this->init_user_settings();
    $this->init();
  }

  /**
   * Load user defined settings.
   */
  public function init_user_settings() {
    $this->title                          = $this->get_option('title');
    $this->description                    = $this->get_option('description');
    $this->enabled                        = $this->title && $this->get_option('enabled') == 'yes' ? 'yes' : 'no';
  }

  /**
   * Initialise Gateway Settings Form Fields
   *
   * @return void
   */
  public function init_form_fields() {
    $this->form_fields = array(
      'enabled' => array(
        'title'         => __('Enable/Disable', 'woocommerce'),
        'type'          => 'checkbox',
        'label'         => __('Enable ePay.bg Direct Pay Checkout', 'woocommerce-epaybg'),
        'default'       => 'no'
      ),
      'title' => array(
        'title'         => __('Title', 'woocommerce'),
        'type'          => 'text',
        'description'   => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default'       => __('BORICA', 'woocommerce-epaybg'),
      ),
      'description' => array(
        'title'         => __('Description', 'woocommerce'),
        'type'          => 'textarea',
        'default'       => __('Pay securely with your credit card.', 'woocommerce-epaybg'),
        'description'   => __('This controls the description which the user sees during checkout.', 'woocommerce'),
      ),
    );
  }

}
