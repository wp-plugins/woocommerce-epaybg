<?php

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

/**
 * hmac implementation used by ePay.bg
 *
 * @param $algo
 * @param $data
 * @param $passwd
 *
 * @return mixed
 */
function woocommerce_epaybg_hmac($algo, $data, $passwd) {
  /* md5 and sha1 only */
  $algo = strtolower($algo);
  $p = array('md5' => 'H32', 'sha1' => 'H40');
  if (strlen($passwd) > 64) {
    $passwd = pack($p[$algo], $algo($passwd));
  }
  if (strlen($passwd) < 64) {
    $passwd = str_pad($passwd, 64, chr(0));
  }

  $ipad = substr($passwd, 0, 64) ^ str_repeat(chr(0x36), 64);
  $opad = substr($passwd, 0, 64) ^ str_repeat(chr(0x5C), 64);

  return ($algo($opad . pack($p[$algo], $algo($ipad . $data))));
}

/**
 * ePay.bg Checkout Gateway
 *
 * Provides WooCommerce with ePay.bg Checkout integration.
 *
 * @class   WC_Gateway_Epaybg
 * @extends WC_Payment_Gateway
 * @version 1.0.0
 * @package WooCommerce/Classes/Payment
 * @author  dimitrov.adrian
 */
class WC_Gateway_Epaybg extends WC_Payment_Gateway {

  public $has_fields  = FALSE;
  public $supports    = array('products');

  // This ID must be here because propertly work of ePay.bg derivated gateways.
  public $id          = 'epaybg';

  /**
   * Constructor for the gateway.
   *
   * @access public
   */
  public function __construct() {

    // Gateway settings.
    $this->liveurl                        = 'https://www.epay.bg/';
    $this->testurl                        = 'https://demo.epay.bg/';
    $this->method_title                   = __('ePay.bg', 'woocommerce-epaybg');
    $this->method_description             = __('ePay.bg main gateway works by sending the user to ePay.bg to enter their payment information. More about API integration on <a href="https://www.epay.bg/img/x/readme_web.pdf" target="_blank">readme.pdf</a>', 'woocommerce-epaybg');
    $this->epaybg_pay_method              = 'paylogin';

    // Init gateway.
    $this->init_settings();
    $this->init_user_settings();
    $this->init();
  }

  /**
   * Load user defined settings.
   */
  public function init_user_settings() {

    $this->title                             = $this->get_option('title');
    $this->description                       = $this->get_option('description');
    $this->testmode                          = $this->get_option('testmode') == 'yes';
    $this->debug                             = $this->get_option('debug') == 'yes';
    $this->disable_plugin_ipn_key_check      = $this->get_option('disable_plugin_ipn_key_check') == 'yes';
    $this->secret_key                        = preg_replace('#\s+#', '', $this->get_option('secret_key'));
    $this->client_id                         = $this->get_option('client_id');
    $this->epaybg_redirect                   = $this->get_option('epaybg_redirect');
    $this->epaybg_exptime                    = $this->get_option('epaybg_exptime', 12);
    $this->epaybg_redirect_in_new_window     = $this->get_option('epaybg_redirect_in_new_window') == 'yes';
    $this->ipn_key                           = md5(wp_salt() . $this->secret_key);
    $this->notify_url                        = add_query_arg(array('hash' => $this->ipn_key), WC()->api_request_url('WC_Gateway_Epaybg'));
    $this->settings['epaybg_ipn_notify_url'] = $this->notify_url;
    $this->invoice_prefix                    = substr(preg_replace('#[^\d]#', '', $this->get_option('invoice_prefix')), 0, 10);
    $this->enabled                           = $this->title && $this->get_option('enabled') == 'yes' ? 'yes' : 'no';
  }

