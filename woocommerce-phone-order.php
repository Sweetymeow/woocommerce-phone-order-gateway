<?php
/*
Plugin Name: Phone Order Gateway for WooCommerce
Plugin URI:
Description: This plugin adds Phone Order gateway to the WooCommerce plugin.
Version: 1.0
Author: Yonatan Ganot
Author URI: http://www.scolpy.net
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

add_action('plugins_loaded', 'woocommerce_phone_order_init', 0);
function woocommerce_phone_order_init() {
	if(!class_exists('WC_Payment_Gateway')) return;

	class WC_Gateway_Phone_Order extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'woocommerce_phone_order';
			$this->icon               = apply_filters( 'woocommerce_phone_order_icon', '' );
			$this->method_title       = __( 'Phone Order', 'woocommerce_phone_order' );
			$this->method_description = __( 'Have your customers pay over the phone', 'woocommerce_phone_order' );
			$this->has_fields         = false;

			// Load the settings
			$this->init_form_fields();
			$this->init_settings();

			// Get settings
			$this->title              = $this->get_option( 'title' );
			$this->description        = $this->get_option( 'description' );
			$this->instructions       = $this->get_option( 'instructions' );
			$this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_phone_order', array( $this, 'thankyou_page' ) );
		}

		/**
		 * Initialise Gateway Settings Form Fields
		 */
		public function init_form_fields() {
			global $woocommerce;

			$shipping_methods = array();

			if ( is_admin() )
				foreach ( WC()->shipping->load_shipping_methods() as $method ) {
					$shipping_methods[ $method->id ] = $method->get_title();
				}

			$this->form_fields = array(
				'enabled' => array(
					'title'       => __( 'Enable Phone Order', 'woocommerce_phone_order' ),
					'label'       => __( 'Enable the Phone Order gateway in WooCommerce', 'woocommerce_phone_order' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => __( 'Title', 'woocommerce_phone_order' ),
					'type'        => 'text',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce_phone_order' ),
					'default'     => __( 'Phone Order', 'woocommerce_phone_order' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description', 'woocommerce_phone_order' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your website.', 'woocommerce_phone_order' ),
					'default'     => __( 'Pay the order over the phone', 'woocommerce_phone_order' ),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Instructions', 'woocommerce_phone_order' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page.', 'woocommerce_phone_order' ),
					'default'     => __( 'We have received the order and we will contact you shortly', 'woocommerce_phone_order' ),
					'desc_tip'    => true,
				),
				'enable_for_methods' => array(
					'title'             => __( 'Enable for shipping methods', 'woocommerce_phone_order' ),
					'type'              => 'multiselect',
					'class'             => 'chosen_select',
					'css'               => 'width: 450px;',
					'default'           => '',
					'description'       => __( 'If phone order is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce_phone_order' ),
					'options'           => $shipping_methods,
					'desc_tip'          => true,
					'custom_attributes' => array(
						'data-placeholder' => __( 'Select shipping methods', 'woocommerce_phone_order' )
					)
				)
		   );
		}

		/**
		 * Check If The Gateway Is Available For Use
		 *
		 * @return bool
		 */
		public function is_available() {

			if ( ! empty( $this->enable_for_methods ) ) {

				// Only apply if all packages are being shipped via local pickup
				$chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

				if ( isset( $chosen_shipping_methods_session ) ) {
					$chosen_shipping_methods = array_unique( $chosen_shipping_methods_session );
				} else {
					$chosen_shipping_methods = array();
				}

				$check_method = false;

				if ( is_page( wc_get_page_id( 'checkout' ) ) && ! empty( $wp->query_vars['order-pay'] ) ) {

					$order_id = absint( $wp->query_vars['order-pay'] );
					$order    = new WC_Order( $order_id );

					if ( $order->shipping_method )
						$check_method = $order->shipping_method;

				} elseif ( empty( $chosen_shipping_methods ) || sizeof( $chosen_shipping_methods ) > 1 ) {
					$check_method = false;
				} elseif ( sizeof( $chosen_shipping_methods ) == 1 ) {
					$check_method = $chosen_shipping_methods[0];
				}

				if ( ! $check_method )
					return false;

				$found = false;

				foreach ( $this->enable_for_methods as $method_id ) {
					if ( strpos( $check_method, $method_id ) === 0 ) {
						$found = true;
						break;
					}
				}

				if ( ! $found )
					return false;
			}

			return parent::is_available();
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {

			$order = new WC_Order( $order_id );

			// Mark as processing (payment will be taken over the phone)
			$order->update_status( 'processing', __( 'Payment to be made over the phone.', 'woocommerce_phone_order' ) );

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			WC()->cart->empty_cart();

			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $this->get_return_url( $order )
			);
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions )
				echo wpautop( wptexturize( $this->instructions ) );
		}
	}
	/**
	* Add the Gateway to WooCommerce
	**/
	function woocommerce_add_phone_order_gateway($methods) {
		$methods[] = 'WC_Gateway_Phone_Order';
		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_phone_order_gateway' );

	/**
	* Load the localisation file
	**/
	function load_localisation () {
		load_plugin_textdomain( 'woocommerce_phone_order', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}

	add_action( 'init', 'load_localisation' );
}
