<?php
/**
 * Plugin Name: WooCommerce Tracking Bridge
 * Plugin URI:
 * Description: Bridges bidirectional tracking sync between WooCommerce PayPal Payments, WooCommerce Shipment Tracking, and WooCommerce Shipping. Install and activate — no configuration needed.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      WooCommerce Growth Team
 * License:     GPL-2.0+
 * Text Domain: wc-tracking-bridge
 *
 * ---------------------------------------------------------------------------
 * Background
 * ---------------------------------------------------------------------------
 *
 * WooCommerce PayPal Payments, WooCommerce Shipment Tracking, and WooCommerce
 * Shipping each maintain their own tracking data stores. They share no common
 * sync mechanism, leaving two gaps:
 *
 * GAP 1 — PayPal → Shipment Tracking
 *   When tracking is entered via the PayPal Package Tracking metabox, it is
 *   sent to the PayPal API but never written to Shipment Tracking. PayPal fires
 *   woocommerce_paypal_payments_before_tracking_is_added, but passes the data as
 *   a JSON-encoded HTTP request body — not a clean consumable format.
 *
 * GAP 2 — WooCommerce Shipping label purchase → PayPal
 *   When a shipping label is purchased in WooCommerce Shipping, tracking is
 *   written directly to the _wc_shipment_tracking_items order meta without going
 *   through Shipment Tracking's AJAX endpoint or REST API. PayPal already hooks
 *   those two paths (wp_ajax_wc_shipment_tracking_save_form and
 *   woocommerce_rest_prepare_order_shipment_tracking), so they are NOT gaps.
 *   The label purchase path falls through both.
 *
 * ---------------------------------------------------------------------------
 * What this plugin does
 * ---------------------------------------------------------------------------
 *
 * Direction 1 — PayPal → Shipment Tracking
 *   Hooks woocommerce_paypal_payments_before_tracking_is_added (priority 10).
 *   Decodes the JSON body, extracts tracking_number and carrier, and calls
 *   wc_st_add_tracking_number() to write the entry to Shipment Tracking.
 *   A static flag prevents the resulting meta write from triggering Direction 2.
 *
 * Direction 2 — WC Shipping label → PayPal (covers the gap only)
 *   Hooks added_order_meta / updated_order_meta on _wc_shipment_tracking_items.
 *   Before acting, checks whether we are in one of the two contexts PayPal
 *   already handles (AJAX save form or REST create). If so, skips — no
 *   double-sync. Otherwise, fires apply_filters(
 *   'woocommerce_rest_prepare_order_shipment_tracking', ...) with a synthetic
 *   WP_REST_Request that satisfies PayPal's create_item check, triggering
 *   PayPal's ShipmentTrackingIntegration sync logic.
 *   Also skips tracking numbers already present in _ppcp_paypal_tracking_info_meta_name
 *   (PayPal's own sync-state meta) to avoid duplicate API calls.
 *
 * ---------------------------------------------------------------------------
 * Known limitations
 * ---------------------------------------------------------------------------
 *
 * - Tracking updates (changing a tracking number after initial entry) are not
 *   synced. Only new entries are covered.
 * - Tracking deletion in either plugin does not propagate to the other.
 * - PayPal carrier codes are passed to wc_st_add_tracking_number() as the
 *   $provider argument. Simple codes (FEDEX → fedex, UPS → ups) survive
 *   sanitize_title() and will match ST's provider list. Compound codes
 *   (DHL_API_TOSHIP, YANWEN_CN, etc.) will not match and are stored as a
 *   custom provider — the ST tracking link will not be populated for these.
 * - This plugin requires WooCommerce PayPal Payments to be active for
 *   Direction 1, and WooCommerce Shipment Tracking to be active for both
 *   directions.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

if ( class_exists( 'WC_Tracking_Bridge' ) ) {
	return;
}

/**
 * Class WC_Tracking_Bridge
 */
class WC_Tracking_Bridge {

	/**
	 * True while we are writing to Shipment Tracking in Direction 1.
	 * Prevents the resulting _wc_shipment_tracking_items meta write from
	 * triggering Direction 2 and creating a sync loop.
	 *
	 * @var bool
	 */
	private static bool $writing_to_st = false;

