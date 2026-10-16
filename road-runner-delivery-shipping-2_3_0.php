<?php
/**
 * Plugin Name: Road Runner Delivery Shipping
 * Plugin URI: https://skyhighlabs.org/
 * Description: Road Runner Courier Services WooCommerce delivery pricing using Geoapify driving distance.
 * Version: 2.3.0
 * Author: Road Runner Courier Services
 * Requires Plugins: woocommerce
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| GEOAPIFY API KEY
|--------------------------------------------------------------------------
| Replace the placeholder below with your Geoapify API key.
| For better security, define RR_GEOAPIFY_API_KEY in wp-config.php instead.
|--------------------------------------------------------------------------
*/

if ( ! defined( 'RR_GEOAPIFY_API_KEY' ) ) {
	define( 'RR_GEOAPIFY_API_KEY', '' );
}

/*
|--------------------------------------------------------------------------
| DEFAULTS
|--------------------------------------------------------------------------
*/

if ( ! defined( 'RR_INCLUDED_MILES' ) ) {
	define( 'RR_INCLUDED_MILES', 10 );
}

if ( ! defined( 'RR_EXTRA_MILE_PRICE' ) ) {
	define( 'RR_EXTRA_MILE_PRICE', 0.75 );
}

if ( ! defined( 'RR_THRESHOLD_MILES' ) ) {
	define( 'RR_THRESHOLD_MILES', 3.5 );
}

if ( ! defined( 'RR_THRESHOLD_DISCOUNT' ) ) {
	define( 'RR_THRESHOLD_DISCOUNT', 3 );
}

if ( ! defined( 'RR_CUSTOM_QUOTE_MILES' ) ) {
	define( 'RR_CUSTOM_QUOTE_MILES', 20 );
}

if ( ! defined( 'RR_INCLUDED_PACKAGES' ) ) {
	define( 'RR_INCLUDED_PACKAGES', 3 );
}

if ( ! defined( 'RR_MAX_PACKAGES' ) ) {
	define( 'RR_MAX_PACKAGES', 100 );
}

if ( ! defined( 'RR_PACKAGE_FEE' ) ) {
	define( 'RR_PACKAGE_FEE', 5 );
}

if ( ! defined( 'RR_MIN_PACKAGE_FEE' ) ) {
	define( 'RR_MIN_PACKAGE_FEE', 5 );
}

if ( ! defined( 'RR_MAX_PACKAGE_FEE' ) ) {
	define( 'RR_MAX_PACKAGE_FEE', 100 );
}

/*
|--------------------------------------------------------------------------
| DELIVERY SERVICES
|--------------------------------------------------------------------------
*/

function rr_delivery_services() {
	return array(
		'express_2_hour' => array(
			'label' => 'Express 2-Hour Delivery',
			'base'  => 215.00,
		),
		'same_day' => array(
			'label' => 'Same Day Delivery',
			'base'  => 95.00,
		),
		'next_day' => array(
			'label' => 'Next Day Delivery',
			'base'  => 85.00,
		),
		'medical_legal' => array(
			'label' => 'Medical Materials and Legal Documents',
			'base'  => 55.00,
		),
		'scheduled_routes' => array(
			'label' => 'Scheduled Routes',
			'base'  => 15.00,
		),
		'multiple_deliveries' => array(
			'label' => 'Multiple Deliveries | Full or Half Days',
			'base'  => 85.00,
			'quote' => true,
		),
		'mobile_public_notary' => array(
			'label' => 'Mobile Public Notary (Marriage License, Legal Documents, etc.)',
			'base'  => 29.00,
		),
		'saturday_delivery' => array(
			'label' => 'Saturday Delivery',
			'base'  => 35.00,
		),
		'standard_delivery' => array(
			'label' => 'Standard Delivery',
			'base'  => 25.00,
		),
	);
}

/*
|--------------------------------------------------------------------------
| DEBUG LOGGER
|--------------------------------------------------------------------------
*/

function rr_delivery_log( $message, $data = null ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	if ( null !== $data ) {
		$message .= ' ' . wp_json_encode(
			$data,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	error_log( '[Road Runner Delivery] ' . $message );
}

/*
|--------------------------------------------------------------------------
| GET PICKUP ADDRESS
|--------------------------------------------------------------------------
| Pickup = WooCommerce BILLING address.
|--------------------------------------------------------------------------
*/

function rr_get_pickup_address() {
	if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
		return '';
	}

	$customer = WC()->customer;

	$parts = array(
		$customer->get_billing_address_1(),
		$customer->get_billing_address_2(),
		$customer->get_billing_city(),
		$customer->get_billing_state(),
		$customer->get_billing_postcode(),
		$customer->get_billing_country(),
	);

	$parts = array_filter(
		array_map( 'sanitize_text_field', $parts )
	);

	return implode( ', ', $parts );
}

/*
|--------------------------------------------------------------------------
| GET DROP-OFF ADDRESS
|--------------------------------------------------------------------------
| Drop-off = WooCommerce SHIPPING address.
|--------------------------------------------------------------------------
*/

function rr_get_dropoff_address( $package = array() ) {
	$destination = array();

	if (
		isset( $package['destination'] ) &&
		is_array( $package['destination'] )
	) {
		$destination = $package['destination'];
	}

	$parts = array(
		isset( $destination['address'] ) ? $destination['address'] : '',
		isset( $destination['address_2'] ) ? $destination['address_2'] : '',
		isset( $destination['city'] ) ? $destination['city'] : '',
		isset( $destination['state'] ) ? $destination['state'] : '',
		isset( $destination['postcode'] ) ? $destination['postcode'] : '',
		isset( $destination['country'] ) ? $destination['country'] : '',
	);

	$parts = array_filter(
		array_map( 'sanitize_text_field', $parts )
	);

	return implode( ', ', $parts );
}

/*
|--------------------------------------------------------------------------
| GET TOTAL QUANTITY
|--------------------------------------------------------------------------
*/

function rr_get_cart_quantity() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 1;
	}

	$quantity = 0;

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$quantity += isset( $cart_item['quantity'] )
			? absint( $cart_item['quantity'] )
			: 0;
	}

	return max( 1, $quantity );
}

/*
|--------------------------------------------------------------------------
| SAME STATE / COUNTRY CHECK
|--------------------------------------------------------------------------
| Routes between different states or countries are rejected.
| Pickup = billing state/country, drop-off = shipping state/country.
|--------------------------------------------------------------------------
*/

function rr_normalize_region_code( $value ) {
	return strtoupper( trim( (string) $value ) );
}

function rr_regions_differ(
	$pickup_state,
	$pickup_country,
	$dropoff_state,
	$dropoff_country
) {
	$pairs = array(
		array( $pickup_country, $dropoff_country ),
		array( $pickup_state, $dropoff_state ),
	);

	foreach ( $pairs as $pair ) {
		$first  = rr_normalize_region_code( $pair[0] );
		$second = rr_normalize_region_code( $pair[1] );

		if ( '' !== $first && '' !== $second && $first !== $second ) {
			return true;
		}
	}

	return false;
}

function rr_get_pickup_region() {
	if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
		return array(
			'state'   => '',
			'country' => '',
		);
	}

	return array(
		'state'   => WC()->customer->get_billing_state(),
		'country' => WC()->customer->get_billing_country(),
	);
}

