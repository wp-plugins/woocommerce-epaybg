<?php

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

/**
 * ePay.bg EasyPay Checkout Gateway
 *
 * Provides WooCommerce with ePay.bg Checkout integration.
 *
 * @class   WC_Gateway_Epaybg_EasyPay
 * @extends WC_Gateway_Epaybg
 * @version 1.0.0
 * @package WooCommerce/Classes/Payment
 * @author  dimitrov.adrian
 */
class WC_Gateway_Epaybg_EasyPay extends WC_Gateway_Epaybg {

  /**
   * Constructor for the gateway.
   *
   * @access public
   */
  public function __construct() {

    // Load main settings.
    parent::init_user_settings();

    // Gateway settings.
    $this->id                             = 'epaybg_easypay';
    $this->liveurl                        = 'https://www.epay.bg/ezp/reg_bill.cgi';
    $this->testurl                        = 'https://demo.epay.bg/ezp/reg_bill.cgi';
    $this->method_title                   = __('ePay.bg - EasyPay', 'woocommerce-epaybg');
    $this->method_description             = __('ePay.bg - EasyPay derivate allow customers to pay offline on EasyPay office or with BORICA ATM. It is works by givving the user to unique IDN number which is used to identify the order payment.', 'woocommerce-epaybg');
    $this->epaybg_pay_method              = 'ezp';

    // Init gateway.
    $this->init_settings();
    $this->init_user_settings();
    $this->init();

  }

  public function init() {
    parent::init();

    // Thank you page.
    add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

    if ($this->send_instructions_mail) {
      // Send instructions mail.
      add_action('woocommerce_thankyou_' . $this->id, array($this, 'send_instructions_mail'));
    }

    add_action('woocommerce_admin_order_data_after_order_details', array($this, 'admin_order_data'));

    // Customer Emails.
    add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
  }

