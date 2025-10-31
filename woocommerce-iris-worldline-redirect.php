<?php
/**
 * Plugin Name: WooCommerce IRIS (Worldline) – Redirection
 * Description: Ενσωμάτωση Hosted Payment Page για Worldline/Cardlink (shophandlermpi) στο WooCommerce. Υπολογίζει το digest (SHA256 + Base64), αποστέλλει το αίτημα, επαληθεύει τις επιστροφές (confirm/cancel) και μπορεί να προεπιλέγει το IRIS ως μέθοδο πληρωμής. Κατασκευάστηκε από τον John Pastras (webally.gr).
 * Version: 1.0.0
 * Author: John Pastras
 * Author URI: https://webally.gr
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function () {

  if ( ! class_exists('WC_Payment_Gateway') ) return;

  class WC_Gateway_IRIS_Worldline_Redirect extends WC_Payment_Gateway {

    public function __construct() {
      $this->id                 = 'iris_worldline_redirect';
      $this->method_title       = 'IRIS (Worldline) – Redirection';
      $this->method_description = 'Πληρωμή μέσω Worldline/Cardlink Hosted Payment Page';
      $this->has_fields         = false;

      $this->init_form_fields();
      $this->init_settings();

      // settings
      $this->enabled       = $this->get_option('enabled', 'no');
      $this->title         = $this->get_option('title', 'Πληρωμή μέσω IRIS');
      $this->description   = $this->get_option('description', 'Θα μεταφερθείτε στη σελίδα πληρωμών της Worldline. Στην επιτυχή πληρωμή θα επιστρέψετε στο κατάστημά μας.');
      $this->testmode      = $this->get_option('testmode') === 'yes';
      $this->force_iris    = $this->get_option('force_iris') === 'yes';
      $this->debug         = $this->get_option('debug') === 'yes';

      // endpoints & creds
      $this->live_endpoint = trim($this->get_option('live_endpoint'));
      $this->live_mid      = trim($this->get_option('live_mid'));
      $this->live_secret   = $this->get_option('live_secret');

      $this->test_endpoint = trim($this->get_option('test_endpoint'));
      $this->test_mid      = trim($this->get_option('test_mid'));
      $this->test_secret   = $this->get_option('test_secret');

      // urls
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
      add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
      add_action('woocommerce_api_iris_worldline_confirm', [$this, 'handle_confirm']);
      add_action('woocommerce_api_iris_worldline_cancel',  [$this, 'handle_cancel']);
    }

    public function init_form_fields() {
      $confirm_url = home_url('/?wc-api=iris_worldline_confirm');
      $cancel_url  = home_url('/?wc-api=iris_worldline_cancel');

      $this->form_fields = [
        'enabled' => [
          'title'   => 'Enable/Disable',
          'type'    => 'checkbox',
          'label'   => 'Enable IRIS (Worldline) – Redirection',
          'default' => 'no'
        ],
        'title' => [
          'title'   => 'Title',
          'type'    => 'text',
          'default' => 'Πληρωμή μέσω IRIS',
        ],
        'description' => [
          'title'   => 'Description',
          'type'    => 'textarea',
          'default' => 'Θα μεταφερθείτε στη σελίδα πληρωμών της Worldline.',
        ],
        'testmode' => [
          'title'   => 'Test Environment',
          'label'   => 'Enable sandbox (eurocommerce-test)',
          'type'    => 'checkbox',
          'default' => 'yes',
          'description' => 'Χρησιμοποίησε το sandbox endpoint και δοκιμαστικό MID/Secret.'
        ],
        'force_iris' => [
          'title'   => 'Preselect IRIS',
          'label'   => 'Send payMethod=IRIS',
          'type'    => 'checkbox',
          'default' => 'yes',
        ],
        'section_live' => [
          'title'   => 'Production (Live)',
          'type'    => 'title',
        ],
        'live_endpoint' => [
          'title'       => 'Live Endpoint URL',
          'type'        => 'text',
          'placeholder' => 'https://eurocommerce.cardlink.gr/vpos/shophandlermpi',
          'description' => 'Το production URL του shophandlermpi.',
        ],
        'live_mid' => [
          'title'       => 'Live MID',
          'type'        => 'text',
          'placeholder' => 'π.χ. 0024554784',
        ],
        'live_secret' => [
          'title'       => 'Live Shared Secret',
          'type'        => 'password',
          'description' => 'Χρησιμοποιείται για το digest (SHA256/Base64).',
        ],
        'section_test' => [
          'title'   => 'Sandbox (Test)',
          'type'    => 'title',
        ],
        'test_endpoint' => [
          'title'       => 'Test Endpoint URL',
          'type'        => 'text',
          'default'     => 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi',
        ],
        'test_mid' => [
          'title'       => 'Test MID',
          'type'        => 'text',
          'placeholder' => 'test MID',
        ],
        'test_secret' => [
          'title'       => 'Test Shared Secret',
          'type'        => 'password',
        ],
        'debug' => [
          'title'   => 'Debug log',
          'type'    => 'checkbox',
          'label'   => 'Enable logging (WooCommerce → Status → Logs)',
          'default' => 'yes',
        ],
        'info_urls' => [
          'title'       => 'Callback URLs',
          'type'        => 'title',
          'description' => 'Success/Fail επιστροφές από Worldline:<br><code>Confirm URL:</code> '.$confirm_url.'<br><code>Cancel URL:</code> '.$cancel_url,
        ],
      ];
    }

    /** Current creds & endpoint */
    protected function creds() {
      if ($this->testmode) {
        return [
          'endpoint' => $this->test_endpoint,
          'mid'      => $this->test_mid,
          'secret'   => $this->test_secret,
        ];
      }
      return [
        'endpoint' => $this->live_endpoint,
        'mid'      => $this->live_mid,
        'secret'   => $this->live_secret,
      ];
    }

    public function admin_options() {
      echo '<h2>IRIS (Worldline) – Redirection</h2>';
      echo '<p>Hosted Payment Page “shophandlermpi”. Υπολογισμός digest, επιστροφή confirm/cancel.</p>';
      echo '<table class="form-table">';
      $this->generate_settings_html();
      echo '</table>';
    }

    public function process_payment( $order_id ) {
      $order = wc_get_order($order_id);
      // redirect στο "receipt" για να κάνουμε auto-post
      return [
        'result'   => 'success',
        'redirect' => $order->get_checkout_payment_url( true ),
      ];
    }

    /** Receipt page → φτιάχνουμε τα fields, digest, και auto-submit στο Worldline */
    public function receipt_page( $order_id ) {
      $order = wc_get_order($order_id);
      $c = $this->creds();

      if (empty($c['endpoint']) || empty($c['mid']) || empty($c['secret'])) {
        wc_print_notice('Λείπουν ρυθμίσεις Worldline (endpoint/MID/secret).', 'error');
        return;
      }

      $fields = $this->build_request_fields($order, $c['mid']);
      $digest = $this->calc_request_digest($fields, $c['secret']);
      $fields['digest'] = $digest;

      $this->log('Request fields (order '.$order_id.'): '. print_r($fields, true));

      echo '<p>Μεταφορά στη Worldline για πληρωμή…</p>';
      echo '<form id="wlform" action="'.esc_url($c['endpoint']).'" method="POST" accept-charset="UTF-8">';
      foreach ($fields as $k=>$v) {
        echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
      }
      echo '</form>';
      echo '<script>document.getElementById("wlform").submit();</script>';
    }

    /** Χτίζουμε ΟΛΑ τα request fields στη ΣΕΙΡΑ του manual για τον digest */
    protected function build_request_fields(WC_Order $order, $mid) {
      $locale = get_locale();
      $lang = (strpos($locale, 'el_') === 0) ? 'el' : 'en';

      $order_id  = $order->get_id();
      $amount    = number_format((float)$order->get_total(), 2, '.', '');
      $currency  = $order->get_currency() ?: 'EUR';
      $email     = $order->get_billing_email();
      $phone     = $order->get_billing_phone();

      $bill_country = $order->get_billing_country();
      $bill_zip     = $order->get_billing_postcode();
      $bill_city    = $order->get_billing_city();
      $bill_addr    = trim($order->get_billing_address_1().' '.$order->get_billing_address_2());

      $ship_country = $order->get_shipping_country();
      $ship_state   = $order->get_shipping_state();
      $ship_zip     = $order->get_shipping_postcode();
      $ship_city    = $order->get_shipping_city();
      $ship_addr    = trim($order->get_shipping_address_1().' '.$order->get_shipping_address_2());

      // Confirm/Cancel URLs
      $confirm_url  = home_url('/?wc-api=iris_worldline_confirm');
      $cancel_url   = home_url('/?wc-api=iris_worldline_cancel');

      // Απλή περιγραφή
      $orderDesc = 'Order #'.$order_id;

      // ΣΥΓΚΕΚΡΙΜΕΝΗ ΣΕΙΡΑ ΟΠΩΣ ΤΟ TABLE (1..45)
      $fields = [
        'version'            => '2',
        'mid'                => $mid,
        'lang'               => $lang,
        'deviceCategory'     => '0',
        'orderid'            => 'O'.$order_id,  // μόνο γράμματα/αριθμοί, χωρίς κενά
        'orderDesc'          => $orderDesc,
        'orderAmount'        => $amount,
        'currency'           => $currency,
        'payerEmail'         => $email,
        'payerPhone'         => $phone,
        'billCountry'        => $bill_country,
        'billState'          => '', // για Ελλάδα το manual λέει να αποφεύγεται
        'billZip'            => $bill_zip,
        'billCity'           => $bill_city,
        'billAddress'        => $bill_addr,
        'weight'             => '',
        'dimensions'         => '',
        'shipCountry'        => $ship_country,
        'shipState'          => $ship_state,
        'shipZip'            => $ship_zip,
        'shipCity'           => $ship_city,
        'shipAddress'        => $ship_addr,
        'addFraudScore'      => '',
        'maxPayRetries'      => '',
        'reject3dsU'         => '',
        'payMethod'          => $this->force_iris ? 'IRIS' : '',
        'trType'             => '', // 1=payment, 2=preauth (για κάρτες) – αφήνουμε κενό
        'extInstallmentoffset'=> '',
        'extInstallmentperiod'=> '',
        'extRecurringfrequency'=> '',
        'extRecurringenddate'=> '',
        'blockScore'         => '',
        'cssUrl'             => '',
        'confirmUrl'         => $confirm_url,
        'cancelUrl'          => $cancel_url,
        'var1'               => '',
        'var2'               => '',
        'var3'               => '',
        'var4'               => '',
        'var5'               => '',
        'var6'               => '',
        'var7'               => '',
        'var8'               => '',
        'var9'               => '',
        // 'digest' στο τέλος ΜΟΝΟ κατά το submit
      ];

      // Καθάρισε πεδία από nulls
      foreach ($fields as $k=>$v) {
        if ($v === null) $fields[$k] = '';
      }
      return $fields;
    }

    /** Υπολογισμός digest για REQUEST: base64( sha256( UTF8( concat(all_fields_in_order) + secret ) ) ) */
    protected function calc_request_digest(array $fields, string $secret): string {
      // σειρά ακριβώς όπως στο build_request_fields
      $order_keys = [
        'version','mid','lang','deviceCategory','orderid','orderDesc','orderAmount','currency','payerEmail','payerPhone',
        'billCountry','billState','billZip','billCity','billAddress','weight','dimensions',
        'shipCountry','shipState','shipZip','shipCity','shipAddress','addFraudScore','maxPayRetries','reject3dsU','payMethod',
        'trType','extInstallmentoffset','extInstallmentperiod','extRecurringfrequency','extRecurringenddate','blockScore','cssUrl',
        'confirmUrl','cancelUrl','var1','var2','var3','var4','var5','var6','var7','var8','var9'
      ];
      $concat = '';
      foreach ($order_keys as $k) {
        $concat .= (string)($fields[$k] ?? '');
      }
      $concat .= (string)$secret;
      return base64_encode(hash('sha256', $concat, true));
    }

    /** Digest για RESPONSE (confirm/cancel) */
    protected function calc_response_digest(array $fields, string $secret): string {
      // Πίνακας Table 3 – Response fields order
      $order_keys = [
        'version','mid','orderid','status','orderAmount','currency','paymentTotal','message','riskScore','payMethod','txId','paymentRef','extData'
      ];
      $concat = '';
      foreach ($order_keys as $k) {
        $concat .= (string)($fields[$k] ?? '');
      }
      $concat .= (string)$secret;
      return base64_encode(hash('sha256', $concat, true));
    }

    /** Confirm handler (success) */
    public function handle_confirm() {
      $this->handle_return('confirm');
    }

    /** Cancel handler (fail/cancel) */
    public function handle_cancel() {
      $this->handle_return('cancel');
    }

    /** Κοινός χειριστής POST επιστροφής από Worldline */
    protected function handle_return($type) {
      $c = $this->creds();
      $post = wp_unslash($_POST);
      $this->log(strtoupper($type).' POST: ' . print_r($post, true));

      $orderid = isset($post['orderid']) ? $post['orderid'] : '';
      $order_id = (int) preg_replace('/\D+/', '', $orderid); // από "O1234" → 1234

      $order = wc_get_order($order_id);
      if ( ! $order ) {
        status_header(400);
        echo 'Order not found';
        exit;
      }

      // Επαλήθευση digest
      $received = (string)($post['digest'] ?? '');
      $calc     = $this->calc_response_digest($post, (string)$c['secret']);
      if ( empty($received) || ! hash_equals($received, $calc) ) {
        $order->add_order_note('Worldline return: INVALID DIGEST');
        $this->log('INVALID DIGEST for order '.$order_id.'. calc='.$calc.' received='.$received);
        // Δεν ολοκληρώνουμε την παραγγελία
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
      }

      // Status mapping
      $status = strtoupper( (string) ($post['status'] ?? '') );
      if ( in_array($status, ['AUTHORIZED','CAPTURED'], true) ) {
        if ( $order->get_status() !== 'completed' && $order->get_status() !== 'processing' ) {
          $order->payment_complete( (string)($post['paymentRef'] ?? '') );
          $order->add_order_note('Worldline: '.$status.' (txId '.($post['txId'] ?? '').')' );
        }
        wp_safe_redirect( $this->get_return_url($order) );
        exit;
      } else {
        $order->update_status('failed', 'Worldline: '.$status.' '.($post['message'] ?? '') );
        wc_add_notice(__('Η πληρωμή δεν ολοκληρώθηκε: ').$status, 'error');
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
      }
    }

    protected function log($msg) {
      if ( ! $this->debug ) return;
      $logger = wc_get_logger();
      $logger->info($msg, ['source' => 'iris_worldline_redirect']);
    }
  }

  add_filter('woocommerce_payment_gateways', function ($methods) {
    $methods[] = 'WC_Gateway_IRIS_Worldline_Redirect';
    return $methods;
  });

});