function rr_get_dropoff_region( $package = array() ) {
	$destination = array();

	if (
		isset( $package['destination'] ) &&
		is_array( $package['destination'] )
	) {
		$destination = $package['destination'];
	}

	return array(
		'state'   => isset( $destination['state'] ) ? $destination['state'] : '',
		'country' => isset( $destination['country'] ) ? $destination['country'] : '',
	);
}

function rr_current_regions_differ() {
	if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
		return false;
	}

	$customer = WC()->customer;

	return rr_regions_differ(
		$customer->get_billing_state(),
		$customer->get_billing_country(),
		$customer->get_shipping_state(),
		$customer->get_shipping_country()
	);
}

/*
|--------------------------------------------------------------------------
| GEOAPIFY HTTP REQUEST
|--------------------------------------------------------------------------
*/

function rr_geoapify_request( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 20,
			'redirection' => 3,
			'headers'     => array(
				'Accept' => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		rr_delivery_log(
			'HTTP request failed:',
			$response->get_error_message()
		);

		return $response;
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	$body        = wp_remote_retrieve_body( $response );

	if ( $status_code < 200 || $status_code >= 300 ) {
		rr_delivery_log(
			'Geoapify HTTP error:',
			array(
				'status' => $status_code,
				'body'   => $body,
			)
		);

		return new WP_Error(
			'geoapify_http_error',
			'Geoapify returned HTTP ' . $status_code
		);
	}

	$data = json_decode( $body, true );

	if ( ! is_array( $data ) ) {
		rr_delivery_log(
			'Geoapify returned invalid JSON:',
			$body
		);

		return new WP_Error(
			'geoapify_invalid_json',
			'Invalid JSON returned by Geoapify.'
		);
	}

	return $data;
}

/*
|--------------------------------------------------------------------------
| GEOCODING
|--------------------------------------------------------------------------
*/

function rr_geoapify_geocode( $address, $api_key = '' ) {
	$address = trim( $address );

	if ( empty( $address ) ) {
		return new WP_Error(
			'empty_address',
			'Address is empty.'
		);
	}

	$cache_key = 'rr_geo_v21_' . md5( strtolower( $address ) );

	$cached = get_transient( $cache_key );

	if (
		false !== $cached &&
		is_array( $cached ) &&
		isset( $cached['lat'], $cached['lon'] )
	) {
		return $cached;
	}

	$api_key = trim( (string) $api_key );

	if ( '' === $api_key ) {
		return new WP_Error( 'missing_geoapify_api_key', 'Geoapify API key is missing.' );
	}

	$url = add_query_arg(
		array(
			'text'   => $address,
			'format' => 'json',
			'limit'  => 1,
			'apiKey' => $api_key,
		),
		'https://api.geoapify.com/v1/geocode/search'
	);

	$data = rr_geoapify_request( $url );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	if (
		empty( $data['results'] ) ||
		! is_array( $data['results'] )
	) {
		rr_delivery_log(
			'Address could not be geocoded:',
			$address
		);

		return new WP_Error(
			'address_not_found',
			'Geoapify could not find the address.'
		);
	}

	$result = $data['results'][0];

	if (
		! isset( $result['lat'], $result['lon'] )
	) {
		return new WP_Error(
			'coordinates_missing',
			'Geoapify did not return coordinates.'
		);
	}

	$coordinates = array(
		'lat' => (float) $result['lat'],
		'lon' => (float) $result['lon'],
	);

	set_transient(
		$cache_key,
		$coordinates,
		DAY_IN_SECONDS
	);

	return $coordinates;
}

/*
|--------------------------------------------------------------------------
| ROUTING
|--------------------------------------------------------------------------
*/

function rr_geoapify_route_distance(
	$pickup_coordinates,
	$dropoff_coordinates,
	$api_key = ''
) {
	if (
		! isset(
			$pickup_coordinates['lat'],
			$pickup_coordinates['lon']
		)
	) {
		return new WP_Error(
			'invalid_pickup_coordinates',
			'Invalid pickup coordinates.'
		);
	}

	if (
		! isset(
			$dropoff_coordinates['lat'],
			$dropoff_coordinates['lon']
		)
	) {
		return new WP_Error(
			'invalid_dropoff_coordinates',
			'Invalid drop-off coordinates.'
		);
	}

	$api_key = trim( (string) $api_key );

	if ( '' === $api_key ) {
		return new WP_Error( 'missing_geoapify_api_key', 'Geoapify API key is missing.' );
	}

	$waypoints =
		$pickup_coordinates['lat'] .
		',' .
		$pickup_coordinates['lon'] .
		'|' .
		$dropoff_coordinates['lat'] .
		',' .
		$dropoff_coordinates['lon'];

	$url = add_query_arg(
		array(
			'waypoints' => $waypoints,
			'mode'      => 'drive',
			'units'     => 'imperial',
			'format'    => 'json',
			'apiKey'    => $api_key,
		),
		'https://api.geoapify.com/v1/routing'
	);

	$data = rr_geoapify_request( $url );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	if (
		empty( $data['results'] ) ||
		! isset( $data['results'][0]['distance'] )
	) {
		rr_delivery_log(
			'Distance missing from Geoapify response:',
			$data
		);

		return new WP_Error(
			'route_distance_missing',
			'Geoapify did not return a route distance.'
		);
	}

	$distance = (float) $data['results'][0]['distance'];
	$distance_units = isset( $data['results'][0]['distance_units'] )
		? strtolower( (string) $data['results'][0]['distance_units'] )
		: 'miles';

	if ( in_array( $distance_units, array( 'meter', 'meters', 'm' ), true ) ) {
		$distance = $distance / 1609.344;
	} elseif ( in_array( $distance_units, array( 'kilometer', 'kilometers', 'km' ), true ) ) {
		$distance = $distance / 1.609344;
	}

	if ( $distance < 0 ) {
		return new WP_Error(
			'invalid_route_distance',
			'Geoapify returned an invalid route distance.'
		);
	}

	return round( $distance, 2 );
}

/*
|--------------------------------------------------------------------------
| COMPLETE ROUTE CALCULATION
|--------------------------------------------------------------------------
*/

function rr_get_road_distance(
	$pickup_address,
	$dropoff_address,
	$api_key = ''
) {
	$pickup_address  = trim( $pickup_address );
	$dropoff_address = trim( $dropoff_address );

	if (
		empty( $pickup_address ) ||
		empty( $dropoff_address )
	) {
		return new WP_Error(
			'missing_route_address',
			'Pickup or drop-off address is missing.'
		);
	}

	$cache_key = 'rr_route_v21_' . md5(
		strtolower(
			$pickup_address . '|' . $dropoff_address
		)
	);

	$cached = get_transient( $cache_key );

	if (
		false !== $cached &&
		is_array( $cached ) &&
		isset( $cached['distance_miles'] )
	) {
		return $cached;
	}

	$pickup_coordinates = rr_geoapify_geocode(
		$pickup_address,
		$api_key
	);

	if ( is_wp_error( $pickup_coordinates ) ) {
		return $pickup_coordinates;
	}

	$dropoff_coordinates = rr_geoapify_geocode(
		$dropoff_address,
		$api_key
	);

	if ( is_wp_error( $dropoff_coordinates ) ) {
		return $dropoff_coordinates;
	}

	$distance_miles = rr_geoapify_route_distance(
		$pickup_coordinates,
		$dropoff_coordinates,
		$api_key
	);

	if ( is_wp_error( $distance_miles ) ) {
		return $distance_miles;
	}

	$result = array(
		'distance_miles' => round(
			(float) $distance_miles,
			2
		),
		'pickup_lat'     => $pickup_coordinates['lat'],
		'pickup_lon'     => $pickup_coordinates['lon'],
		'dropoff_lat'    => $dropoff_coordinates['lat'],
		'dropoff_lon'    => $dropoff_coordinates['lon'],
		'calculated_at'  => current_time( 'mysql' ),
	);

	set_transient(
		$cache_key,
		$result,
		HOUR_IN_SECONDS
	);

	return $result;
}

/*
|--------------------------------------------------------------------------
| SHIPPING METHOD
|--------------------------------------------------------------------------
*/

add_action(
	'woocommerce_shipping_init',
	'rr_register_shipping_method'
);

function rr_register_shipping_method() {

	if ( class_exists( 'RR_Delivery_Shipping_Method' ) ) {
		return;
	}

	class RR_Delivery_Shipping_Method extends WC_Shipping_Method {

		public function __construct( $instance_id = 0 ) {

			$this->id                 = 'rr_delivery_shipping';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = 'Road Runner Delivery';
			$this->method_description = 'Road Runner Courier Services delivery pricing based on actual driving distance using Geoapify.';

			$this->supports = array(
				'shipping-zones',
				'instance-settings',
				'instance-settings-modal',
			);

			$this->init_instance_form_fields();
			$this->init_instance_settings();

			$this->enabled = $this->get_instance_option(
				'enabled',
				'yes'
			);

			$this->title = $this->get_instance_option(
				'title',
				'Delivery Service'
			);
		}

		/*
		|--------------------------------------------------------------------------
		| SETTINGS
		|--------------------------------------------------------------------------
		*/

		public function init_instance_form_fields() {

			$fields = array(

				'api_key' => array(
					'title'       => 'Geoapify API key',
					'type'        => 'password',
					'description' => 'Enter your Geoapify API key. If blank, RR_GEOAPIFY_API_KEY from wp-config.php will be used.',
					'default'     => '',
					'desc_tip'    => true,
				),

				'enabled' => array(
					'title'   => 'Enable',
					'type'    => 'checkbox',
					'label'   => 'Enable Road Runner Delivery',
					'default' => 'yes',
				),

				'title' => array(
					'title'       => 'Method title',
					'type'        => 'text',
					'description' => 'Heading displayed above the delivery options.',
					'default'     => 'Delivery Service',
					'desc_tip'    => true,
				),

				'pricing_mode' => array(
					'title'       => 'Pricing mode',
					'type'        => 'select',
					'description' => 'Global Formula uses one set of pricing rules for every service. Separate Formula lets every service have its own pricing rules.',
					'default'     => 'global',
					'options'     => array(
						'global'   => 'Global Formula',
						'separate' => 'Separate Formula',
					),
					'desc_tip'    => true,
				),

				'global_base_price' => array(
					'title'             => 'Global base price',
					'type'              => 'price',
					'description'       => 'Base delivery price used by the Global Formula.',
					'default'           => '25.00',
					'desc_tip'          => true,
					'sanitize_callback' => array(
						$this,
						'sanitize_decimal',
					),
				),

				'global_included_miles' => array(
					'title'             => 'Global included distance',
					'type'              => 'number',
					'description'       => 'Distance included in the base price for the standard formulas.',
					'default'           => '10',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				),

				'global_extra_mile_price' => array(
					'title'             => 'Global price per extra mile',
					'type'              => 'price',
					'description'       => 'Price charged for each mile above the included distance. Formula 4 uses its own threshold instead.',
					'default'           => '0.75',
					'desc_tip'          => true,
					'sanitize_callback' => array(
						$this,
						'sanitize_decimal',
					),
				),

				'global_distance_multiplier' => array(
					'title'             => 'Global distance multiplier',
					'type'              => 'number',
					'description'       => 'Multiplier applied to the distance-based formula. Use 1.00 for no change.',
					'default'           => '1.00',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				),

				'global_threshold_miles' => array(
					'title'             => 'Global basic-price distance threshold',
					'type'              => 'number',
					'description'       => 'Formula 4: distances at or below this threshold receive the discounted base price.',
					'default'           => '3.5',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				),

				'global_threshold_discount' => array(
					'title'             => 'Global threshold discount percentage',
					'type'              => 'number',
					'description'       => 'Formula 4: percentage discount applied when the distance is at or below the threshold.',
					'default'           => '3',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'max' => '100',
						'step' => '0.01',
					),
				),

				'global_quantity_multiplier' => array(
					'title'             => 'Global quantity multiplier',
					'type'              => 'number',
					'description'       => 'Multiplier applied to the final price based on total cart quantity. Use 1.00 for no quantity increase.',
					'default'           => '1.00',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				),

				'global_min_price' => array(
					'title'       => 'Global minimum price',
					'type'        => 'price',
					'description' => 'Optional minimum delivery price. Set to 0 to disable.',
					'default'     => '0',
					'desc_tip'    => true,
				),

				'global_max_price' => array(
					'title'       => 'Global maximum price',
					'type'        => 'price',
					'description' => 'Optional maximum delivery price. Set to 0 to disable.',
					'default'     => '0',
					'desc_tip'    => true,
				),

				'global_formula' => array(
					'title'       => 'Global formula',
					'type'        => 'select',
					'description' => 'Formula used when Pricing Mode is Global Formula.',
					'default'     => 'base_plus_extra',
					'options'     => array(
						'base_plus_extra' => 'Base Price + (Extra Miles × Extra Mile Price)',
						'base_plus_extra_distance' => '(Base Price + Extra Mileage) × Distance Multiplier',
						'base_times_distance' => 'Base Price × Distance Multiplier + Extra Mileage',
						'distance_threshold_discount' => 'Formula 4: Threshold Discount + Extra Mileage',
						'distance_only' => '(Extra Miles × Extra Mile Price) × Distance Multiplier',
					),
					'desc_tip'    => true,
				),

				'quantity_mode' => array(
					'title'       => 'Quantity mode',
					'type'        => 'select',
					'description' => 'Choose how cart quantity affects delivery price. Package fees are charged separately; "Ignore quantity" is recommended.',
					'default'     => 'none',
					'options'     => array(
						'none' => 'Ignore quantity',
						'multiply_final' => 'Multiply final price by quantity',
						'add_per_quantity' => 'Add the calculated delivery price for each quantity',
					),
					'desc_tip'    => true,
				),
			);

			/*
			 * Distance limit, package add-on and Saturday surcharge.
			 */
			$fields = array_merge(
				$fields,
				array(

					'custom_quote_miles' => array(
						'title'             => 'Custom quote distance (miles)',
						'type'              => 'number',
						'description'       => 'At or beyond this driving distance, only "Custom Quote Required" is shown. Set to 0 to disable.',
						'default'           => '20',
						'desc_tip'          => true,
						'custom_attributes' => array(
							'min'  => '0',
							'step' => '0.01',
						),
					),

					'included_packages' => array(
						'title'             => 'Included packages',
						'type'              => 'number',
						'description'       => 'Packages covered by the delivery price. The package fee starts with the next package (3 = fee starts at package 4).',
						'default'           => '3',
						'desc_tip'          => true,
						'custom_attributes' => array(
							'min'  => '0',
							'step' => '1',
						),
					),

					'max_packages' => array(
						'title'             => 'Maximum packages',
						'type'              => 'number',
						'description'       => 'Above this many packages, only "Custom Quote Required" is shown.',
						'default'           => '100',
						'desc_tip'          => true,
						'custom_attributes' => array(
							'min'  => '1',
							'step' => '1',
						),
					),

					'package_fee_enabled' => array(
						'title'   => 'Package fee',
						'type'    => 'checkbox',
						'label'   => 'Charge a fee for each package above the included limit',
						'default' => 'yes',
					),

					'package_fee' => array(
						'title'             => 'Fee per additional package',
						'type'              => 'number',
						'description'       => 'Fixed fee for each package above the included limit. Allowed range: 5 to 100.',
						'default'           => '5.00',
						'desc_tip'          => true,
						'custom_attributes' => array(
							'min'  => '5',
							'max'  => '100',
							'step' => '0.01',
						),
					),

					'saturday_surcharge' => array(
						'title'             => 'Saturday surcharge',
						'type'              => 'number',
						'description'       => 'Flat amount added to the Saturday Delivery service. Set to 0 to disable.',
						'default'           => '0.00',
						'desc_tip'          => true,
						'custom_attributes' => array(
							'min'  => '0',
							'step' => '0.01',
						),
					),
				)
			);

			$services = rr_delivery_services();

			foreach ( $services as $service_key => $service ) {

				// Custom-quote services have no price settings.
				if ( ! empty( $service['quote'] ) ) {
					continue;
				}

				$prefix = 'service_' . $service_key . '_';

				$fields[ $prefix . 'base_price' ] = array(
					'title'             => $service['label'] . ' — Base price',
					'type'              => 'price',
					'description'       => 'Used when Pricing Mode is Separate Formula.',
					'default'           => number_format(
						$service['base'],
						2,
						'.',
						''
					),
					'desc_tip'          => true,
				);

				$fields[ $prefix . 'included_miles' ] = array(
					'title'             => $service['label'] . ' — Included distance',
					'type'              => 'number',
					'description'       => 'Distance included in this service base price for the standard formulas.',
					'default'           => '10',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				);

				$fields[ $prefix . 'threshold_miles' ] = array(
					'title'             => $service['label'] . ' — Basic-price distance threshold',
					'type'              => 'number',
					'description'       => 'Formula 4: distances at or below this threshold receive the discounted base price.',
					'default'           => '3.5',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				);

				$fields[ $prefix . 'threshold_discount' ] = array(
					'title'             => $service['label'] . ' — Threshold discount percentage',
					'type'              => 'number',
					'description'       => 'Formula 4: percentage discount applied at or below the threshold.',
					'default'           => '3',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'max' => '100',
						'step' => '0.01',
					),
				);

				$fields[ $prefix . 'extra_mile_price' ] = array(
					'title'             => $service['label'] . ' — Price per extra mile',
					'type'              => 'price',
					'description'       => 'Price charged for each extra mile. Formula 4 calculates extra miles above its own threshold.',
					'default'           => '0.75',
					'desc_tip'          => true,
				);

				$fields[ $prefix . 'distance_multiplier' ] = array(
					'title'             => $service['label'] . ' — Distance multiplier',
					'type'              => 'number',
					'description'       => 'Use 1.00 for no change.',
					'default'           => '1.00',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				);

				$fields[ $prefix . 'quantity_multiplier' ] = array(
					'title'             => $service['label'] . ' — Quantity multiplier',
					'type'              => 'number',
					'description'       => 'Use 1.00 for no additional quantity multiplier.',
					'default'           => '1.00',
					'desc_tip'          => true,
					'custom_attributes' => array(
						'min'  => '0',
						'step' => '0.01',
					),
				);

				$fields[ $prefix . 'min_price' ] = array(
					'title'       => $service['label'] . ' — Minimum price',
					'type'        => 'price',
					'description' => 'Set to 0 to disable.',
					'default'     => '0',
					'desc_tip'    => true,
				);

				$fields[ $prefix . 'max_price' ] = array(
					'title'       => $service['label'] . ' — Maximum price',
					'type'        => 'price',
					'description' => 'Set to 0 to disable.',
					'default'     => '0',
					'desc_tip'    => true,
				);

				$fields[ $prefix . 'formula' ] = array(
					'title'       => $service['label'] . ' — Formula',
					'type'        => 'select',
					'description' => 'Used when Pricing Mode is Separate Formula.',
					'default'     => 'base_plus_extra',
					'options'     => array(
						'base_plus_extra' => 'Base Price + (Extra Miles × Extra Mile Price)',
						'base_plus_extra_distance' => '(Base Price + Extra Mileage) × Distance Multiplier',
						'base_times_distance' => 'Base Price × Distance Multiplier + Extra Mileage',
						'distance_threshold_discount' => 'Formula 4: Threshold Discount + Extra Mileage',
						'distance_only' => '(Extra Miles × Extra Mile Price) × Distance Multiplier',
					),
					'desc_tip'    => true,
				);
			}

			$this->instance_form_fields = $fields;
		}

		public function sanitize_decimal( $value ) {
			return wc_format_decimal( $value, 4 );
		}

		/*
		|--------------------------------------------------------------------------
		| AVAILABILITY
		|--------------------------------------------------------------------------
		*/

		private function get_geoapify_api_key() {

			$instance_key = trim( (string) $this->get_instance_option( 'api_key', '' ) );

			if ( '' !== $instance_key ) {
				return $instance_key;
			}

			if ( defined( 'RR_GEOAPIFY_API_KEY' ) ) {
				return trim( (string) RR_GEOAPIFY_API_KEY );
			}

			return '';
		}

		public function is_available( $package ) {

			if ( 'yes' !== $this->enabled ) {
				return false;
			}

			$api_key = $this->get_geoapify_api_key();

			if ( '' === $api_key ) {
				rr_delivery_log( 'Geoapify API key is missing.' );
				return false;
			}

			return parent::is_available( $package );
		}

		/*
		|--------------------------------------------------------------------------
		| GET SETTING
		|--------------------------------------------------------------------------
		*/

		private function rr_decimal( $key, $default = 0 ) {
			return max(
				0,
				(float) $this->get_instance_option(
					$key,
					$default
				)
			);
		}

		/*
		|--------------------------------------------------------------------------
		| CALCULATE FORMULA
		|--------------------------------------------------------------------------
		|
		| Formula 4:
		|
		| If distance <= threshold:
		|     price = base price × (1 - discount / 100)
		|
		| If distance > threshold:
		|     price = (base price + extra mileage fee) × multiplier
		|
		| For Formula 4, extra mileage starts AFTER the threshold.
		|--------------------------------------------------------------------------
		*/

		private function calculate_formula(
			$formula,
			$base_price,
			$extra_miles,
			$extra_mile_price,
			$distance_multiplier,
			$distance,
			$threshold_miles,
			$threshold_discount
		) {

			$base_price            = max( 0, (float) $base_price );
			$extra_miles           = max( 0, (float) $extra_miles );
			$extra_mile_price      = max( 0, (float) $extra_mile_price );
			$multiplier             = max( 0, (float) $distance_multiplier );
			$distance               = max( 0, (float) $distance );
			$threshold_miles        = max( 0, (float) $threshold_miles );
			$threshold_discount     = min(
				100,
				max( 0, (float) $threshold_discount )
			);

			/*
			 * Formula 4 needs its own extra-mile calculation because
			 * its threshold is different from the standard included
			 * mileage setting.
			 */
			$threshold_extra_miles = max(
				0,
				$distance - $threshold_miles
			);

			$standard_extra_mileage_fee = $extra_miles * $extra_mile_price;

			$threshold_extra_mileage_fee =
				$threshold_extra_miles * $extra_mile_price;

			switch ( $formula ) {

				case 'base_plus_extra_distance':
					$price = (
						$base_price +
						$standard_extra_mileage_fee
					) * $multiplier;
					break;

				case 'base_times_distance':
					$price = (
						$base_price * $multiplier
					) + $standard_extra_mileage_fee;
					break;

				case 'distance_only':
					$price =
						$standard_extra_mileage_fee *
						$multiplier;
					break;

				case 'distance_threshold_discount':

					if ( $distance <= $threshold_miles ) {

						$price = $base_price * (
							1 - (
								$threshold_discount / 100
							)
						);

					} else {

						$price = (
							$base_price +
							$threshold_extra_mileage_fee
						) * $multiplier;
					}

					break;

				case 'base_plus_extra':
				default:
					$price =
						$base_price +
						$standard_extra_mileage_fee;
					break;
			}

			return max( 0, (float) $price );
		}

		/*
		|--------------------------------------------------------------------------
		| APPLY MIN / MAX
		|--------------------------------------------------------------------------
		*/

		private function apply_price_limits(
			$price,
			$minimum,
			$maximum
		) {

			$price = (float) $price;

			if ( $minimum > 0 ) {
				$price = max(
					$price,
					$minimum
				);
			}

			if ( $maximum > 0 ) {
				$price = min(
					$price,
					$maximum
				);
			}

			return round(
				max( 0, $price ),
				2
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PRICING SETTINGS FOR ONE SERVICE
		|--------------------------------------------------------------------------
		*/

		private function get_pricing_settings(
			$service_key,
			$service,
			$pricing_mode
		) {

			if ( 'separate' === $pricing_mode ) {

				$prefix = 'service_' . $service_key . '_';

				return array(
					'base_price'          => $this->rr_decimal( $prefix . 'base_price', $service['base'] ),
					'included_miles'      => $this->rr_decimal( $prefix . 'included_miles', RR_INCLUDED_MILES ),
					'extra_mile_price'    => $this->rr_decimal( $prefix . 'extra_mile_price', RR_EXTRA_MILE_PRICE ),
					'distance_multiplier' => $this->rr_decimal( $prefix . 'distance_multiplier', 1 ),
					'threshold_miles'     => $this->rr_decimal( $prefix . 'threshold_miles', RR_THRESHOLD_MILES ),
					'threshold_discount'  => $this->rr_decimal( $prefix . 'threshold_discount', RR_THRESHOLD_DISCOUNT ),
					'quantity_multiplier' => $this->rr_decimal( $prefix . 'quantity_multiplier', 1 ),
					'minimum'             => $this->rr_decimal( $prefix . 'min_price', 0 ),
					'maximum'             => $this->rr_decimal( $prefix . 'max_price', 0 ),
					'formula'             => $this->get_instance_option( $prefix . 'formula', 'base_plus_extra' ),
				);
			}

			return array(
				'base_price'          => $this->rr_decimal( 'global_base_price', 25 ),
				'included_miles'      => $this->rr_decimal( 'global_included_miles', RR_INCLUDED_MILES ),
				'extra_mile_price'    => $this->rr_decimal( 'global_extra_mile_price', RR_EXTRA_MILE_PRICE ),
				'distance_multiplier' => $this->rr_decimal( 'global_distance_multiplier', 1 ),
				'threshold_miles'     => $this->rr_decimal( 'global_threshold_miles', RR_THRESHOLD_MILES ),
				'threshold_discount'  => $this->rr_decimal( 'global_threshold_discount', RR_THRESHOLD_DISCOUNT ),
				'quantity_multiplier' => $this->rr_decimal( 'global_quantity_multiplier', 1 ),
				'minimum'             => $this->rr_decimal( 'global_min_price', 0 ),
				'maximum'             => $this->rr_decimal( 'global_max_price', 0 ),
				'formula'             => $this->get_instance_option( 'global_formula', 'base_plus_extra' ),
			);
		}

		/*
		|--------------------------------------------------------------------------
		| CALCULATE SHIPPING
		|--------------------------------------------------------------------------
		|
		| 1. Pickup (billing) and drop-off (shipping) must be in the same
		|    state and country, otherwise no rates are returned.
		| 2. At or beyond the custom-quote distance (default 20 miles), or
		|    above the maximum package count (default 100), ONLY a
		|    "Custom Quote Required" option is shown.
		| 3. Otherwise every service is priced. Services flagged 'quote'
		|    (Multiple Deliveries) always show as Custom Quote Required.
		| 4. Price = formula price (+ quantity mode)
		|            + fee for each package above the included limit
		|            + Saturday surcharge (Saturday Delivery only).
		|--------------------------------------------------------------------------
		*/

		public function calculate_shipping(
			$package = array()
		) {

			$pickup_address = rr_get_pickup_address();

			$dropoff_address = rr_get_dropoff_address(
				$package
			);

			if (
				empty( $pickup_address ) ||
				empty( $dropoff_address )
			) {

				rr_delivery_log(
					'Waiting for both pickup and drop-off addresses.',
					array(
						'pickup'  => $pickup_address,
						'dropoff' => $dropoff_address,
					)
				);

				return;
			}

			/*
			 * Reject routes between different states or countries.
			 */
			$pickup_region  = rr_get_pickup_region();
			$dropoff_region = rr_get_dropoff_region( $package );

			if (
				rr_regions_differ(
					$pickup_region['state'],
					$pickup_region['country'],
					$dropoff_region['state'],
					$dropoff_region['country']
				)
			) {

				rr_delivery_log(
					'Route rejected: different state or country.',
					array(
						'pickup'  => $pickup_region,
						'dropoff' => $dropoff_region,
					)
				);

				return;
			}

			$api_key = $this->get_geoapify_api_key();

			if ( '' === $api_key ) {
				rr_delivery_log( 'Geoapify API key is missing during shipping calculation.' );
				return;
			}

			$route = rr_get_road_distance(
				$pickup_address,
				$dropoff_address,
				$api_key
			);

			if ( is_wp_error( $route ) ) {

				rr_delivery_log(
					'Route calculation failed:',
					array(
						'code'    => $route->get_error_code(),
						'message' => $route->get_error_message(),
						'pickup'  => $pickup_address,
						'dropoff' => $dropoff_address,
					)
				);

				return;
			}

			$distance_miles = (float) $route['distance_miles'];
			$quantity       = rr_get_cart_quantity();

			$common_meta = array(
				'distance_miles'  => $distance_miles,
				'quantity'        => $quantity,
				'pickup_address'  => $pickup_address,
				'dropoff_address' => $dropoff_address,
			);

			/*
			 * Custom quote: long distance or too many packages.
			 */
			$custom_quote_miles = $this->rr_decimal(
				'custom_quote_miles',
				RR_CUSTOM_QUOTE_MILES
			);

			$included_packages = (int) $this->rr_decimal(
				'included_packages',
				RR_INCLUDED_PACKAGES
			);

			$max_packages = max(
				$included_packages,
				(int) $this->rr_decimal(
					'max_packages',
					RR_MAX_PACKAGES
				)
			);

			if (
				$custom_quote_miles > 0 &&
				$distance_miles >= $custom_quote_miles
			) {

				$this->add_rr_quote_rate(
					'custom_quote',
					'Custom Quote Required',
					'Custom Quote Required',
					'distance',
					$package,
					$common_meta
				);

				return;
			}

			if ( $quantity > $max_packages ) {

				$this->add_rr_quote_rate(
					'custom_quote',
					'Custom Quote Required',
					'Custom Quote Required',
					'packages',
					$package,
					$common_meta
				);

				return;
			}

			/*
			 * Package add-on fee and Saturday surcharge.
			 */
			$pricing_mode = $this->get_instance_option(
				'pricing_mode',
				'global'
			);

			$quantity_mode = $this->get_instance_option(
				'quantity_mode',
				'none'
			);

			$extra_packages    = max( 0, $quantity - $included_packages );
			$package_fee_each  = 0.0;
			$package_fee_total = 0.0;

			if (
				'yes' === $this->get_instance_option(
					'package_fee_enabled',
					'yes'
				)
			) {

				$package_fee_each = min(
					RR_MAX_PACKAGE_FEE,
					max(
						RR_MIN_PACKAGE_FEE,
						$this->rr_decimal(
							'package_fee',
							RR_PACKAGE_FEE
						)
					)
				);

				$package_fee_total = round(
					$extra_packages * $package_fee_each,
					2
				);
			}

			$saturday_surcharge_setting = $this->rr_decimal(
				'saturday_surcharge',
				0
			);

			foreach ( rr_delivery_services() as $service_key => $service ) {

				if ( ! empty( $service['quote'] ) ) {

					$this->add_rr_quote_rate(
						$service_key,
						$service['label'] . ' — Custom Quote Required',
						$service['label'],
						'service',
						$package,
						$common_meta
					);

					continue;
				}

				$settings = $this->get_pricing_settings(
					$service_key,
					$service,
					$pricing_mode
				);

				$extra_miles = max(
					0,
					$distance_miles - $settings['included_miles']
				);

				/*
				 * Formula 4 charges extra miles from its own threshold,
				 * so record the mileage that was actually charged.
				 */
				if ( 'distance_threshold_discount' === $settings['formula'] ) {
					$extra_miles = max(
						0,
						$distance_miles - $settings['threshold_miles']
					);
				}

				$extra_mileage_fee = round(
					$extra_miles * $settings['extra_mile_price'],
					2
				);

				$calculated_price = $this->calculate_formula(
					$settings['formula'],
					$settings['base_price'],
					max( 0, $distance_miles - $settings['included_miles'] ),
					$settings['extra_mile_price'],
					$settings['distance_multiplier'],
					$distance_miles,
					$settings['threshold_miles'],
					$settings['threshold_discount']
				);

				$calculated_price = $this->apply_price_limits(
					$calculated_price,
					$settings['minimum'],
					$settings['maximum']
				);

				$service_price = $this->apply_quantity(
					$calculated_price,
					$quantity,
					$quantity_mode,
					$settings['quantity_multiplier']
				);

				$saturday_surcharge = (
					'saturday_delivery' === $service_key
				) ? $saturday_surcharge_setting : 0.0;

				$final_price = round(
					$service_price +
					$package_fee_total +
					$saturday_surcharge,
					2
				);

				$this->add_rr_rate(
					$service_key,
					$service['label'],
					$final_price,
					$package,
					array_merge(
						$common_meta,
						array(
							'base_price'          => $settings['base_price'],
							'included_miles'      => $settings['included_miles'],
							'extra_miles'         => $extra_miles,
							'extra_mile_price'    => $settings['extra_mile_price'],
							'extra_mileage_fee'   => $extra_mileage_fee,
							'distance_multiplier' => $settings['distance_multiplier'],
							'threshold_miles'     => $settings['threshold_miles'],
							'threshold_discount'  => $settings['threshold_discount'],
							'quantity_multiplier' => $settings['quantity_multiplier'],
							'formula'             => $settings['formula'],
							'pricing_mode'        => ( 'separate' === $pricing_mode ) ? 'separate' : 'global',
							'included_packages'   => $included_packages,
							'extra_packages'      => $extra_packages,
							'package_fee_each'    => $package_fee_each,
							'package_fee_total'   => $package_fee_total,
							'saturday_surcharge'  => $saturday_surcharge,
							'final_price'         => $final_price,
						)
					)
				);
			}
		}

		/*
		|--------------------------------------------------------------------------
		| QUANTITY
		|--------------------------------------------------------------------------
		*/

		private function apply_quantity(
			$price,
			$quantity,
			$quantity_mode,
			$quantity_multiplier
		) {

			$price = (float) $price;
			$quantity = max(
				1,
				absint( $quantity )
			);

			$quantity_multiplier = max(
				0,
				(float) $quantity_multiplier
			);

			switch ( $quantity_mode ) {

				case 'multiply_final':
					$price = $price * (
						1 + (
							( $quantity - 1 ) *
							$quantity_multiplier
						)
					);
					break;

				case 'add_per_quantity':
					$price =
						$price *
						$quantity *
						$quantity_multiplier;
					break;

				case 'none':
				default:
					break;
			}

			return round(
				max( 0, $price ),
				2
			);
		}

		/*
		|--------------------------------------------------------------------------
		| ADD RATE
		|--------------------------------------------------------------------------
		*/

		private function add_rr_rate(
			$service_key,
			$service_name,
			$final_price,
			$package,
			$meta
		) {

			$money = function ( $value ) {
				return number_format( (float) $value, 2, '.', '' );
			};

			$get = function ( $key, $default = 0 ) use ( $meta ) {
				return isset( $meta[ $key ] ) ? $meta[ $key ] : $default;
			};

			$this->add_rate(
				array(
					'id'       => $this->get_rate_id( $service_key ),
					'label'    => $service_name,
					'cost'     => $final_price,
					'calc_tax' => 'per_order',
					'package'  => $package,

					'meta_data' => array(
						'rr_service_key'          => $service_key,
						'rr_service_name'         => $service_name,
						'rr_custom_quote'         => 'no',
						'rr_base_price'           => $money( $get( 'base_price' ) ),
						'rr_road_distance_miles'  => $money( $get( 'distance_miles' ) ),
						'rr_included_miles'       => $money( $get( 'included_miles' ) ),
						'rr_threshold_miles'      => $money( $get( 'threshold_miles' ) ),
						'rr_threshold_discount'   => $money( $get( 'threshold_discount' ) ),
						'rr_extra_miles'          => $money( $get( 'extra_miles' ) ),
						'rr_price_per_extra_mile' => $money( $get( 'extra_mile_price' ) ),
						'rr_extra_mileage_fee'    => $money( $get( 'extra_mileage_fee' ) ),
						'rr_distance_multiplier'  => $money( $get( 'distance_multiplier' ) ),
						'rr_quantity'             => absint( $get( 'quantity', 1 ) ),
						'rr_package_count'        => absint( $get( 'quantity', 1 ) ),
						'rr_included_packages'    => absint( $get( 'included_packages' ) ),
						'rr_extra_packages'       => absint( $get( 'extra_packages' ) ),
						'rr_package_fee_each'     => $money( $get( 'package_fee_each' ) ),
						'rr_package_fee_total'    => $money( $get( 'package_fee_total' ) ),
						'rr_saturday_surcharge'   => $money( $get( 'saturday_surcharge' ) ),
						'rr_formula'              => $get( 'formula', '' ),
						'rr_pricing_mode'         => $get( 'pricing_mode', '' ),
						'rr_final_price'          => $money( $get( 'final_price' ) ),
						'rr_pickup_address'       => $get( 'pickup_address', '' ),
						'rr_dropoff_address'      => $get( 'dropoff_address', '' ),
					),
				)
			);
		}

		/*
		|--------------------------------------------------------------------------
		| ADD CUSTOM QUOTE RATE
		|--------------------------------------------------------------------------
		| Cost is 0. The administrator quotes the price after the order.
		| $reason: distance | packages | service
		|--------------------------------------------------------------------------
		*/

		private function add_rr_quote_rate(
			$service_key,
			$label,
			$service_name,
			$reason,
			$package,
			$meta
		) {

			$this->add_rate(
				array(
					'id'       => $this->get_rate_id( $service_key ),
					'label'    => $label,
					'cost'     => 0,
					'calc_tax' => 'per_order',
					'package'  => $package,

					'meta_data' => array(
						'rr_service_key'         => $service_key,
						'rr_service_name'        => $service_name,
						'rr_custom_quote'        => 'yes',
						'rr_quote_reason'        => $reason,
						'rr_road_distance_miles' => number_format( (float) $meta['distance_miles'], 2, '.', '' ),
						'rr_quantity'            => absint( $meta['quantity'] ),
						'rr_package_count'       => absint( $meta['quantity'] ),
						'rr_pickup_address'      => $meta['pickup_address'],
						'rr_dropoff_address'     => $meta['dropoff_address'],
					),
				)
			);
		}
	}
}

/*
|--------------------------------------------------------------------------
| REGISTER SHIPPING METHOD
|--------------------------------------------------------------------------
*/

add_filter(
	'woocommerce_shipping_methods',
	'rr_add_shipping_method'
);

function rr_add_shipping_method( $methods ) {

	$methods['rr_delivery_shipping'] =
		'RR_Delivery_Shipping_Method';

	return $methods;
}

/*
|--------------------------------------------------------------------------
| PICKUP ADDRESS IN SHIPPING PACKAGE
|--------------------------------------------------------------------------
| WooCommerce caches shipping rates by a hash of the package. The billing
| (pickup) address is not normally part of it, so changing only the billing
| address would show stale prices. Adding it to the package fixes that.
|--------------------------------------------------------------------------
*/

add_filter(
	'woocommerce_cart_shipping_packages',
	'rr_add_pickup_to_shipping_packages'
);

function rr_add_pickup_to_shipping_packages( $packages ) {

	$pickup = rr_get_pickup_address();

	foreach ( $packages as $key => $package ) {
		$packages[ $key ]['rr_pickup_address'] = $pickup;
	}

	return $packages;
}

/*
|--------------------------------------------------------------------------
| CUSTOMER MESSAGES
|--------------------------------------------------------------------------
*/

add_filter(
	'woocommerce_cart_no_shipping_available_html',
	'rr_no_shipping_region_message'
);

add_filter(
	'woocommerce_no_shipping_available_html',
	'rr_no_shipping_region_message'
);

function rr_no_shipping_region_message( $html ) {

	if ( rr_current_regions_differ() ) {
		return wpautop(
			esc_html(
				'Road Runner delivery is not available between different states or countries. Please make sure the billing (pickup) and shipping (drop-off) addresses are in the same state.'
			)
		);
	}

	return $html;
}

/*
 * Custom quote options show only their label, not "$0.00" or "Free".
 */
add_filter(
	'woocommerce_cart_shipping_method_full_label',
	'rr_custom_quote_shipping_label',
	10,
	2
);

function rr_custom_quote_shipping_label( $label, $method ) {

	if ( ! $method instanceof WC_Shipping_Rate ) {
		return $label;
	}

	$meta = $method->get_meta_data();

	if (
		isset( $meta['rr_custom_quote'] ) &&
		'yes' === $meta['rr_custom_quote']
	) {
		return $method->get_label();
	}

	return $label;
}

/*
|--------------------------------------------------------------------------
| SAVE ORDER INFORMATION
|--------------------------------------------------------------------------
*/

add_action(
	'woocommerce_checkout_order_processed',
	'rr_save_delivery_order_information',
	20,
	3
);

function rr_save_delivery_order_information(
	$order_id,
	$posted_data,
	$order
) {

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order ) {
		return;
	}

	$shipping_items = $order->get_items( 'shipping' );

	if ( empty( $shipping_items ) ) {
		return;
	}

	foreach ( $shipping_items as $shipping_item ) {

		if (
			'rr_delivery_shipping' !==
			$shipping_item->get_method_id()
		) {
			continue;
		}

		$service_name = $shipping_item->get_meta(
			'rr_service_name',
			true
		);

		if ( empty( $service_name ) ) {
			$service_name =
				$shipping_item->get_name();
		}

		$order->update_meta_data(
			'_rr_delivery_service',
			$service_name
		);

		$distance = $shipping_item->get_meta(
			'rr_road_distance_miles',
			true
		);

		if ( '' !== $distance ) {
			$order->update_meta_data(
				'_rr_delivery_distance_miles',
				$distance
			);
		}

		$base_price = $shipping_item->get_meta(
			'rr_base_price',
			true
		);

		if ( '' !== $base_price ) {
			$order->update_meta_data(
				'_rr_delivery_base_price',
				$base_price
			);
		}

		$extra_fee = $shipping_item->get_meta(
			'rr_extra_mileage_fee',
			true
		);

		if ( '' !== $extra_fee ) {
			$order->update_meta_data(
				'_rr_delivery_extra_mileage_fee',
				$extra_fee
			);
		}

		$threshold = $shipping_item->get_meta(
			'rr_threshold_miles',
			true
		);

		if ( '' !== $threshold ) {
			$order->update_meta_data(
				'_rr_delivery_threshold_miles',
				$threshold
			);
		}

		$threshold_discount =
			$shipping_item->get_meta(
				'rr_threshold_discount',
				true
			);

		if ( '' !== $threshold_discount ) {
			$order->update_meta_data(
				'_rr_delivery_threshold_discount',
				$threshold_discount
			);
		}

		$pickup_address = $shipping_item->get_meta(
			'rr_pickup_address',
			true
		);

		if ( ! empty( $pickup_address ) ) {
			$order->update_meta_data(
				'_rr_pickup_address',
				$pickup_address
			);
		}

		$dropoff_address = $shipping_item->get_meta(
			'rr_dropoff_address',
			true
		);

		if ( ! empty( $dropoff_address ) ) {
			$order->update_meta_data(
				'_rr_dropoff_address',
				$dropoff_address
			);
		}

		$order->update_meta_data(
			'_rr_delivery_quantity',
			$shipping_item->get_meta(
				'rr_quantity',
				true
			)
		);

		$order->update_meta_data(
			'_rr_delivery_formula',
			$shipping_item->get_meta(
				'rr_formula',
				true
			)
		);

		$extra_order_meta = array(
			'rr_package_count'      => '_rr_package_count',
			'rr_included_packages'  => '_rr_included_packages',
			'rr_extra_packages'     => '_rr_extra_packages',
			'rr_package_fee_each'   => '_rr_package_fee_each',
			'rr_package_fee_total'  => '_rr_package_fee_total',
			'rr_saturday_surcharge' => '_rr_saturday_surcharge',
			'rr_custom_quote'       => '_rr_custom_quote',
			'rr_quote_reason'       => '_rr_quote_reason',
		);

		foreach ( $extra_order_meta as $item_key => $order_key ) {

			$value = $shipping_item->get_meta( $item_key, true );

			if ( '' !== $value ) {
				$order->update_meta_data( $order_key, $value );
			}
		}

		$order->save();

		if ( 'yes' === $shipping_item->get_meta( 'rr_custom_quote', true ) ) {

			$reasons = array(
				'distance' => 'distance is at or beyond the custom-quote limit',
				'packages' => 'package count is above the maximum',
				'service'  => 'selected service requires a custom quote',
			);

			$reason_key = $shipping_item->get_meta( 'rr_quote_reason', true );

			$order->add_order_note(
				'Road Runner Delivery: CUSTOM QUOTE REQUIRED (' .
				( isset( $reasons[ $reason_key ] ) ? $reasons[ $reason_key ] : 'custom quote' ) .
				'). Contact the customer with a delivery price.'
			);
		}

		break;
	}
}

