<?php
/**
 * Plugin Name: Lithops Tariffs
 * Description: ERP-каталог тарифов: импорт JSON, табличный CRUD и REST-выдача тарифных строк для вторичных сайтов.
 * Version: 1.1.7
 * Author: Endure Route / Lithops Group
 * Text Domain: lithops-tariffs
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$_ltar_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

define( 'LTAR_VERSION', $_ltar_data['Version'] );
define( 'LTAR_PLUGIN_FILE', __FILE__ );
define( 'LTAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LTAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LTAR_BASENAME', plugin_basename( __FILE__ ) );
define( 'LTAR_OPTION_KEY', 'ltar_settings_v1' );
define( 'LTAR_CAP', 'access_lithops_tariffs' );

unset( $_ltar_data );

require_once LTAR_PLUGIN_DIR . 'includes/helpers.php';
require_once LTAR_PLUGIN_DIR . 'includes/class-ltar-db.php';
require_once LTAR_PLUGIN_DIR . 'includes/class-ltar-rest.php';
require_once LTAR_PLUGIN_DIR . 'includes/class-ltar-admin.php';

/**
 * Activation callback.
 *
 * @return void
 */
function ltar_activate() {
	$admin = get_role( 'administrator' );

	if ( $admin ) {
		$admin->add_cap( LTAR_CAP );
	}

	LTAR_DB::install();
	ltar_ensure_api_token();
}

register_activation_hook( __FILE__, 'ltar_activate' );

add_action(
	'plugins_loaded',
	function() {
		LTAR_REST::init();
		LTAR_Admin::init();
	}
);

add_filter(
	'lg_plugin_capabilities',
	function( $caps ) {
		$caps['lithops-tariffs'] = array(
			'label'       => __( 'Каталог тарифов', 'lithops-tariffs' ),
			'cap'         => LTAR_CAP,
			'description' => __( 'Импорт и управление ERP-каталогом тарифов.', 'lithops-tariffs' ),
		);

		return $caps;
	}
);