	/**
	 * Tracking data queued by queue_paypal_to_st() and consumed by
	 * sync_paypal_to_st_after_success(). Keyed by a "{order_id}:{index}"
	 * string to support multiple trackers added in a single request.
	 *
	 * @var array<string, array>
	 */
	private static array $pending_st_sync = array();

	/**
	 * Boot the plugin after all plugins have loaded.
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( static::class, 'register_hooks' ), 20 );
	}

	/**
	 * Register hooks once all plugins are available.
	 */
	public static function register_hooks(): void {
		// Direction 1: PayPal metabox → Shipment Tracking.
		// Two hooks are used so that we only write to ST after a confirmed
		// PayPal API success. The 'before' hook captures the tracking data
		// (not available on the 'after' hook); the 'after' hook fires only
		// when PayPal returns HTTP 201, at which point we consume the queue.
		add_action(
			'woocommerce_paypal_payments_before_tracking_is_added',
			array( static::class, 'queue_paypal_to_st' ),
			10,
			2
		);
		add_action(
			'woocommerce_paypal_payments_after_tracking_is_added',
			array( static::class, 'sync_paypal_to_st_after_success' ),
			10,
			2
		);

		// Direction 2: _wc_shipment_tracking_items meta write → PayPal.
		// Covers WC Shipping label purchases (and any other programmatic writes)
		// that bypass PayPal's own AJAX / REST hooks.
		add_action( 'added_order_meta',   array( static::class, 'on_st_meta_written' ), 10, 4 );
		add_action( 'updated_order_meta', array( static::class, 'on_st_meta_written' ), 10, 4 );
	}

	// =========================================================================
	// Direction 1: PayPal metabox → Shipment Tracking
	// =========================================================================

	/**
	 * Fires just before PayPal sends tracking to its API.
	 * Parses the JSON request body and queues tracker data for processing
	 * after the API call succeeds.
	 *
	 * We cannot write to ST here because the PayPal API call may still fail —
	 * writing first would leave ST ahead of PayPal and worsen the sync problem.
	 *
	 * @param int   $order_id             WC order ID.
	 * @param array $shipment_request_data {
	 *     @type string $url  PayPal API endpoint URL.
	 *     @type array  $args {
	 *         @type string $body JSON-encoded shipment payload.
	 *     }
	 * }
	 */
	public static function queue_paypal_to_st( int $order_id, array $shipment_request_data ): void {
		if ( ! function_exists( 'wc_st_add_tracking_number' ) ) {
			return;
		}

		$args      = isset( $shipment_request_data['args'] ) && is_array( $shipment_request_data['args'] )
			? $shipment_request_data['args']
			: array();
		$body_raw  = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '{}';
		$body      = json_decode( $body_raw, true );

		if ( ! is_array( $body ) ) {
			return;
		}

		// PayPal v1 API wraps shipments in a 'trackers' array.
		// PayPal v2 API sends a single shipment object directly.
		$trackers = ( isset( $body['trackers'] ) && is_array( $body['trackers'] ) )
			? $body['trackers']
			: array( $body );

		foreach ( $trackers as $index => $tracker ) {
			if ( ! is_array( $tracker ) || empty( $tracker['tracking_number'] ) ) {
				continue;
			}
			$key = "{$order_id}:{$index}";
			self::$pending_st_sync[ $key ] = array(
				'order_id' => $order_id,
				'tracker'  => $tracker,
			);
		}
	}

	/**
	 * Fires after PayPal's API returns HTTP 201 (tracker created successfully).
	 * Consumes any queued tracker data for this order and writes it to ST.
	 *
	 * @param int   $order_id WC order ID.
	 * @param mixed $response The WP HTTP response (unused; its presence confirms success).
	 */
	public static function sync_paypal_to_st_after_success( int $order_id, $response ): void {
		if ( self::$writing_to_st ) {
			return;
		}

		$pending = array_filter(
			self::$pending_st_sync,
			static function ( array $item ) use ( $order_id ): bool {
				return $item['order_id'] === $order_id;
			}
		);

		if ( empty( $pending ) ) {
			return;
		}

		// Clear the queue before writing so that if wc_st_add_tracking_number
		// triggers a re-entrant save, the queue is already empty.
		foreach ( array_keys( $pending ) as $key ) {
			unset( self::$pending_st_sync[ $key ] );
		}

		self::$writing_to_st = true;

		try {
			foreach ( $pending as $item ) {
				self::add_tracker_to_st( $item['order_id'], $item['tracker'] );
			}
		} finally {
			self::$writing_to_st = false;
		}
	}