/*
|--------------------------------------------------------------------------
| DISPLAY INFORMATION IN ADMIN ORDER
|--------------------------------------------------------------------------
*/

add_action(
	'woocommerce_admin_order_data_after_shipping_address',
	'rr_display_delivery_order_information'
);

function rr_display_delivery_order_information(
	$order
) {

	$service = $order->get_meta(
		'_rr_delivery_service'
	);

	$distance = $order->get_meta(
		'_rr_delivery_distance_miles'
	);

	$base_price = $order->get_meta(
		'_rr_delivery_base_price'
	);

	$extra_fee = $order->get_meta(
		'_rr_delivery_extra_mileage_fee'
	);

	$threshold = $order->get_meta(
		'_rr_delivery_threshold_miles'
	);

	$threshold_discount =
		$order->get_meta(
			'_rr_delivery_threshold_discount'
		);

	$quantity = $order->get_meta(
		'_rr_delivery_quantity'
	);

	$formula = $order->get_meta(
		'_rr_delivery_formula'
	);

	$custom_quote = $order->get_meta( '_rr_custom_quote' );
	$quote_reason = $order->get_meta( '_rr_quote_reason' );
	$extra_pkgs   = $order->get_meta( '_rr_extra_packages' );
	$pkg_fee_each = $order->get_meta( '_rr_package_fee_each' );
	$pkg_fee_tot  = $order->get_meta( '_rr_package_fee_total' );
	$sat_sur      = $order->get_meta( '_rr_saturday_surcharge' );

	if (
		empty( $service ) &&
		empty( $distance )
	) {
		return;
	}

	echo '<div class="rr-delivery-order-information">';
	echo '<h4>Road Runner Delivery</h4>';

	if ( 'yes' === $custom_quote ) {
		echo '<p style="color:#b32d2e;"><strong>CUSTOM QUOTE REQUIRED</strong>';

		if ( ! empty( $quote_reason ) ) {
			echo ' (' . esc_html( $quote_reason ) . ')';
		}

		echo ' — contact the customer with a price.</p>';
	}

	if ( ! empty( $service ) ) {
		echo '<p><strong>Service:</strong> ';
		echo esc_html( $service );
		echo '</p>';
	}

	if (
		'' !== $distance &&
		null !== $distance
	) {
		echo '<p><strong>Road distance:</strong> ';
		echo esc_html( $distance );
		echo ' miles</p>';
	}

	if (
		'' !== $base_price &&
		null !== $base_price
	) {
		echo '<p><strong>Base price:</strong> $';
		echo esc_html( $base_price );
		echo '</p>';
	}

	if (
		'' !== $threshold &&
		null !== $threshold
	) {
		echo '<p><strong>Basic-price threshold:</strong> ';
		echo esc_html( $threshold );
		echo ' miles</p>';
	}

	if (
		'' !== $threshold_discount &&
		null !== $threshold_discount
	) {
		echo '<p><strong>Threshold discount:</strong> ';
		echo esc_html( $threshold_discount );
		echo '%</p>';
	}

	if (
		'' !== $extra_fee &&
		null !== $extra_fee
	) {
		echo '<p><strong>Additional mileage:</strong> $';
		echo esc_html( $extra_fee );
		echo '</p>';
	}

	if (
		'' !== $quantity &&
		null !== $quantity
	) {
		echo '<p><strong>Quantity:</strong> ';
		echo esc_html( $quantity );
		echo '</p>';
	}

	if ( ! empty( $formula ) ) {
		echo '<p><strong>Formula:</strong> ';
		echo esc_html( $formula );
		echo '</p>';
	}

	if ( '' !== $extra_pkgs && null !== $extra_pkgs && (float) $extra_pkgs > 0 ) {
		echo '<p><strong>Additional packages:</strong> ';
		echo esc_html( $extra_pkgs );
		echo ' × $' . esc_html( $pkg_fee_each );
		echo ' = $' . esc_html( $pkg_fee_tot );
		echo '</p>';
	}

	if ( '' !== $sat_sur && null !== $sat_sur && (float) $sat_sur > 0 ) {
		echo '<p><strong>Saturday surcharge:</strong> $';
		echo esc_html( $sat_sur );
		echo '</p>';
	}

	echo '</div>';
}

/*
|--------------------------------------------------------------------------
| WOOCOMMERCE MISSING NOTICE
|--------------------------------------------------------------------------
*/

add_action(
	'admin_notices',
	'rr_delivery_woocommerce_notice'
);

function rr_delivery_woocommerce_notice() {

	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error">';
	echo '<p>';
	echo '<strong>Road Runner Delivery Shipping:</strong> ';
	echo 'WooCommerce must be installed and active.';
	echo '</p>';
	echo '</div>';
}