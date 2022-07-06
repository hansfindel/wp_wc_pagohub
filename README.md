# PagoHub - WooCommerce Plugin
Modulo encargado de gestionar y disponer la pasarela de pago de PagoHub a través de API REST.

## Instalación en Wordpress
Para instalar este módulo se debe comprimir en formato zip y subirlo a través del apartado de plugins en el panel de control de Wordpress.

### Configuración
Por defecto, el modulo tiene activo la API key de pruebas. Para ingresar la API key productiva proporcionada por PagoHub, debe ingresar al panel administrativo de Wordpress: WooCommerce -> Configuración -> Pagos -> Activar Plugin PagoHub. Una vez activado podrás agregar el MerchantID proporcionado por PagoHub asociado a tu negocio.

## Desarrollo
El plugin se divide en dos archivos principales: **pagohub.php** y **PagoHubAPI.php**.

**pagohub.php**
Aqui se encuentra toda la configuración necesario para disponibilizar el plugin en woocommerce, incluye la logica de configuración así como de activación dentro del administrador.
**PagoHubAPI.php**
Aquí se encuentra la lógica y seguridad de comunicación con la API de pagohub, se especifica las funciones necesarios para la creación de orden y verificación de pago.
Las funciones disponibles son:
- _createOrderPayment_: crea una orden a través de la API de pagohub y retorna la respuesta. (url de pago)
- _getOrderPayment_: obtiene la orden de pago a través de la APi de pagohub, recibe como parametro la orden de woocommerce que contiene el identificador. 
- _getPaymentStatus_: obtiene el estado del pago de la orden.
- _isPaymentSuccess_: verifica si el pago de la orden fue exitosa.
- _signMessage_(privada): esta función genérica firma el mensaje para agregarlo a las cabeceras requeridas por la APi de pagohub.
 