	/**
	 * Writes a single PayPal tracker entry to Shipment Tracking.
	 *
	 * @param int   $order_id WC order ID.
	 * @param array $tracker  Single tracker data from PayPal API body.
	 */
	private static function add_tracker_to_st( int $order_id, array $tracker ): void {
		$tracking_number = sanitize_text_field( $tracker['tracking_number'] ?? '' );
		$carrier         = sanitize_text_field( $tracker['carrier'] ?? '' );
		$carrier_other   = sanitize_text_field( $tracker['carrier_name_other'] ?? '' );

		if ( ! $tracking_number ) {
			return;
		}

		// Avoid duplicates: skip if ST already has this tracking number.
		if ( in_array( $tracking_number, self::get_st_tracking_numbers( $order_id ), true ) ) {
			return;
		}

		// When PayPal carrier is OTHER, use carrier_name_other as the provider.
		// Otherwise pass the PayPal carrier code directly.
		$provider = ( 'OTHER' === $carrier && $carrier_other ) ? $carrier_other : $carrier;

		wc_st_add_tracking_number( $order_id, $tracking_number, $provider );
	}

	/**
	 * Returns all tracking numbers currently stored in Shipment Tracking for an order.
	 *
	 * @param int $order_id WC order ID.
	 * @return string[]
	 */
	private static function get_st_tracking_numbers( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( ! is_array( $items ) ) {
			return array();
		}

		return array_values( array_filter( array_column( $items, 'tracking_number' ) ) );
	}

	// =========================================================================
	// Direction 2: _wc_shipment_tracking_items write → PayPal
	// =========================================================================

	/**
	 * Fires on every order meta add or update.
	 * When _wc_shipment_tracking_items is written and PayPal isn't already
	 * handling the sync, pushes any new tracking numbers to PayPal.
	 *
	 * @param int|string $meta_id    Meta row ID.
	 * @param int        $order_id   WC order ID.
	 * @param string     $meta_key   Meta key.
	 * @param mixed      $meta_value Meta value.
	 */
	public static function on_st_meta_written( $meta_id, $order_id, string $meta_key, $meta_value ): void {
		if ( '_wc_shipment_tracking_items' !== $meta_key ) {
			return;
		}

		// This write was made by Direction 1 (our own sync_paypal_to_st call).
		// Don't re-trigger — that would create an infinite loop.
		if ( self::$writing_to_st ) {
			return;
		}

		// PayPal Payments already handles the ST admin UI AJAX save and ST REST
		// API creates natively. Skip those contexts to avoid double API calls.
		if ( self::paypal_already_handles_this_context() ) {
			return;
		}

		if ( ! is_array( $meta_value ) || empty( $meta_value ) ) {
			return;
		}

		$order_id = (int) $order_id;

		// Tracking numbers PayPal has already successfully synced for this order.
		$already_synced = self::get_paypal_synced_tracking_numbers( $order_id );

		foreach ( $meta_value as $tracking_item ) {
			if ( ! is_array( $tracking_item ) ) {
				continue;
			}

			$tracking_number = $tracking_item['tracking_number'] ?? '';
			if ( ! $tracking_number ) {
				continue;
			}

			if ( in_array( $tracking_number, $already_synced, true ) ) {
				continue;
			}

			self::trigger_paypal_sync( $order_id, $tracking_item );
		}
	}

