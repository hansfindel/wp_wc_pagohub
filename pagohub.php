<?php

if (!defined('ABSPATH')) {
  exit();
} // Exit if accessed directly

/*
 * Plugin Name: PagoHub
 * Plugin URI: https://www.pagohub.cl/
 * Description: Plugin de WooCoommerce para PagoHub
 * Author: Jorge Simoes 
 * Author URI: http://jorgehsy.com
 * Version: 0.0.1
 */

if (!function_exists('write_log')) {
  function write_log($log)
  {
    if (true === WP_DEBUG) {
      if (is_array($log) || is_object($log)) {
        error_log("PagoHub - ". print_r($log, true));
      } else {
        error_log("PagoHub - ". $log);
      }
    }
  }
}

function warning_incompatible() {
  if (!class_exists(WC_PagoHub::class)) {
    return;
  }
  if (!WC_PagoHub::is_valid_for_use()) { ?>
    <div data-dismissible="pagohub_warning" class="updated notice notice-error is-dismissible">
      <p><?php _e('Woocommerce debe estar configurado en pesos chilenos (CLP) para habilitar Pagohub', 'pagohub_wc_plugin'); ?></p>
    </div>
  <?php }
}

add_filter('woocommerce_payment_gateways', 'pagohub_add_gateway_class');
function pagohub_add_gateway_class($gateways)
{
  $gateways[] = 'WC_PagoHub';
  return $gateways;
}

require_once plugin_dir_path( __FILE__ ) . "lib/PagoHubAPI.php";

add_action('plugins_loaded', 'pagohub_init_gateway_class');
function pagohub_init_gateway_class()
{

  class WC_PagoHub extends WC_Payment_Gateway
  {
    const TEST_MERCHANT_ID = "integration";

    public function __construct()
    {

      $this->id = 'pagohub';
      $this->icon = plugin_dir_url(__FILE__) . 'images/logo_PagoHub.webp';
      $this->has_fields = true;
      $this->title = __('PagoHub', 'pagohub_wc_plugin');
      $this->method_title = __('PagoHub', 'pagohub_wc_plugin');
      $this->method_description = __('PagoHub - Portal de pagos', 'pagohub_wc_plugin');
      $this->supports = array(
        'products'
      );

      $this->init_form_fields();

      $this->init_settings();
      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->enabled = $this->get_option('enabled');
      $this->test_mode = 'yes' === $this->get_option('test_mode');
      $this->merchant_id = $this->test_mode ? static::TEST_MERCHANT_ID : $this->get_option('merchant_id');

      if (is_admin()) {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      }

      if (!$this->is_valid_for_use()) {
        add_action('admin_notices', 'warning_incompatible');
        $this->enabled = false;
      }

      // add_action('woocommerce_api_{webhook name}', array($this, 'webhook'));
      add_action('woocommerce_api_return_'.$this->id, array($this, 'callback_return'));
    }

    public function init_form_fields() {

      $this->form_fields = array(
        'enabled' => array(
          'title'       => __('Activar/Desactivar', 'pagohub_wc_plugin'),
          'label'       => __('Habilita PagoHub', 'pagohub_wc_plugin'),
          'type'        => 'checkbox',
          'description' => __('Al habilitar este medio de pago, podrás aceptar pagos a través del portal de pagos PagoHub.', 'pagohub_wc_plugin'),
          'default'     => 'no',
          'desc_tip'    => true
        ),
        'title' => array(
          'title'       => __('Título', 'pagohub_wc_plugin'),
          'type'        => 'text',
          'description' => __('Este será el título que se mostrará a tus clientes en la página de pago.', 'pagohub_wc_plugin'),
          'default'     => __('PagoHub', 'pagohub_wc_plugin'),
          'desc_tip'    => true,
        ),
        'description' => array(
          'title'       => __('Description', 'pagohub_wc_plugin'),
          'type'        => 'textarea',
          'description' => __('Este será la descripción que se mostrará a tus clientes en la página de pago.', 'pagohub_wc_plugin'),
          'default'     => __('Pagar via PagoHub', 'pagohub_wc_plugin'),
        ),
        'test_mode' => array(
          'title'       => __('Modo de pruebas', 'pagohub_wc_plugin'),
          'label'       => __('Activa el modo de pruebas', 'pagohub_wc_plugin'),
          'type'        => 'checkbox',
          'description' => __('Recuerda ingresar el Merchant ID de pruebas enviado por PagoHub', 'pagohub_wc_plugin'),
          'default'     => 'yes',
          'desc_tip'    => true,
        ),
        'merchant_id' => array(
          'title'       => __('Merchant ID Oficial', 'pagohub_wc_plugin'),
          'type'        => 'password'
        )
      );
    }


    public function payment_fields() {
      // Como PagoHub se encarga de realizar todo el pago desde su plataforma, no es necesario colocar los campos de pago (form)
    }

    public function payment_scripts() {
      // Como PagoHub se encarga de realizar todo el pago desde su plataforma, no es necesario colocar los campos de pago (form)	
    }

    public function validate_fields() {
      // Como PagoHub se encarga de realizar todo el pago desde su plataforma, no es necesario colocar los campos de pago (form)	
    }

    public function process_payment($order_id) {
      write_log("Inicia proceso de pago");

      $order = new WC_Order($order_id);
      $api = new PahoHubAPI($this->merchant_id);
      $response = $api->createOrderPayment($order);
      
      
      if (!is_wp_error($response)) {
        if ($response['status'] === 201) {
          write_log($response['data']['url']);

          $paymentGatewayUrl = $response['data']['url'];
          $pagoHubIdentifier = $response['data']['identifier'];
          $order->update_meta_data('pago_hub_identifier', $pagoHubIdentifier);
          $order->update_status('on-hold', __('Pago en proceso', 'pagohub_wc_plugin'));

          return array(
            'result' => 'success',
            'redirect' => $paymentGatewayUrl
          );
        } else {
          wc_add_notice('Ocurrió un error, por favor intente de nuevo más tarde.', 'error');
          return;
        }
      } else {
        write_log("Error al crear el pago". is_wp_error($response));
        wc_add_notice('Ops! tenemos un problema de conexión, por favor intente de nuevo más tarde', 'error');
        return;
      }
    }

    public function callback_return(){
      write_log("Retorno de pago");
      $orderId = $_GET['order_id'];
      $order = wc_get_order($orderId);
      
      $api = new PahoHubAPI($this->merchant_id);
      $paymentSuccess = $api->isPaymentSuccess($order);
      $returnUrl = self::processOrder($order, $paymentSuccess);
    
      wp_redirect($returnUrl);
    }

    public function processOrder(WC_Order $order, bool $paymentSuccess = false) : string{
      if ($paymentSuccess){

        $order->add_order_note(__("Pagado con PagoHub", 'woocommerce'));
        $order->payment_complete();
        wc_add_notice('Pago recibido con éxito', 'success');
        $returnUrl = $this->get_return_url($order);

      } else {

        $order->add_order_note(__("Rechazado por PagoHub", 'woocommerce'));
        $order->update_status('failed');
        wc_add_notice('No se pudo verificar el pago, por favor intente de nuevo.', 'error');
        $returnUrl = $order->get_cancel_order_url();

      }
      
      return $returnUrl;
    }

    public function webhook() {
      // TODO: se puede implementar el webhook para recibir las notificaciones de PagoHub
    }

    public static function is_valid_for_use() {
      return in_array(get_woocommerce_currency(), ['CLP']);
    }

  }
}
