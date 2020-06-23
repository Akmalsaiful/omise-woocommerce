<?php
defined( 'ABSPATH' ) or die( 'No direct script access allowed.' );

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

if ( class_exists( 'Omise_Payment' ) ) {
	return;
}

abstract class Omise_Payment extends WC_Payment_Gateway {
	/** Omise charge id post meta key. */
	const CHARGE_ID = 'omise_charge_id';

	/**
	 * @var string Omise charge statuses
	 */
	const STATUS_SUCCESSFUL = 'successful';
	const STATUS_FAILED     = 'failed';
	const STATUS_PENDING    = 'pending';

	/**
	 * @see woocommerce/includes/abstracts/abstract-wc-settings-api.php
	 *
	 * @var string
	 */
	public $id = 'omise';

	/**
	 * @since 3.4
	 *
	 * @var   \Omise_Backend
	 */
	protected $backend;

	/**
	 * @see omise/includes/class-omise-setting.php
	 *
	 * @var Omise_Setting
	 */
	protected $omise_settings;

	/**
	 * Payment setting values.
	 *
	 * @var array
	 */
	public $payment_settings = array();

	/**
	 * A list of countries the payment method can be operated with.
	 *
	 * @var array
	 */
	public $restricted_countries = array();

	/**
	 * @var array
	 */
	private $currency_subunits = array(
		'EUR' => 100,
		'GBP' => 100,
		'JPY' => 1,
		'SGD' => 100,
		'THB' => 100,
		'USD' => 100
	);

	/**
	 * @var string
	 */
	protected $order_id;

	/**
	 * @var Omise_Order|null
	 */
	protected $order;

	/**
	 * @var OmiseCharge|null
	 */
	protected $charge;

	public function __construct() {
		$this->omise_settings   = Omise()->settings();
		$this->payment_settings = $this->omise_settings->get_settings();

		add_action( 'wp_enqueue_scripts', array( $this, 'omise_checkout_assets' ) );
	}

	/**
	 * Register all required javascripts
	 */
	public function omise_checkout_assets() {
		if ( is_checkout() ) {
			wp_enqueue_style( 'omise', plugins_url( '../../assets/css/omise-css.css', __FILE__ ), array(), OMISE_WOOCOMMERCE_PLUGIN_VERSION );

			do_action( 'omise_checkout_assets' );
		}
	}

	/**
	 * @param  string|WC_Order $order_id
	 *
	 * @return WC_Order|null
	 */
	public function load_order( $order_id ) {
		$this->order_id = $order_id;
		$this->order    = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );

		if ( ! $this->order ) {
			$this->order = null;
		}