	/**
	 * Returns true when we are running inside a context that PayPal's
	 * ShipmentTrackingIntegration already handles, making our sync redundant.
	 *
	 * PayPal handles:
	 *   - wp_ajax_wc_shipment_tracking_save_form  (Shipment Tracking metabox)
	 *   - woocommerce_rest_prepare_order_shipment_tracking (ST REST API creates)
	 *
	 * @return bool
	 */
	private static function paypal_already_handles_this_context(): bool {
		// Shipment Tracking metabox AJAX form submission.
		if (
			wp_doing_ajax()
			&& isset( $_REQUEST['action'] ) // phpcs:ignore WordPress.Security.NonceVerification
			&& 'wc_shipment_tracking_save_form' === $_REQUEST['action'] // phpcs:ignore WordPress.Security.NonceVerification
		) {
			return true;
		}

		// Shipment Tracking REST API create.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$wp    = $GLOBALS['wp'] ?? null;
			$route = ( $wp instanceof WP ) ? ( $wp->query_vars['rest_route'] ?? '' ) : '';
			if ( false !== strpos( (string) $route, 'shipment-trackings' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns tracking numbers that PayPal has already successfully pushed to
	 * its API for this order.
	 *
	 * PayPal stores these in _ppcp_paypal_tracking_info_meta_name as an array
	 * keyed by tracking number:  [ 'TRACKING123' => [ item_ids... ], ... ]
	 *
	 * @param int $order_id WC order ID.
	 * @return string[]
	 */
	private static function get_paypal_synced_tracking_numbers( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		// Meta key defined in:
		// WooCommerce\PayPalCommerce\OrderTracking\OrderTrackingModule::PPCP_TRACKING_INFO_META_NAME
		$meta = $order->get_meta( '_ppcp_paypal_tracking_info_meta_name' );

		return is_array( $meta ) ? array_keys( $meta ) : array();
	}

	/**
	 * Triggers PayPal's tracking sync by invoking the filter that
	 * ShipmentTrackingIntegration listens to for REST-created tracking items.
	 *
	 * We synthesise a WP_REST_Request that satisfies PayPal's create_item check:
	 *   $callback = $request->get_attributes()['callback'][1] ?? '';
	 *   if ( $callback !== 'create_item' ) { return $response; }
	 *
	 * This reuses PayPal's existing, tested sync path rather than reimplementing
	 * the PayPal API call. It is the same mechanism PayPal uses for all REST
	 * creates — we just invoke it from a non-REST context.
	 *
	 * @param int   $order_id     WC order ID.
	 * @param array $tracking_item ST-format tracking item array.
	 */
	private static function trigger_paypal_sync( int $order_id, array $tracking_item ): void {
		if ( ! class_exists( 'WP_REST_Request' ) || ! class_exists( 'WP_REST_Response' ) ) {
			return;
		}

		// Check whether PayPal's ShipmentTrackingIntegration has a callback
		// registered on this filter before invoking it. If not (PayPal Payments
		// not active, or its ST integration disabled via the
		// woocommerce_paypal_payments_sync_wc_shipment_tracking filter), log a
		// notice so the merchant has visibility rather than a silent no-op.
		if ( ! has_filter( 'woocommerce_rest_prepare_order_shipment_tracking' ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->notice(
					sprintf(
						'WC Tracking Bridge: could not sync tracking %s for order %d to PayPal — no handler registered on woocommerce_rest_prepare_order_shipment_tracking. Is WooCommerce PayPal Payments active?',
						$tracking_item['tracking_number'] ?? '(unknown)',
						$order_id
					),
					array( 'source' => 'wc-tracking-bridge' )
				);
			}
			return;
		}

		// We invoke apply_filters() here purely for its side effect: PayPal's
		// ShipmentTrackingIntegration callback runs and pushes the tracking data
		// to the PayPal API. The returned WP_REST_Response is intentionally
		// discarded — we have no use for it.
		$request = new WP_REST_Request( 'POST', '' );
		$request->set_attributes( array( 'callback' => array( null, 'create_item' ) ) );

		apply_filters( // phpcs:ignore WordPress.WP.DiscouragedFunctions.apply_filters_apply_filters -- intentional side-effect invocation
			'woocommerce_rest_prepare_order_shipment_tracking',
			new WP_REST_Response(),
			array(
				'order_id'                 => $order_id,
				'tracking_number'          => sanitize_text_field( $tracking_item['tracking_number'] ?? '' ),
				'tracking_provider'        => sanitize_text_field( $tracking_item['tracking_provider'] ?? '' ),
				'custom_tracking_provider' => sanitize_text_field( $tracking_item['custom_tracking_provider'] ?? '' ),
			),
			$request
		);
	}
}

WC_Tracking_Bridge::init();
