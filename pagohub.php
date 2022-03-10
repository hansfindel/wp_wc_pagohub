<?php
/*
 * Plugin Name: PagoHub
 * Plugin URI: https://www.pagohub.cl/
 * Description: Plugin de WooCoommerce para PagoHub
 * Author: Jorge Simoes 
 * Author URI: http://jorgehsy.com
 * Version: 0.0.1
 */

const PAGOHUB_API_URL = "https://portal.alpayments.com/payments";

if (!function_exists('write_log')) {
  function write_log($log)
  {
    if (true === WP_DEBUG) {
      if (is_array($log) || is_object($log)) {
        error_log(print_r($log, true));
      } else {
        error_log($log);
      }
    }
  }
}

add_filter('woocommerce_payment_gateways', 'pagohub_add_gateway_class');
function pagohub_add_gateway_class($gateways)
{
  $gateways[] = 'WC_PagoHub';
  return $gateways;
}

add_action('plugins_loaded', 'pagohub_init_gateway_class');
function pagohub_init_gateway_class()
{

  class WC_PagoHub extends WC_Payment_Gateway
  {

    public function __construct()
    {

      $this->id = 'pagohub';
      $this->icon = '';
      $this->has_fields = true;
      $this->title = __('PagoHub', 'text-domain');
      $this->method_title = __('PagoHub', 'text-domain');
      $this->method_description = __('PagoHub - Portal de pagos', 'text-domain');
      $this->supports = array(
        'products'
      );

      $this->init_form_fields();

      $this->init_settings();
      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->enabled = $this->get_option('enabled');
      $this->test_mode = 'yes' === $this->get_option('test_mode');
      $this->merchant_id = $this->test_mode ? $this->get_option('test_merchant_id') : $this->get_option('merchant_id');

      if (is_admin()) {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      }

      // add_action( 'wp_enqueue_scripts', array( $this, 'pagohub_scripts' ) );

      add_action('woocommerce_api_{webhook name}', array($this, 'webhook'));
    }

    public function init_form_fields()
    {

      $this->form_fields = array(
        'enabled' => array(
          'title'       => __('Activar/Desactivar', 'text-domain'),
          'label'       => __('Habilita PagoHub', 'text-domain'),
          'type'        => 'checkbox',
          'description' => __('Al habilitar este medio de pago, podrás aceptar pagos a través del portal de pagos PagoHub.', 'text-domain'),
          'default'     => 'no',
          'desc_tip'    => true
        ),
        'title' => array(
          'title'       => __('Título', 'text-domain'),
          'type'        => 'text',
          'description' => __('Este será el título que se mostrará a tus clientes en la página de pago.', 'text-domain'),
          'default'     => __('PagoHub', 'text-domain'),
          'desc_tip'    => true,
        ),
        'description' => array(
          'title'       => __('Description', 'text-domain'),
          'type'        => 'textarea',
          'description' => __('Este será la descripción que se mostrará a tus clientes en la página de pago.', 'text-domain'),
          'default'     => __('Paga vie PagoHub', 'text-domain'),
        ),
        'test_mode' => array(
          'title'       => __('Modo de pruebas', 'text-domain'),
          'label'       => __('Activa el modo de pruebas', 'text-domain'),
          'type'        => 'checkbox',
          'description' => __('Recuerda ingresar el Merchant ID de pruebas enviado por PagoHub', 'text-domain'),
          'default'     => 'yes',
          'desc_tip'    => true,
        ),
        'test_merchant_id' => array(
          'title'       => __('Merchant ID de Pruebas', 'text-domain'),
          'type'        => 'password',
        ),
        'merchant_id' => array(
          'title'       => __('Merchant ID Oficial', 'text-domain'),
          'type'        => 'password'
        )
      );
    }


    public function payment_fields()
    {
      // Como PagoHub se encarga de realizar todo el pago desde su plataforma, no es necesario colocar los campos de pago (form)
    }

    public function payment_scripts()
    {
      // Como PagoHub se encarga de realizar todo el pago desde su plataforma, no es necesario colocar los campos de pago (form)	
    }

    public function validate_fields()
    {
      // Como PagoHub se encarga de realizar todo el pago desde su plataforma, no es necesario colocar los campos de pago (form)	
    }

    public function process_payment($order_id)
    {
      $order = new WC_Order($order_id);

      $orderData = array(
        'amount' => $order->get_total(),
        'external_transaction_id' => $order->get_order_number(),
        'external_account_id' => $order->get_user_id(),
        'description' => 'Descripción de la orden',
        'return_url' => $this->get_return_url($order),
      );

      $hash = hash('sha256', implode($orderData), false);
      $signature = base64_encode($hash);

      $args = array(
        'method' => 'POST',
        'timeout' => 45,
        'redirection' => 5,
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => array(
          "content-type" => "application/json",
          "api_version" => "v1",
          "merchant_id" => " $this->merchant_id",
          "ALP_SIGNATURE" => "ALP:$signature",
          "Authorization" => "Basic dGVzdDp0ZXN0"
        ),
        'body' => json_encode($orderData)
      );

      $response = wp_remote_post(PAGOHUB_API_URL, $args);

      if (!is_wp_error($response)) {
        write_log($args);
        write_log($response['body']);
        $body = json_decode($response['body'], true);

        if ($body['status'] == 201) {

          $paymentGatewayUrl = $body['data']['url'];

          return array(
            'result' => 'success',
            'redirect' => $paymentGatewayUrl
          );
        } else {
          wc_add_notice('Please try again.', 'error');
          return;
        }
      } else {
        wc_add_notice('Connection error.', 'error');
        return;
      }
    }

    public function webhook()
    {
      global $woocommerce;
      $order = new WC_Order($_GET['id']);

      $order->payment_complete();
      $order->add_order_note('Hey, your order is paid! Thank you!', true);
      wc_reduce_stock_levels($order->id);
      $woocommerce->cart->empty_cart();

      update_option('webhook_debug', $_GET);
    }
  }
}