  /**
   * Init class actions.
   */
  protected function init() {

    // Set gateway icon
    $this->icon = apply_filters('woocommerce_epaybg_icon', plugins_url('assets/icon-' . $this->id . '@2x.png', plugin_dir_path(__FILE__)), $this->id);

    // Load the settings.
    $this->init_form_fields();
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

    // Payment listener/API hook, must be performed only for main class.
    add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'epaybg_ipn_response'));

    if (!$this->is_valid_for_use()) {
      $this->enabled = 'no';
    }

    // Logs
    if ($this->debug) {
      $this->_logger = new WC_Logger();
    }

  }

  /**
   * Initialise Gateway Settings Form Fields
   *
   * @return void
   */
  public function init_form_fields() {
    $this->form_fields = array(
      'enabled' => array(
        'title'               => __('Enable/Disable', 'woocommerce'),
        'type'                => 'checkbox',
        'label'               => __('Enable ePay.bg Checkout', 'woocommerce-epaybg'),
        'default'             => 'no'
      ),
      'title' => array(
        'title'               => __('Title', 'woocommerce'),
        'type'                => 'text',
        'description'         => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default'             => __('ePay.bg', 'woocommerce-epaybg'),
      ),
      'description' => array(
        'title'               => __('Description', 'woocommerce'),
        'type'                => 'textarea',
        'default'             => __('Pay securely with your credit card.', 'woocommerce-epaybg'),
        'description'         => __('This controls the description which the user sees during checkout.', 'woocommerce'),
      ),
      'testmode' => array(
        'title'               => __('Test mode', 'woocommerce-epaybg'),
        'type'                => 'checkbox',
        'label'               => __('Work in testing mode, no actual transfer will be done. Demo portal can be accessed on <a href="https://demo.epay.bg" target="_blank">demo.epay.bg</a>', 'woocommerce-epaybg'),
        'default'             => TRUE,
      ),
      'debug' => array(
        'title'               => __('Debug Log', 'woocommerce'),
        'type'                => 'checkbox',
        'label'               => __('Enable logging', 'woocommerce'),
        'default'             => 'yes',
        'description'         => sprintf(__('Log ePay.bg events, such as IPN requests, inside <code>%s</code>', 'woocommerce-epaybg'), wc_get_log_file_path('epaybg'))
      ),
      'client_id' => array(
        'title'               => __('Customer number', 'woocommerce-epaybg'),
        'type'                => 'text',
        'description'         => __('Merchant customer ID number of the ePay.bg account.', 'woocommerce-epaybg'),
        'default'             => '',
        'required'            => TRUE,
      ),
      'secret_key' => array(
        'title'               => __('Secret Key', 'woocommerce-epaybg'),
        'type'                => 'text',
        'description'         => __('Your ePay.bg secret code (64char alphabet string).', 'woocommerce-epaybg'),
        'default'             => '',
        'required'            => TRUE,
      ),
      'epaybg_ipn_notify_url' => array(
        'title'               => __('IPN Callback', 'woocommerce-epaybg'),
        'type'                => 'textarea',
        'default'             => $this->notify_url,
        'custom_attributes'   => array(
          'readonly' => 'readonly',
        ),
        'disabled'            => 'disabled',
        'description'         => __('Value of the field depends of <code>Customer Number</code>, so if it is changed then change also this URL.<br /> Copy and paste this value in your profile under <code>URL for receiving notifications</code>', 'woocommerce-epaybg'),
      ),
      'disable_plugin_ipn_key_check' => array(
        'title'               => __('Disable IPN hash key heck', 'woocommerce-epaybg'),
        'type'                => 'checkbox',
        'default'             => FALSE,
        'description'         => __('Normally you shound not touch this, but if you are in case you have problems with order processing after they are payed and have errors in the log that IPN can not be checked, then disable this protection. Note that this is additional security check for the incomming requests from ePay.bg', 'woocommerce-epaybg'),
      ),
      'invoice_prefix' => array(
        'title'               => __('Order ID prefix', 'woocommerce-epaybg'),
        'type'                => 'decimal',
        'default'             => '00000',
        'description'         => __('Set prefix for order IDs, this is useful in case to separate invoice numbers in ePay.bg.<br />Due to limitation of ePay.bg, this field <strong>accept only numeric values</strong>.', 'woocommerce-epaybg'),
        'custom_attributes'   => array(
          'pattern'   => '^(\d{0,10})$',
          'size'      => 10,
          'maxlength' => 10,
        ),
      ),
      'epaybg_exptime' => array(
        'title'               => __('Transaction expiration', 'woocommerce-epaybg'),
        'type'                => 'select',
        'options'             => array(
          1 => sprintf(_n('%s hour', '%s hours', 1), 1),
          3 => sprintf(_n('%s hour', '%s hours', 3), 3),
          6 => sprintf(_n('%s hour', '%s hours', 6), 6),
          12 => sprintf(_n('%s hour', '%s hours', 12), 12),
          24 => sprintf(_n('%s day', '%s days', 1), 1),
          48 => sprintf(_n('%s day', '%s days', 2), 2),
          72 => sprintf(_n('%s day', '%s days', 3), 3),
        ),
        'default'             => 24,
      ),
      'epaybg_redirect' => array(
        'title'               => __('Redirection method', 'woocommerce-epaybg'),
        'type'                => 'select',
        'options'             => array(
          '' => __('Manual via button', 'woocommerce-epaybg'),
          0 => __('After page load', 'woocommerce-epaybg'),
          3 => sprintf(__('After %s secs', 'woocommerce-epaybg'), 3),
          5 => sprintf(__('After %s secs', 'woocommerce-epaybg'), 5),
          7 => sprintf(__('After %s secs', 'woocommerce-epaybg'), 7),
          9 => sprintf(__('After %s secs', 'woocommerce-epaybg'), 9),
          15 => sprintf(__('After %s secs', 'woocommerce-epaybg'), 15),
        ),
        'default'             => '',
      ),
      'epaybg_redirect_in_new_window' => array(
        'title'               => __('Redirect in new window', 'woocommerce-epaybg'),
        'type'                => 'checkbox',
        'label'               => __('Open ePay.bg payment gateway in new window.', 'woocommerce-epaybg'),
        'default'             => FALSE,
      ),
    );
  }

  /**
   * Admin Panel Options
   * - Options for bits like 'title' and availability on a country-by-country basis
   *
   * @return string
   */
  public function admin_options() {
    if (!$this->is_currency_supported()) {
      ?>
      <div class="woocommerce-message below-h2 error">
        <p>
          <strong><?php _e('Gateway Disabled', 'woocommerce')?></strong>:
          <?php _e('ePay.bg does not support your store currency. Supported currencies are: USD, EUR and BGN.', 'woocommerce-epaybg')?>
        </p>
      </div>
      <?php
    }
    else {
      parent::admin_options();
      if ($this->id !== 'epaybg') {
        echo '<p>' . __('Settings are in limited range because this payment gateway is derivate of main ePay.bg', 'woocommerce-epaybg') . '</p>';
      }
    }
  }

  /**
   * Check if this gateway is enabled and available in the user's country
   *
   * @return bool
   */
  public function is_valid_for_use() {
    return $this->client_id && $this->secret_key && $this->is_currency_supported();
  }

  /**
   * Check if shop current currency is supported by the ePay.bg
   *
   * @return bool
   */
  public function is_currency_supported() {
    return in_array(get_woocommerce_currency(), array( 'BGN', 'USD', 'EUR'));
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

    $order->add_order_note(__('Awaiting payment from ePay.bg', 'woocommerce-epaybg'), FALSE);

    // Remove cart
    WC()->cart->empty_cart();

    // Return thankyou redirect
    return array(
      'result' => 'success',
      'redirect' => $order->get_checkout_payment_url(TRUE),
    );
  }

  /**
   * Output for the order received page.
   *
   * @param int $order_id
   *
   * @return void
   */
  public function receipt_page($order_id) {
    echo '<p>' . __('Thank you - your order is now pending payment.', 'woocommerce-epaybg') . '</p>';
    echo $this->generate_epaybg_form($order_id);
  }

  /**
   * Generate ePay.bg form that is required to send data to the service.
   *
   * @param int $order_id
   *
   * @return string
   */
  public function generate_epaybg_form($order_id) {

    $order = wc_get_order($order_id);

    if (!$order) {
      $this->log(__FUNCTION__ . ' cannot be generated for unknown order: ' . $order_id);
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
    $FORM_PAGE          = $this->epaybg_pay_method;
    $FORM_SUBMIT_URL    = ($this->testmode ? $this->testurl : $this->liveurl);
    $FORM_FORM_ID       = 'woocommerce-epaybg-pay-form-' . $order_id;
    $FORM_LANG          = (get_locale() == 'bg_BG' ? 'bg' : 'en');
    $FORM_URL_OK        = $this->get_return_url($order);
    $FORM_URL_CANCEL    = $order->get_cancel_order_url();

    $form_submit_button = '<button type="submit">' . sprintf(__('Proceed to %s', 'woocommerce-epaybg'), $this->title) . '</button>';

    $output = '
      <form id="' . $FORM_FORM_ID . '" action="' . $FORM_SUBMIT_URL . '" method="post" target="' . ($this->epaybg_redirect_in_new_window ? '_blank' : '') . '">
        <input type="hidden" name="PAGE" value="' . $FORM_PAGE . '" />
        <input type="hidden" name="LANG" value="' . $FORM_LANG . '" />
        <input type="hidden" name="ENCODED" value="' . $FORM_ENCODED . '" />
        <input type="hidden" name="CHECKSUM" value="' . $FORM_CHECKSUM . '" />
        <input type="hidden" name="URL_OK" value="' . esc_url($FORM_URL_OK) . '" />
        <input type="hidden" name="URL_CANCEL" value="' . esc_url($FORM_URL_CANCEL) . '" />';
    if ($this->epaybg_redirect === '') {
      $output .= $form_submit_button;
    }
    else {
      $output .= '
        <p>' . sprintf(__('You will be automatically redirected to ePay.bg in %s', 'woocommerce-epaybg'), '<span id="' . $FORM_FORM_ID . '-timecountdown">' . $this->epaybg_redirect . '</span>') . '</p>
        <script>
          window.ePaybgAutoRedirectCountDown = ' . $this->epaybg_redirect . ';
          window.ePaybgAutoRedirectCountDown_timer = setInterval(function() {
            window.ePaybgAutoRedirectCountDown--;
            if (window.ePaybgAutoRedirectCountDown < 1) {
              document.getElementById("' . $FORM_FORM_ID . '").submit();
              clearInterval(ePaybgAutoRedirectCountDown_timer);
            }
            else {
              document.getElementById("' . $FORM_FORM_ID . '-timecountdown").innerHTML = window.ePaybgAutoRedirectCountDown;
            }
          }, 1000);
        </script>
        <noscript>
          <p>
            ' . sprintf(__('If you are not redirected within next %s seconds, then %s', 'woocommerce-epaybg'), $this->epaybg_redirect, $form_submit_button) . '
          </p>
        </noscript>';
    }
    $output .= '
      </form>';
    return $output;
  }

  /**
   * Check ePay.bg response from the web callback.
   *
   * @return bool
   */
  public function epaybg_ipn_response() {

    if (!$this->disable_plugin_ipn_key_check && (empty($_GET['hash']) || $_GET['hash'] != $this->ipn_key)) {
      $this->log('ERROR: IPN response incorrect hash: ' . (empty($_GET['hash']) ? 'empty' : '"' . $_GET['hash'] . '"'));
      return 0;
    }

    if (empty($_POST['encoded']) || empty($_POST['checksum'])) {
      if (empty($_POST['encoded'])) {
        $this->log('ERROR: IPN response missing encoded post data.');
      }
      if (empty($_POST['encoded'])) {
        $this->log('ERROR: IPN response missing checksum post data.');
      }
      return 0;
    }

    $ENCODED = $_POST['encoded'];
    $CHECKSUM = $_POST['checksum'];
    $hmac = woocommerce_epaybg_hmac('sha1', $ENCODED, $this->secret_key);
    if ($hmac == $CHECKSUM) {
      $data = base64_decode($ENCODED);
      $lines_arr = explode("\n", $data);
      $info_data = '';
      foreach ($lines_arr as $line) {
        if (preg_match("/^INVOICE=(\d+):STATUS=(PAID|DENIED|EXPIRED)(:PAY_TIME=(\d+):STAN=(\d+):BCODE=([0-9a-zA-Z]+))?$/", $line, $regs)) {

          $invoice = $regs[1];
          $status = $regs[2];
          $pay_date = empty($regs[4]) ? NULL : $regs[4];
          $stan = empty($regs[5]) ? NULL : $regs[5];
          $bcode = empty($regs[6]) ? NULL : $regs[6];

          $this->log('IPN command recieved: ' . $line);
          $order_id = substr($invoice, strlen($this->invoice_prefix));
          $order = wc_get_order($order_id);
          if ($order) {
            if ($status !== get_post_meta($order->id, '_epaybg_last_status', TRUE)) {
              add_post_meta($order->id, '_epaybg_last_status', $status, TRUE);
              $order->add_order_note(sprintf(__('ePay.bg set invoice status to: %s', 'woocommerce-epaybg'), $status), FALSE);

              // Process payments.
              if ($status == 'PAID') {
                $pay_date_formatted = substr($pay_date, 0, 4) . '-' . substr($pay_date, 4, 2) . '-' . substr($pay_date, 6, 2) . ' ' . substr($pay_date, 8, 2) . ':' . substr($pay_date, 10, 2) . ':' . substr($pay_date, 12, 2);
                $order->add_order_note(sprintf(__('ePay.bg approved payment on %s with BORICA code: %s, transaction id: %s', 'woocommerce-epaybg'), $pay_date_formatted, $bcode, $stan));

                $order_transaction_id = $stan;
                if ($order->payment_method == 'epaybg_easypay' && $stan == '000000') {
                  $order_transaction_id = get_post_meta($order_id, '_epaybg_easypay_idn', TRUE);
                }
                $order->payment_complete($stan);

                add_post_meta($order->id, '_paid_date', $pay_date_formatted, TRUE);
                return 1;
              }

              // Process DENIED
              elseif ($status == 'DENIED') {
                $order->cancel_order(__('Order is denied by the payment service.', 'woocommerce-epaybg'));
                return 0;
              }

              // Process EXPIRED
              elseif ($status == 'EXPIRED') {
                $order->cancel_order(__('Order is canceled due to expiration.', 'woocommerce-epaybg'));
                return 0;
              }
            }
            else {
              $this->log('IPN IDLE: same status code for order: ' . $order->id);
            }
          }
          else {
            $this->log('IPN recieved for unknown order: ' . $invoice);
            return 0;
          }
        }
      }
    }
    else {
      $this->log('IPN checksum authorization failed.');
    }
    return 0;
  }


  /**
   * ePay.bg logger
   *
   * @param $log_msg
   */
  public function log($log_msg = '') {
    if ($this->debug) {
      $this->_logger->add('epaybg', strtoupper($this->epaybg_pay_method) . ': ' . $log_msg);
    }
  }

}