		return $this->order;
	}

	/**
	 * @return Omise_Order|null
	 */
	public function order() {
		return $this->order;
	}

	/**
	 * Whether Sandbox (test) mode is enabled or not.
	 *
	 * @return bool
	 */
	public function is_test() {
		return $this->omise_settings->is_test();
	}

	/**
	 * Return Omise public key.
	 *
	 * @return string
	 */
	protected function public_key() {
		return $this->omise_settings->public_key();
	}

	/**
	 * Return Omise secret key.
	 *
	 * @return string
	 */
	protected function secret_key() {
		return $this->omise_settings->secret_key();
	}

	/**
	 * @param  string $currency
	 *
	 * @return bool
	 */
	protected function is_currency_support( $currency ) {
		if ( isset( $this->currency_subunits[ strtoupper( $currency ) ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @param  string $country_code
	 *
	 * @return bool
	 */
	public function is_country_support( $country_code ) {
		array_map( 'strtoupper', $this->restricted_countries );

		if ( in_array( strtoupper( $country_code ), $this->restricted_countries ) ) {
			return true;
		}

		return false;
	}

	/**
	 * @since  3.4
	 *
	 * @see    WC_Payment_Gateway::process_payment( $order_id )
	 * @see    woocommerce/includes/abstracts/abstract-wc-payment-gateway.php
	 *
	 * @param  int $order_id
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		if ( ! $this->load_order( $order_id ) ) {
			return $this->invalid_order( $order_id );
		}

		$order_note = sprintf( __( 'Omise: Processing a payment with %s', 'omise' ), $this->method_title );
		$this->order->add_order_note( $order_note );

		try {
			$charge_params = $this->build_charge_params();
			$this->charge  = OmiseCharge::create( $charge_params );
		} catch ( Exception $e ) {
			return $this->payment_failed( $e->getMessage() );
		}

		$order_note = sprintf( __( 'Omise: Charge (ID: %s) has been created', 'omise' ), $this->charge['id'] );
		$this->order->add_order_note( $order_note );
		$this->set_order_transaction_id( $this->charge['id'] );

		return $this->result( $order_id, $this->order, $this->charge );
	}

	/**
	 * @since  4.0
	 *
	 * @return array
	 */
	public function build_charge_params() {
		return array_merge( $this->charge_params_default(), array() );
	}

	/**
	 * @since  3.4
	 *
	 * @see    Omise_Payment::process_payment( $order_id )
	 *
	 * @param  int         $order_id
	 * @param  WC_Order    $order
	 * @param  OmiseCharge $charge
	 *
	 * @return array|Exception
	 */
	abstract public function result( $order_id, $order, $charge );

	/**
	 * Process refund.
	 *
	 * @param  int    $order_id
	 * @param  float  $amount
	 * @param  string $reason
	 *
	 * @return boolean True|False based on success, or a WP_Error object.
	 *
	 * @see    WC_Payment_Gateway::process_refund( $order_id, $amount = null, $reason = '' )
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		if ( ! $order = wc_get_order( $order_id ) ) {
			$message = __(
				'Refund failed. Cannot retrieve an order with the given ID: %s. Please try again or do a manual refund.',
				'omise'
			);

			return new WP_Error( 'error', sprintf( wp_kses( $message, array( 'br' => array() ) ), $order_id ) );
		}

		try {
			$charge = OmiseCharge::retrieve( $order->get_transaction_id() );
			$refund = $charge->refunds()->create( array(
				'amount'   => Omise_Money::to_subunit( $amount, $order->get_order_currency() ),
				'metadata' => array( 'reason' => sanitize_text_field( $reason ) )
			) );

			if ( $refund['voided'] ) {
				$message = sprintf(
					wp_kses(
						__( 'Omise: Voided an amount of %1$s %2$s.<br/>Refund id is %3$s', 'omise' ),
						array( 'br' => array() )
					),
					$amount,
					$order->get_order_currency(),
					$refund['id']
				);
			} else {
				$message = sprintf(
					wp_kses(
						__( 'Omise: Refunded an amount of %1$s %2$s.<br/>Refund id is %3$s', 'omise' ),
						array( 'br' => array() )
					),
					$amount,
					$order->get_order_currency(),
					$refund['id']
				);
			}

			$order->add_order_note( $message );
			return true;
		} catch (Exception $e) {
			return new WP_Error( 'error', __( 'Refund failed.' ) . ' ' . $e->getMessage() );
		}
	}

	/**	
	 * Retrieve a charge by a given charge id (that attach to an order).
	 * Find some diff, then merge it back to WooCommerce system.
	 *
	 * @param  WC_Order $order WooCommerce's order object
	 *
	 * @return void
	 *
	 * @see    WC_Meta_Box_Order_Actions::save( $post_id, $post )
	 * @see    woocommerce/includes/admin/meta-boxes/class-wc-meta-box-order-actions.php
	 */
	public function sync_payment( $order ) {
		$this->load_order( $order );

		try {
			$charge = OmiseCharge::retrieve( $this->get_charge_id_from_order() );

			if ( ! $this->order()->get_transaction_id() ) {
				/** backward compatible with WooCommerce v2.x series **/
				if ( version_compare( WC()->version, '3.0.0', '>=' ) ) {
					$this->order()->set_transaction_id( $charge['id'] );
					$this->order()->save();
				} else {
					update_post_meta( $this->order()->id, '_transaction_id', $charge['id'] );
				}
			}

			if ( 'failed' === $charge['status'] ) {
				$this->order()->add_order_note(
					sprintf(
						wp_kses(
							__( 'Omise: Payment failed.<br/>%s (code: %s) (manual sync).', 'omise' ),
							array( 'br' => array() )
						),
						$charge['failure_message'],
						$charge['failure_code']
					)
				);
				$this->order()->update_status( 'failed' );
				return;
			}

			if ( 'pending' === $charge['status'] ) {
				$this->order()->add_order_note(
					wp_kses(
						__( 'Omise: Payment is still in progress.<br/>You might wait for a moment before click sync the status again or contact Omise support team at support@omise.co if you have any questions (manual sync).', 'omise' ),
						array( 'br' => array() )
					)
				);
				return;
			}

			if ( 'successful' === $charge['status'] ) {
				$this->order()->add_order_note(
					sprintf(
						wp_kses(
							__( 'Omise: Payment successful.<br/>An amount %1$s %2$s has been paid (manual sync).', 'omise' ),
							array( 'br' => array() )
						),
						$this->order()->get_total(),
						$this->order()->get_order_currency()
					)
				);

				if ( ! $this->order()->is_paid() ) {
					$this->order()->payment_complete();
				}

				return;
			}

			throw new Exception(
				__( 'Cannot read the payment status. Please try sync again or contact Omise support team at support@omise.co if you have any questions.', 'omise' )
			);
		} catch ( Exception $e ) {
			$order->add_order_note(
				sprintf(
					wp_kses(
						__( 'Omise: Sync failed (manual sync).<br/>%s (manual sync).', 'omise' ),
						array( 'br' => array() )
					),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * @since  4.0
	 *
	 * @return array
	 */
	protected function charge_params_default() {
		return array(
			'amount'      => Omise_Money::to_subunit( $this->order()->get_total(), $this->order()->get_order_currency() ),
			'currency'    => $this->order()->get_order_currency(),
			'description' => apply_filters( 'omise_charge_params_description', 'WooCommerce Order id ' . $this->order_id, $this->order() ),
			'metadata'    => array_merge(
				apply_filters( 'omise_charge_params_metadata', array(), $this->order() ),
				array( 'order_id' => $this->order_id ) // override order_id as a reference for webhook handlers.
			)
		);
	}

	/**
	 * Set an order transaction id
	 *
	 * @param string $transaction_id  Omise charge id.
	 */
	protected function set_order_transaction_id( $transaction_id ) {
		/** backward compatible with WooCommerce v2.x series **/
		if ( version_compare( WC()->version, '3.0.0', '>=' ) ) {
			$this->order()->set_transaction_id( $transaction_id );
			$this->order()->save();
		} else {
			update_post_meta( $this->order()->id, '_transaction_id', $transaction_id );
		}
	}

	/**
	 * @param int|mixed $order_id
	 */
	protected function invalid_order( $order_id ) {
		wc_add_notice(
			sprintf(
				wp_kses(
					__( 'We have been unable to process your payment.<br/>Please note that you\'ve done nothing wrong - this is likely an issue with our store.<br/><br/>Feel free to try submitting your order again, or report this problem to our support team (Your temporary order id is \'%s\')', 'omise' ),
					array(
						'br' => array()
					)
				),
				$order_id
			),
			'error'
		);
	}

	/**
	 * @param string $message
	 */
	protected function payment_failed( $message ) {
		wc_add_notice(
			sprintf(
				wp_kses(
					__( 'It seems we\'ve been unable to process your payment properly:<br/>%s', 'omise' ),
					array( 'br' => array() )
				),
				$message
			),
			'error'
		);

		if ( $this->order() ) {
			$this->order()->add_order_note(
				sprintf( __( 'Omise: Payment failed, %s', 'omise' ), $message )
			);
		}
	}

	/**
	 * Retrieve an attached charge id.
	 *
	 * @deprecated 3.4  We can simply retrieve Omise charge id via WC_Order::get_transaction_id().
	 *                  Unfortunately, we may need to leave this code
	 *                  as it is for backward compatibility reason.
	 *
	 * @return    string
	 */
	public function get_charge_id_from_order() {
		if ( $charge_id = $this->order()->get_transaction_id() ) {
			return $charge_id;
		}

		/**
		 * @deprecated 3.4
		 * The following code are for backward compatible only.
		 */
		// Backward compatible for Omise v3.0 - v3.3
		$order_id  = version_compare( WC()->version, '3.0.0', '>=' ) ? $this->order()->get_id() : $this->order()->id;
		$charge_id = get_post_meta( $order_id, self::CHARGE_ID, true );

		// Backward compatible for Omise v1.2.3
		if ( empty( $charge_id ) ) {
			$charge_id = $this->deprecated_get_charge_id_from_post();
		}

		return $charge_id;
	}

	/**
	 * Attach a charge id into an order.
	 *
	 * @deprecated 3.4  Now using Omise_Payment::set_order_transaction_id().
	 *                  However, keeping this method here just in case
	 *                  if this method has been implemented in some other of 3rd-party plugins.
	 *
	 * @param      string $charge_id  Omise charge id.
	 */
	public function attach_charge_id_to_order( $charge_id ) {
		$this->set_order_transaction_id( $charge_id );
	}

	/**
	 * Retrieve a charge id from a post.
	 *
	 * @deprecated 3.0  No longer assign a new charge id with new post.
	 *
	 * @return     string
	 */
	protected function deprecated_get_charge_id_from_post() {
		/** backward compatible with WooCommerce v2.x series **/
		$order_id  = version_compare( WC()->version, '3.0.0', '>=' ) ? $this->order()->get_id() : $this->order()->id;

		$posts = get_posts(
			array(
				'post_type'  => 'omise_charge_items',
				'meta_query' => array(
					array(
						'key'     => '_wc_order_id',
						'value'   => $order_id,
						'compare' => '='
					)
				)
			)
		);

		if ( empty( $posts ) ) {
			return '';
		}

		$post  = $posts[0];
		$value = get_post_custom_values( '_omise_charge_id', $post->ID );

		if ( ! is_null( $value ) && ! empty( $value ) ) {
			return $value[0];
		}
	}
}
