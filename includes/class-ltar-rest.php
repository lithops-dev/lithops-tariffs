<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for secondary sites.
 */
class LTAR_REST {

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'ltar/v1',
			'/catalog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_catalog' ),
				'permission_callback' => array( __CLASS__, 'permission_catalog' ),
			)
		);
	}

	/**
	 * Validate access token.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function permission_catalog( $request ) {
		$expected = ltar_get_auth_token();
		$provided = (string) $request->get_header( 'x-leb-enrollment-token' );

		if ( '' !== $expected && '' !== $provided && hash_equals( $expected, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'ltar_forbidden',
			__( 'Enrollment token is invalid.', 'lithops-tariffs' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Return current catalog rows.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_catalog() {
		$settings = ltar_get_settings();
		$rows     = LTAR_DB::get_rows();

		return rest_ensure_response(
			array(
				'ok'               => true,
				'rows_count'       => count( $rows ),
				'catalog_endpoint' => ltar_get_endpoint_url(),
				'last_import_name' => ltar_text( $settings['last_import_name'] ?? '' ),
				'last_import_gmt'  => ltar_text( $settings['last_import_gmt'] ?? '' ),
				'rows'             => ltar_prepare_rows_for_output( $rows ),
			)
		);
	}
}
