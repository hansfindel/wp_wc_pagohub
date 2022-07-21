<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase personalizada para el uso de la API de PagoHub
 * Version: 0.0.1
 * Require: PHP 5.6+
 */

class PahoHubAPI {
    const PAGOHUB_API_BASE_URL = "https://portal.alpayments.com/payments";
    const SUCCESS_CODE = 1000;
    
    private $merchantId;
    private $authHeader;
    private $secret;
    /**
     * @merchantId string
     * return PagoHubAPI::class
     */
    function __construct(string $merchantId, string $authHeader, string $secret)
    {
        $this->merchantId = $merchantId;
        $this->authHeader = $authHeader;
        $this->secret = $secret ?? $merchantId;

        $this->headers = array(
          'Authorization' => $this->authHeader,
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'merchant_id' => $merchantId,
        );

        return $this;
    }

    /**
     * Crea la orden en la api y retorna el objecto con la url de pago y el identificador
     * @order WC_Order 
     * return array
     */
    public function createOrderPayment(WC_Order $order) : array{
      try {
        // http://dev.wordpress.cl/?wc-api=return_pagohub&order_id=27
        $returnUrl = add_query_arg('wc-api', "return_pagohub&order_id=".$order->get_id(), home_url('/'));
        $order->update_meta_data('check_payment_url', $returnUrl);
        $order->save_meta_data();
        $message = $order->get_total().":".$order->get_order_number();
        $signature = $this->signMessage($message, $this->secret);
        $body = array(
          'amount' => $order->get_total(),
          'external_transaction_id' => $order->get_order_number(),
          'external_account_id' => $order->get_billing_email(),
          'description' => 'Orden de prueba',
          'return_url' => $returnUrl,
        );
        
        $this->headers['ALP_SIGNATURE'] = $signature;
  
        $args = [
          'body'        => json_encode($body),
          'timeout'     => '5',
          'redirection' => '5',
          'httpversion' => '1.0',
          'blocking'    => true,
          'headers'     => $this->headers,
          'cookies'     => array(),
        ];
  
        $response = wp_remote_post(self::PAGOHUB_API_BASE_URL, $args);
        $parseResponse = wp_remote_retrieve_body($response);
  
        return json_decode($parseResponse, true);
      } catch (\Throwable $th) {
        return $th;
      }
    }

    /**
     * Obtiene el el registro de pago de la orden
     * @order WC_Order 
     * return json
     */
    public function getOrderPayment(WC_Order $order) : array{
      $identifier = $order->get_meta('pago_hub_identifier');
      $message = $order->get_total().":".$order->get_order_number();
      $currentDate = date('YmdHi');

      //$signature = $this->signMessage($message, $this->merchantId);
      $signature = $this->signMessage($currentDate, $this->secret);

      $this->headers['ALP_SIGNATURE'] = $signature;
      $this->headers['ALP_DATE'] = $currentDate;

      $args = [
        'body'        => [],
        'timeout'     => '5',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking'    => true,
        'headers'     => $this->headers,
        'cookies'     => array(),
      ];

      $response = wp_remote_get(self::PAGOHUB_API_BASE_URL . "/$identifier", $args);
      $parseResponse = wp_remote_retrieve_body($response);

      write_log("Obteniendo estado de pago: ".$identifier);

      return json_decode($parseResponse, true);
    }

    /**
     * Retorna el estado del pago filtrando el registro de pago de la api
     * @order WC_Order 
     * return array
     */
    public function getPaymentStatus(WC_Order $order) : array{
      $result = [
        'response_code' => '',
        'message' => '',
        'amount' => null,
      ];

      try {
        $orderPayment = $this->getOrderPayment($order);

        if ($orderPayment['status'] === 200){
          write_log("respuesta PAGOHUB", $orderPayment);
          $result = [
            // TODO destructurar arreglo para manejar indices por defecto
            'response_code' => $orderPayment['data']['payment']['result']['status']['response_code'],
            'message' => $orderPayment['data']['payment']['result']['status']['message'],
            'amount' => $orderPayment['data']['amount']
          ];

        }
      } catch (\Throwable $th) {
        write_log("Error al consultar el pago: ".$th->getMessage());
        $result = [
          'response_code' => '-1',
          'message' => 'Error'
        ];
      }

      return $result;
    }

    /**
     * Retorna true o false si la orden fue pagada exitosamente, comprobando el monto pagado y el total de la orden
     * @order WC_Order 
     * return bool
     */
    public function isPaymentSuccess(WC_Order $order) : bool{
      $paymentStatus = $this->getPaymentStatus($order);

      // write_log("amount: ".$paymentStatus['amount']." - ".gettype($paymentStatus['amount']));
      // write_log("total: ".$order->get_total()." - ".gettype($order->get_total()));
      // write_log($paymentStatus['amount'] === $order->get_total() ? 'Y' : 'N');

      // write_log("code: ".$paymentStatus['response_code']." - ". gettype($paymentStatus['response_code']));
      // write_log("code: ".self::SUCCESS_CODE." - ". gettype(self::SUCCESS_CODE));
      // write_log($paymentStatus['response_code'] === self::SUCCESS_CODE ? 'Y' : 'N');

      $result = $paymentStatus['response_code'] == self::SUCCESS_CODE && $paymentStatus['amount'] == $order->get_total();
      
      $logMessage = "order:". $order->get_order_number()." - total:".$order->get_total()." - status:".$paymentStatus['response_code'];
      $result 
        ? write_log("Pago EXITOSO: ".$logMessage)
        : write_log("Pago FALLIDO: ".$logMessage);

      if ($result){
        $order->payment_complete();
      }

      return $result;
    }

    /**
     * Retorna la cadena firmada para enviar a la api
     * @message string
     * return string
     */
    private function signMessage(string $message, string $secret) : string{
      write_log("mensaje a firmar: ".$message);
      write_log("secreto: ".$secret);
      $hash = hash_hmac('sha256', $message, $secret, true);
      $signature = base64_encode($hash);
      write_log("Signature: ".$signature);
      
      return "ALP:$signature";
    }
}