  /**
   * Load user defined settings.
   */
  public function init_user_settings() {
    $this->title                          = $this->get_option('title');
    $this->description                    = $this->get_option('description');
    $this->epaybg_exptime                 = $this->get_option('epaybg_exptime', 48);
    $this->send_instructions_mail         = 'yes' == $this->get_option('send_instructions_mail', 'yes');
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
        'title'       => __('Enable/Disable', 'woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable ePay.bg EasyPay Checkout', 'woocommerce-epaybg'),
        'default'     => 'no'
      ),
      'title' => array(
        'title'       => __('Title', 'woocommerce'),
        'type'        => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default'     => __('EasyPay', 'woocommerce-epaybg'),
      ),
      'description' => array(
        'title'       => __('Description', 'woocommerce'),
        'type'        => 'textarea',
        'default'     => __('Pay in EasyPay offices.', 'woocommerce-epaybg'),
        'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
      ),
      'epaybg_exptime' => array(
        'title'       => __('Transaction expiration', 'woocommerce-epaybg'),
        'type'        => 'select',
        'options'     => array(
          24 => sprintf(_n('%s day', '%s days', 1), 1),
          48 => sprintf(_n('%s day', '%s days', 2), 2),
          72 => sprintf(_n('%s day', '%s days', 3), 3),
          120 => sprintf(_n('%s day', '%s days', 5), 5),
          168 => sprintf(_n('%s day', '%s days', 7), 7),
          240 => sprintf(_n('%s day', '%s days', 10), 10),
        ),
        'default'     => 48,
      ),
      'send_instructions_mail' => array(
        'title'       => __('Send instructions', 'woocommerce-epaybg'),
        'type'        => 'checkbox',
        'label'       => __('Send mail with instructions about how to make the payment.', 'woocommerce-epaybg'),
        'default'     => 'yes'
      ),
    );
  }

  /**
   * Place the order, and redirect to order page, where ePay.bg form is shown.
   *
   * @param int $order_id
   *
   * @return array
   */
  public function process_payment($order_id) {

    $order = wc_get_order($order_id);

    $idn_data = $this->epaybg_easypay_get_payment_code($order_id);
    if ($idn_data !== FALSE) {

      // Mark as on-hold (we're awaiting the payment)
      $order->add_order_note(sprintf(__('Awaiting payment from EasyPay/B-Pay with code: %s', 'woocommerce-epaybg'), $idn_data['idn']));

      // Remove cart
      WC()->cart->empty_cart();

      // Return thankyou redirect
      return array(
        'result'   => 'success',
        'redirect' => $this->get_return_url($order),
      );
    }
    else {
      wc_add_notice(__('EasyPay|B-Pay error while processing payment', 'woocommerce-epaybg'), 'error');
    }

  }

  public function epaybg_easypay_get_payment_code($order_id) {
    $order = wc_get_order($order_id);

    if (!$order) {
      $this->log('IDN request for unknown order: ' . $order_id);
      return;
    }

    $expires = date('d.m.Y H:i', current_time('timestamp', 0) + ($this->epaybg_exptime * 60 * 60));

    // Build pack for API service.
    $form_data  = "\nMIN={$this->client_id}";
    $form_data .= "\nINVOICE={$this->invoice_prefix}{$order->id}";
    $form_data .= "\nAMOUNT={$order->order_total}";
    $form_data .= "\nEXP_TIME={$expires}";
    $form_data .= "\nCURRENCY=" . get_woocommerce_currency();
    $form_data .= "\nENCODING=utf-8";
    $form_data .= "\nDESCR=" . sprintf(__('Order: %s', 'woocommerce'), $order->id);

    $FORM_ENCODED       = base64_encode($form_data);
    $FORM_CHECKSUM      = woocommerce_epaybg_hmac('sha1', $FORM_ENCODED, $this->secret_key);
    $FORM_SUBMIT_URL    = ($this->testmode ? $this->testurl : $this->liveurl);

    $http_args = array(
      'method'      => 'POST',
      'timeout'     => 30,
      'redirection' => 2,
      'httpversion' => '1.0',
      'blocking'    => TRUE,
      'sslverify'   => TRUE,
      'stream'      => FALSE,
      'body'        => array(
        'ENCODED' => $FORM_ENCODED,
        'CHECKSUM' => $FORM_CHECKSUM,
      ),
    );

    $response = wp_remote_post($FORM_SUBMIT_URL, $http_args);
    if (is_wp_error($response)) {
      $error_message = $response->get_error_message();
      $this->log('IDN request failed: ' . $error_message);
    }
    else {
      if (!empty($response['body'])) {
        if (preg_match('#^IDN=(\d+)$#', $response['body'], $matches)) {
          update_post_meta($order_id, '_epaybg_easypay_idn', $matches[1]);
          update_post_meta($order_id, '_epaybg_easypay_expire', $expires);
          return array(
            'idn' => $matches[1],
            'expire' => $expires,
          );
        }
        elseif (preg_match('#^ERR=(.*)$#', $response['body'], $matches)) {
          $this->log('IDN response error: ' . $matches[1]);
        }
      }
      else {
        $this->log('IDN request empty response');
      }
    }
    return FALSE;
  }


  public function admin_order_data($order) {
    if ($order->payment_method != 'epaybg_easypay') {
      return;
    }
    $idn_code = get_post_meta($order->id, '_epaybg_easypay_idn', TRUE);
    $idn_code_expire = get_post_meta($order->id, '_epaybg_easypay_expire', TRUE);
    if ($idn_code) {
      ?>
      <p class="form-field form-field-wide">
        <?php printf(__('IDN Code: %s, valid until: %s', 'woocommerce-epaybg'), $idn_code, $idn_code_expire)?>
      </p>
      <?php
    }
  }

  /**
   * @see parent::generate_epaybg_form().
   */
  public function generate_epaybg_form($order_id) {
    // This method not support pay via form.
    return NULL;
  }

  /**
   * Send mail with instructions
   *
   * @access public
   * @param WC_Order $order
   * @param bool $sent_to_admin
   * @param bool $plain_text
   */
  public function email_instructions($order, $sent_to_admin, $plain_text = false) {
    if (!$sent_to_admin && $this->id === $order->payment_method) {
      $this->thankyou_page($order->id);
    }
  }

  /**
   * Show instructions.
   *
   * @param int $order_id
   */
  public function thankyou_page($order_id = NULL) {

    $order = wc_get_order($order_id);
    if ($order) {
      $tokens = array(
        '{order_id}'    => $order->get_order_number(),
        '{idn_code}'    => get_post_meta($order_id, '_epaybg_easypay_idn', TRUE),
        '{expire_date}' => get_post_meta($order_id, '_epaybg_easypay_expire', TRUE),
        '{order_total}' => $order->get_formatted_order_total(),
      );
      $instructions = __('
To pay your order ({order_id}) with EasyPay or B-Pay service you can use next tutorials.

Payment via EasyPay
1. Go in some of the <a href="http://easypay.bg/?p=offices" target="_blank">EasyPay offices</a> (http://easypay.bg/?p=offices)
2. Say your IDN code "{idn_code}" to office agent
3. You will be asked to pay {order_total}

Payment via ATM and B-Pay service
If you prefere pay via ATM and B-Pay method then you can follow these steps.
1. Go and find some of the <a href="https://www.epay.bg/en/?page=front_wiki&p=b-pay_atm" target="_blank">ATMs that supports BPay payments</a> (https://www.epay.bg/en/?page=front_wiki&p=b-pay_atm)
2. Put your card in the ATM
3. Select "Other services"
4. Select "B-Pay"
5. Enter Merchant code - "60000"
6. Enter your IDN code "{idn_code}"
7. You will be asked to pay {order_total}

To do the this payment with some of these methods, your should take in mind that your IDN code is valid until {expire_date}, after that your IDN code will be invalid, and payment will be refused.

More information you can found on www.easypay.bg and www.epay.bg/en', 'woocommerce-epaybg');

      $instructions = wpautop(wptexturize(strtr($instructions, $tokens)));
      echo $instructions;
    }
  }

  /**
   * Send a notification to the user handling orders.
   *
   * @param int $order_id
   */
  public function send_instructions_mail($order_id = NULL) {
    $order = wc_get_order($order_id);
    $mailer = WC()->mailer();

    if ($order && $order->billing_email && isset($mailer->emails['WC_Email_New_Order']) && add_post_meta($order_id, '_epaybg_easypay_instructions_sent', time(), TRUE)) {

      ob_start();
      ?>

      <p><?php printf( __( 'Hello %s,', 'woocommerce-epaybg'), $order->billing_first_name . ' ' . $order->billing_last_name ); ?></p>
      <p>
        <?php printf(__('You have new order on %s, this email include instructions how to pay the order.', 'woocommerce-epaybg'), get_bloginfo('name'))?>
      </p>

      <?php do_action( 'woocommerce_email_before_order_table', $order, false, false ); ?>

      <h2><?php printf( __( 'Order: %s', 'woocommerce'), $order->get_order_number() ); ?> (<?php printf( '<span datetime="%s">%s</span>', date_i18n('c', strtotime($order->order_date)), date_i18n(wc_date_format(), strtotime($order->order_date)))?>)</h2>

      <table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
        <thead>
        <tr>
          <th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Product', 'woocommerce' ); ?></th>
          <th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Quantity', 'woocommerce' ); ?></th>
          <th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Price', 'woocommerce' ); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php echo $order->email_order_items_table( false, true ); ?>
        </tbody>
        <tfoot>
        <?php
        if ( $totals = $order->get_order_item_totals() ) {
          $i = 0;
          foreach ( $totals as $total ) {
            $i++;
            ?><tr>
            <th scope="row" colspan="2" style="text-align:left; border: 1px solid #eee; <?php if ( $i == 1 ) echo 'border-top-width: 4px;'; ?>"><?php echo $total['label']; ?></th>
            <td style="text-align:left; border: 1px solid #eee; <?php if ( $i == 1 ) echo 'border-top-width: 4px;'; ?>"><?php echo $total['value']; ?></td>
            </tr><?php
          }
        }
        ?>
        </tfoot>
      </table>

      <?php do_action( 'woocommerce_email_after_order_table', $order, true, false ); ?>

      <?php do_action( 'woocommerce_email_order_meta', $order, true, false ); ?>

      <h2><?php _e( 'Customer details', 'woocommerce' ); ?></h2>

      <?php if ( $order->billing_email ) : ?>
        <p><strong><?php _e( 'Email:', 'woocommerce' ); ?></strong> <?php echo $order->billing_email; ?></p>
      <?php endif; ?>
      <?php if ( $order->billing_phone ) : ?>
        <p><strong><?php _e( 'Tel:', 'woocommerce' ); ?></strong> <?php echo $order->billing_phone; ?></p>
      <?php endif; ?>

      <?php wc_get_template( 'emails/email-addresses.php', array( 'order' => $order ) ); ?>
      <?php
      $message = ob_get_clean();
      $heading = sprintf(__('You have new order (%s) from %s', 'woocommerce-epaybg'), $order->get_order_number(), get_bloginfo('name'));
      $message = $mailer->wrap_message($heading, $message);
      $mailer->send($order->billing_email, $heading, $message);
    }
  }

}


/**
 * Hook that add IDN code to payment method in the order detials.
 *
 * @see woocommerce_get_order_item_totals
 *
 * @param $total_rows
 * @param $order
 *
 * @return mixed
 */
function woocommerce_epaybg_easypay_order_details_table($total_rows, $order) {
  if ($order->payment_method == 'epaybg_easypay') {
    $idn_code = get_post_meta($order->id, '_epaybg_easypay_idn', TRUE);
    $idn_code_expire = get_post_meta($order->id, '_epaybg_easypay_expire', TRUE);
    if ($idn_code) {
      $total_rows['payment_method']['value'] .= '<br />';
      $total_rows['payment_method']['value'] .= sprintf(__('IDN Code: %s, valid until: %s', 'woocommerce-epaybg'), $idn_code, $idn_code_expire);
    }
  }
  return $total_rows;
}
add_filter('woocommerce_get_order_item_totals', 'woocommerce_epaybg_easypay_order_details_table', 10, 2);


/**
 * Delete easypay meta about idn and expire
 *
 * @param $order_id
 * @param $old_status
 * @param $new_status
 */
function woocommerce_epaybg_order_status_changed($order_id, $old_status, $new_status) {
  if ($old_status == 'wc-cancelled') {
    delete_post_meta($order_id, '_epaybg_easypay_idn');
    delete_post_meta($order_id, '_epaybg_easypay_expire');
    delete_post_meta($order_id, '_epaybg_easypay_instructions_sent');
    $this->epaybg_easypay_get_payment_code($order_id);
  }
}
add_action('woocommerce_order_status_changed', 'woocommerce_epaybg_order_status_changed', 10, 3);
