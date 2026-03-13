<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for tariffs catalog.
 */
class LTAR_Admin {

	/**
	 * Menu slug.
	 */
	const MENU_SLUG = 'ltar-catalog';

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_ltar_import_json', array( __CLASS__, 'handle_import_json' ) );
		add_action( 'admin_post_ltar_save_row', array( __CLASS__, 'handle_save_row' ) );
		add_action( 'admin_post_ltar_delete_row', array( __CLASS__, 'handle_delete_row' ) );
	}

	/**
	 * Register admin page.
	 *
	 * @return void
	 */
	public static function menu() {
		if ( function_exists( 'lmm_add_submenu' ) ) {
			lmm_add_submenu(
				'lithops-erp',
				array(
					'page_title' => __( 'Каталог тарифов', 'lithops-tariffs' ),
					'menu_title' => __( 'Каталог тарифов', 'lithops-tariffs' ),
					'capability' => LTAR_CAP,
					'menu_slug'  => self::MENU_SLUG,
					'callback'   => array( __CLASS__, 'render_page' ),
				)
			);

			return;
		}

		add_menu_page(
			__( 'Каталог тарифов', 'lithops-tariffs' ),
			__( 'Каталог тарифов', 'lithops-tariffs' ),
			LTAR_CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-line',
			67
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public static function admin_assets( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'ltar-bootstrap',
			'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
			array(),
			'5.3.3'
		);

		wp_enqueue_style(
			'ltar-bootstrap-icons',
			'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
			array(),
			'1.11.3'
		);

		wp_enqueue_style(
			'ltar-admin',
			LTAR_PLUGIN_URL . 'assets/admin.css',
			array( 'ltar-bootstrap', 'ltar-bootstrap-icons' ),
			LTAR_VERSION
		);

		wp_enqueue_script(
			'ltar-alpine',
			'https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js',
			array(),
			'3.14.8',
			true
		);
	}

	/**
	 * Import JSON and replace catalog rows.
	 *
	 * @return void
	 */
	public static function handle_import_json() {
		if ( ! current_user_can( LTAR_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lithops-tariffs' ) );
		}

		check_admin_referer( 'ltar_import_json' );

		$file = $_FILES['ltar_json_file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			self::redirect_with_notice( 'import_error' );
		}

		$raw     = file_get_contents( (string) $file['tmp_name'] );
		$payload = ltar_decode_json( (string) $raw );
		$rows    = ltar_rows_from_payload( $payload );

		if ( empty( $rows ) ) {
			self::redirect_with_notice( 'import_empty' );
		}

		$count    = LTAR_DB::replace_catalog( $rows );
		$settings = ltar_get_settings();

		$settings['last_import_name'] = sanitize_file_name( (string) ( $file['name'] ?? 'catalog.json' ) );
		$settings['last_import_gmt']  = gmdate( 'Y-m-d H:i:s' );
		$settings['last_import_rows'] = $count;

		update_option( LTAR_OPTION_KEY, wp_parse_args( $settings, ltar_get_default_settings() ), false );

		self::redirect_with_notice( 'imported' );
	}

	/**
	 * Save or update a single row.
	 *
	 * @return void
	 */
	public static function handle_save_row() {
		if ( ! current_user_can( LTAR_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lithops-tariffs' ) );
		}

		check_admin_referer( 'ltar_save_row' );

		$id  = isset( $_POST['ltar_row_id'] ) ? absint( wp_unslash( $_POST['ltar_row_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$row = array(
			'route_key'           => isset( $_POST['route_key'] ) ? wp_unslash( $_POST['route_key'] ) : '',
			'export_country'      => isset( $_POST['export_country'] ) ? wp_unslash( $_POST['export_country'] ) : '',
			'export_country_code' => isset( $_POST['export_country_code'] ) ? wp_unslash( $_POST['export_country_code'] ) : '',
			'export_city'         => isset( $_POST['export_city'] ) ? wp_unslash( $_POST['export_city'] ) : '',
			'import_country'      => isset( $_POST['import_country'] ) ? wp_unslash( $_POST['import_country'] ) : '',
			'import_country_code' => isset( $_POST['import_country_code'] ) ? wp_unslash( $_POST['import_country_code'] ) : '',
			'import_city'         => isset( $_POST['import_city'] ) ? wp_unslash( $_POST['import_city'] ) : '',
			'service'             => isset( $_POST['service'] ) ? wp_unslash( $_POST['service'] ) : '',
			'service_label'       => isset( $_POST['service_label'] ) ? wp_unslash( $_POST['service_label'] ) : '',
			'unit'                => isset( $_POST['unit'] ) ? wp_unslash( $_POST['unit'] ) : '',
			'currency'            => isset( $_POST['currency'] ) ? wp_unslash( $_POST['currency'] ) : '',
			'price_min'           => isset( $_POST['price_min'] ) ? wp_unslash( $_POST['price_min'] ) : '',
			'price_max'           => isset( $_POST['price_max'] ) ? wp_unslash( $_POST['price_max'] ) : '',
			'price_avg'           => isset( $_POST['price_avg'] ) ? wp_unslash( $_POST['price_avg'] ) : '',
			'transit_min_days'    => isset( $_POST['transit_min_days'] ) ? wp_unslash( $_POST['transit_min_days'] ) : '',
			'transit_max_days'    => isset( $_POST['transit_max_days'] ) ? wp_unslash( $_POST['transit_max_days'] ) : '',
			'transit_avg_days'    => isset( $_POST['transit_avg_days'] ) ? wp_unslash( $_POST['transit_avg_days'] ) : '',
			'based_on_route'      => isset( $_POST['based_on_route'] ) ? wp_unslash( $_POST['based_on_route'] ) : '',
			'based_on_scenario'   => isset( $_POST['based_on_scenario'] ) ? wp_unslash( $_POST['based_on_scenario'] ) : '',
			'reason'              => isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
			'price_source'        => isset( $_POST['price_source'] ) ? wp_unslash( $_POST['price_source'] ) : '',
			'notes'               => isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '',
		);

		LTAR_DB::save_row( $row, $id );

		self::redirect_with_notice( $id > 0 ? 'updated' : 'created' );
	}

	/**
	 * Delete a single row.
	 *
	 * @return void
	 */
	public static function handle_delete_row() {
		if ( ! current_user_can( LTAR_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'lithops-tariffs' ) );
		}

		check_admin_referer( 'ltar_delete_row' );

		$id = isset( $_POST['ltar_row_id'] ) ? absint( wp_unslash( $_POST['ltar_row_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		LTAR_DB::delete_row( $id );

		self::redirect_with_notice( 'deleted' );
	}

	/**
	 * Redirect to admin page with notice while preserving filters/pagination.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	protected static function redirect_with_notice( $notice ) {
		$notice = sanitize_key( (string) $notice );
		$url    = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'ltar_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);

		$return_to = isset( $_REQUEST['ltar_return_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['ltar_return_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$referer   = '' !== $return_to ? $return_to : wp_get_referer();

		if ( is_string( $referer ) && '' !== $referer ) {
			$query = array();
			parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );

			if ( ( $query['page'] ?? '' ) === self::MENU_SLUG ) {
				unset( $query['ltar_notice'], $query['ltar_edit'] );
				$query['page']        = self::MENU_SLUG;
				$query['ltar_notice'] = $notice;
				$url                  = add_query_arg( $query, admin_url( 'admin.php' ) );
			}
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Get notice config.
	 *
	 * @param string $notice Notice key.
	 * @return array<string,string>
	 */
	protected static function notice_config( $notice ) {
		$map = array(
			'imported'     => array( 'type' => 'success', 'text' => __( 'JSON импортирован. Каталог тарифов заменён.', 'lithops-tariffs' ) ),
			'import_empty' => array( 'type' => 'warning', 'text' => __( 'В загруженном JSON не найдено поддерживаемых строк тарифов.', 'lithops-tariffs' ) ),
			'import_error' => array( 'type' => 'danger', 'text' => __( 'Не удалось прочитать загруженный JSON-файл.', 'lithops-tariffs' ) ),
			'created'      => array( 'type' => 'success', 'text' => __( 'Строка тарифа создана.', 'lithops-tariffs' ) ),
			'updated'      => array( 'type' => 'success', 'text' => __( 'Строка тарифа обновлена.', 'lithops-tariffs' ) ),
			'deleted'      => array( 'type' => 'warning', 'text' => __( 'Строка тарифа удалена.', 'lithops-tariffs' ) ),
		);

		return $map[ $notice ] ?? array();
	}

	/**
	 * Build current page URL while preserving active filters.
	 *
	 * @param array $overrides Query overrides.
	 * @param array $remove    Keys to remove.
	 * @return string
	 */
	protected static function current_page_url( $overrides = array(), $remove = array() ) {
		$query = array(
			'page' => self::MENU_SLUG,
		);

		$allowed_keys = array(
			'ltar_search',
			'ltar_export_country',
			'ltar_export_city',
			'ltar_import_country',
			'ltar_import_city',
			'ltar_order_by',
			'ltar_order',
			'per_page',
			'paged',
			'ltar_notice',
			'ltar_edit',
		);

		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				continue;
			}

			$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $key, array( 'per_page', 'paged', 'ltar_edit' ), true ) ) {
				$value = (string) absint( $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}

			if ( '' !== $value ) {
				$query[ $key ] = $value;
			}
		}

		foreach ( (array) $remove as $key ) {
			unset( $query[ $key ] );
		}

		foreach ( (array) $overrides as $key => $value ) {
			if ( null === $value || '' === $value ) {
				unset( $query[ $key ] );
				continue;
			}

			$query[ $key ] = $value;
		}

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * Default editor state.
	 *
	 * @return array<string,string|int>
	 */
	protected static function editor_defaults() {
		return array(
			'ltar_row_id'         => 0,
			'route_key'           => '',
			'service'             => '',
			'service_label'       => '',
			'export_country_code' => '',
			'export_country'      => '',
			'export_city'         => '',
			'import_country_code' => '',
			'import_country'      => '',
			'import_city'         => '',
			'unit'                => '',
			'currency'            => 'USD',
			'price_source'        => '',
			'price_min'           => '',
			'price_max'           => '',
			'price_avg'           => '',
			'transit_min_days'    => '',
			'transit_max_days'    => '',
			'transit_avg_days'    => '',
			'based_on_scenario'   => '',
			'reason'              => '',
			'based_on_route'      => '',
			'notes'               => '',
		);
	}

	/**
	 * Convert DB row into editor payload.
	 *
	 * @param object|array $row Source row.
	 * @return array<string,string|int>
	 */
	protected static function row_to_editor_state( $row ) {
		$source   = is_object( $row ) ? get_object_vars( $row ) : ( is_array( $row ) ? $row : array() );
		$defaults = self::editor_defaults();
		$out      = $defaults;

		foreach ( $defaults as $key => $default ) {
			if ( 'ltar_row_id' === $key ) {
				$out[ $key ] = isset( $source['id'] ) ? (int) $source['id'] : ( isset( $source[ $key ] ) ? (int) $source[ $key ] : 0 );
				continue;
			}

			$out[ $key ] = isset( $source[ $key ] ) && null !== $source[ $key ]
				? (string) $source[ $key ]
				: (string) $default;
		}

		return $out;
	}

	/**
	 * Build pagination links.
	 *
	 * @param int $current Current page.
	 * @param int $total   Total pages.
	 * @return array<int,string>
	 */
	protected static function pagination_links( $current, $total ) {
		$current = max( 1, (int) $current );
		$total   = max( 1, (int) $total );

		if ( $total <= 1 ) {
			return array();
		}

		$big   = 999999999;
		$base  = str_replace( $big, '%#%', esc_url_raw( self::current_page_url( array( 'paged' => $big ), array( 'ltar_notice', 'ltar_edit' ) ) ) );
		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => $current,
				'total'     => $total,
				'type'      => 'array',
				'prev_text' => __( 'Назад', 'lithops-tariffs' ),
				'next_text' => __( 'Вперёд', 'lithops-tariffs' ),
				'mid_size'  => 1,
				'end_size'  => 1,
			)
		);

		return is_array( $links ) ? $links : array();
	}

	/**
	 * Whitelist sortable numeric fields.
	 *
	 * @param string $field Raw field.
	 * @return string
	 */
	protected static function sanitize_sort_field( $field ) {
		$field   = sanitize_key( (string) $field );
		$allowed = array(
			'price_min',
			'price_max',
			'price_avg',
			'transit_min_days',
			'transit_max_days',
			'transit_avg_days',
		);

		return in_array( $field, $allowed, true ) ? $field : '';
	}

	/**
	 * Normalize sort direction.
	 *
	 * @param string $direction Raw direction.
	 * @return string
	 */
	protected static function sanitize_sort_direction( $direction ) {
		return 'desc' === strtolower( trim( (string) $direction ) ) ? 'desc' : 'asc';
	}

	/**
	 * Format money value for the table.
	 *
	 * @param mixed  $value    Value.
	 * @param string $currency Currency.
	 * @return string
	 */
	protected static function format_money_value( $value, $currency ) {
		if ( '' === trim( (string) $value ) ) {
			return '—';
		}

		$prefix = '' !== trim( (string) $currency ) ? trim( (string) $currency ) . ' ' : '';

		return $prefix . trim( (string) $value );
	}

	/**
	 * Format transit value for the table.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected static function format_days_value( $value ) {
		if ( '' === trim( (string) $value ) ) {
			return '—';
		}

		return trim( (string) $value );
	}

	/**
	 * Render sort controls for one table column.
	 *
	 * @param string $label            Header label.
	 * @param string $field            Sort field.
	 * @param string $active_order_by  Active sort field.
	 * @param string $active_order     Active sort direction.
	 * @return void
	 */
	protected static function render_sort_header( $label, $field, $active_order_by, $active_order ) {
		$label           = (string) $label;
		$field           = self::sanitize_sort_field( $field );
		$active_order_by = self::sanitize_sort_field( $active_order_by );
		$active_order    = self::sanitize_sort_direction( $active_order );

		if ( '' === $field ) {
			echo esc_html( $label );
			return;
		}

		$asc_url  = self::current_page_url(
			array(
				'ltar_order_by' => $field,
				'ltar_order'    => 'asc',
				'paged'         => 1,
			),
			array( 'ltar_notice', 'ltar_edit' )
		);
		$desc_url = self::current_page_url(
			array(
				'ltar_order_by' => $field,
				'ltar_order'    => 'desc',
				'paged'         => 1,
			),
			array( 'ltar_notice', 'ltar_edit' )
		);
		?>
		<div class="ltar-sortable-head">
			<span><?php echo esc_html( $label ); ?></span>
			<span class="ltar-sort-links">
				<a class="ltar-sort-link<?php echo $active_order_by === $field && 'asc' === $active_order ? ' is-active' : ''; ?>" href="<?php echo esc_url( $asc_url ); ?>" aria-label="<?php echo esc_attr( $label . ' ↑' ); ?>">↑</a>
				<a class="ltar-sort-link<?php echo $active_order_by === $field && 'desc' === $active_order ? ' is-active' : ''; ?>" href="<?php echo esc_url( $desc_url ); ?>" aria-label="<?php echo esc_attr( $label . ' ↓' ); ?>">↓</a>
			</span>
		</div>
		<?php
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( LTAR_CAP ) ) {
			return;
		}

		$settings      = ltar_get_settings();
		$stats         = LTAR_DB::get_stats();
		$search        = isset( $_GET['ltar_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ltar_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_country = isset( $_GET['ltar_export_country'] ) ? sanitize_text_field( wp_unslash( $_GET['ltar_export_country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_city   = isset( $_GET['ltar_export_city'] ) ? sanitize_text_field( wp_unslash( $_GET['ltar_export_city'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$import_country = isset( $_GET['ltar_import_country'] ) ? sanitize_text_field( wp_unslash( $_GET['ltar_import_country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$import_city   = isset( $_GET['ltar_import_city'] ) ? sanitize_text_field( wp_unslash( $_GET['ltar_import_city'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_by      = self::sanitize_sort_field( isset( $_GET['ltar_order_by'] ) ? wp_unslash( $_GET['ltar_order_by'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order         = self::sanitize_sort_direction( isset( $_GET['ltar_order'] ) ? wp_unslash( $_GET['ltar_order'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page      = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page      = in_array( $per_page, array( 25, 50, 100, 200 ), true ) ? $per_page : 50;
		$paged         = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice        = isset( $_GET['ltar_notice'] ) ? sanitize_key( wp_unslash( $_GET['ltar_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice_config = self::notice_config( $notice );
		$edit_id       = isset( $_GET['ltar_edit'] ) ? absint( wp_unslash( $_GET['ltar_edit'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_row      = $edit_id > 0 ? LTAR_DB::get_row( $edit_id ) : null;
		$endpoint      = ltar_get_endpoint_url();
		$token         = ltar_get_auth_token();
		$shared_token  = function_exists( 'lesh_ensure_enrollment_token' ) && function_exists( 'lesh_decrypt' );
		$filters       = array(
			'search'         => $search,
			'export_country' => $export_country,
			'export_city'    => $export_city,
			'import_country' => $import_country,
			'import_city'    => $import_city,
			'order_by'       => $order_by,
			'order'          => $order,
		);
		$total_rows    = LTAR_DB::count_rows( $filters );
		$total_pages   = max( 1, (int) ceil( max( 1, $total_rows ) / $per_page ) );
		$paged         = min( $paged, $total_pages );
		$offset        = ( $paged - 1 ) * $per_page;
		$rows          = LTAR_DB::get_rows(
			array_merge(
				$filters,
				array(
					'limit'  => $per_page,
					'offset' => $offset,
				)
			)
		);
		$rows_map      = array();

		foreach ( $rows as $row ) {
			$row_id              = (int) ( $row->id ?? 0 );
			$rows_map[ $row_id ] = self::row_to_editor_state( $row );
		}

		if ( $edit_id > 0 && is_object( $edit_row ) && ! isset( $rows_map[ $edit_id ] ) ) {
			$rows_map[ $edit_id ] = self::row_to_editor_state( $edit_row );
		}

		$alpine_config = array(
			'rowsMap'        => $rows_map,
			'editorDefaults' => self::editor_defaults(),
			'initialEditId'  => $edit_id,
		);
		$return_to = self::current_page_url( array(), array( 'ltar_notice', 'ltar_edit' ) );
		$reset_url = self::current_page_url( array(), array( 'ltar_search', 'ltar_export_country', 'ltar_export_city', 'ltar_import_country', 'ltar_import_city', 'ltar_order_by', 'ltar_order', 'paged', 'ltar_notice', 'ltar_edit' ) );

		self::render_page_script( $alpine_config );
		?>
		<div
			class="wrap ltar-admin-wrap"
			x-data="ltarAdminPage(<?php echo esc_attr( wp_json_encode( $alpine_config ) ); ?>)"
			x-init="init()"
			x-on:keydown.escape.window="closeEditor()"
		>
			<?php self::render_summary_header( $stats, $notice_config ); ?>
			<?php self::render_data_access_cards( $settings, $endpoint, $token, $shared_token, $return_to ); ?>
			<?php self::render_catalog_table_section( $rows, $filters, $per_page, $paged, $total_rows, $return_to, $reset_url ); ?>
			<?php self::render_editor_modal( $return_to ); ?>
		</div>
		<?php
	}

	/**
	 * Render Alpine bootstrap script.
	 *
	 * @param array $alpine_config Alpine config.
	 * @return void
	 */
	protected static function render_page_script( $alpine_config ) {
		?>
		<script>
			window.ltarAdminPage = window.ltarAdminPage || function(config) {
				return {
					copied: '',
					editorOpen: false,
					editorMode: 'create',
					editorDefaults: config.editorDefaults || {},
					rowsMap: config.rowsMap || {},
					editor: JSON.parse(JSON.stringify(config.editorDefaults || {})),
					initialEditId: parseInt(config.initialEditId || 0, 10),
					init() {
						if (this.initialEditId && this.rowsMap[this.initialEditId]) {
							this.openEditor(this.initialEditId);
						}
					},
					cloneRow(row) {
						return JSON.parse(JSON.stringify(row || this.editorDefaults || {}));
					},
					openCreate() {
						this.editorMode = 'create';
						this.editor = this.cloneRow(this.editorDefaults);
						this.editor.ltar_row_id = 0;
						this.editorOpen = true;
					},
					openEditor(id) {
						if (!this.rowsMap[id]) {
							return;
						}
						this.editorMode = 'edit';
						this.editor = this.cloneRow(this.rowsMap[id]);
						this.editor.ltar_row_id = parseInt(this.editor.ltar_row_id || id, 10) || 0;
						this.editorOpen = true;
					},
					closeEditor() {
						this.editorOpen = false;
					},
					editorTitle() {
						return this.editorMode === 'edit' ? 'Редактирование тарифа' : 'Новая строка тарифа';
					},
					copyField(field, label) {
						if (!field || !field.value) {
							return;
						}
						const onSuccess = () => {
							this.copied = label;
							setTimeout(() => { this.copied = ''; }, 2200);
						};
						if (navigator.clipboard && window.isSecureContext) {
							navigator.clipboard.writeText(field.value).then(onSuccess);
							return;
						}
						field.removeAttribute('readonly');
						field.select();
						field.setSelectionRange(0, field.value.length);
						document.execCommand('copy');
						field.setAttribute('readonly', 'readonly');
						window.getSelection().removeAllRanges();
						onSuccess();
					}
				};
			};
		</script>
		<?php
	}

	/**
	 * Render page banner, notice and metrics.
	 *
	 * @param array $stats         Dashboard stats.
	 * @param array $notice_config Notice config.
	 * @return void
	 */
	protected static function render_summary_header( $stats, $notice_config ) {
		$stats = is_array( $stats ) ? $stats : array();
		?>
		<div class="lhfe-banner">
			<div>
				<h1 class="mb-2"><?php esc_html_e( 'Каталог тарифов', 'lithops-tariffs' ); ?></h1>
				<p class="mb-0"><?php esc_html_e( 'Центральный ERP-каталог тарифов для дочерних сайтов, SEO-плейсхолдеров, превью маршрутов и fallback-резолвинга.', 'lithops-tariffs' ); ?></p>
			</div>
			<div class="lhfe-banner-meta">
				<span class="badge text-bg-light"><?php echo esc_html( 'v' . LTAR_VERSION ); ?></span>
				<span class="badge text-bg-info"><?php echo esc_html( sprintf( __( 'Строк: %d', 'lithops-tariffs' ), (int) ( $stats['total'] ?? 0 ) ) ); ?></span>
			</div>
		</div>

		<?php if ( ! empty( $notice_config ) ) : ?>
			<div class="alert alert-<?php echo esc_attr( $notice_config['type'] ); ?> lhfe-alert">
				<?php echo esc_html( $notice_config['text'] ); ?>
			</div>
		<?php endif; ?>

		<div class="row g-4 mb-4">
			<div class="col-xl-3 col-md-6">
				<div class="lhfe-card metric-card">
					<div class="metric-title"><?php esc_html_e( 'Всего строк', 'lithops-tariffs' ); ?></div>
					<div class="metric-value"><?php echo esc_html( (int) ( $stats['total'] ?? 0 ) ); ?></div>
				</div>
			</div>
			<div class="col-xl-3 col-md-6">
				<div class="lhfe-card metric-card">
					<div class="metric-title"><?php esc_html_e( 'Стран импорта', 'lithops-tariffs' ); ?></div>
					<div class="metric-value"><?php echo esc_html( (int) ( $stats['import_countries'] ?? 0 ) ); ?></div>
				</div>
			</div>
			<div class="col-xl-3 col-md-6">
				<div class="lhfe-card metric-card">
					<div class="metric-title"><?php esc_html_e( 'Стран экспорта', 'lithops-tariffs' ); ?></div>
					<div class="metric-value"><?php echo esc_html( (int) ( $stats['export_countries'] ?? 0 ) ); ?></div>
				</div>
			</div>
			<div class="col-xl-3 col-md-6">
				<div class="lhfe-card metric-card">
					<div class="metric-title"><?php esc_html_e( 'Сервисов', 'lithops-tariffs' ); ?></div>
					<div class="metric-value"><?php echo esc_html( (int) ( $stats['services'] ?? 0 ) ); ?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render import and REST cards.
	 *
	 * @param array  $settings     Plugin settings.
	 * @param string $endpoint     REST endpoint.
	 * @param string $token        Access token.
	 * @param bool   $shared_token Whether token comes from shared hub.
	 * @param string $return_to    Return URL.
	 * @return void
	 */
	protected static function render_data_access_cards( $settings, $endpoint, $token, $shared_token, $return_to ) {
		$settings = is_array( $settings ) ? $settings : array();
		?>
		<div class="row g-4 mb-4">
			<div class="col-xl-6">
				<div class="lhfe-card">
					<h2 class="h4 mb-3"><?php esc_html_e( 'Импорт JSON', 'lithops-tariffs' ); ?></h2>
					<p class="mb-3"><?php esc_html_e( 'Импорт полностью заменяет текущий каталог. Поддерживаются как плоские массивы строк, так и структурированные тарифные payload.', 'lithops-tariffs' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'ltar_import_json' ); ?>
						<input type="hidden" name="action" value="ltar_import_json">
						<input type="hidden" name="ltar_return_to" value="<?php echo esc_attr( $return_to ); ?>">
						<div class="mb-3">
							<label class="form-label" for="ltar-json-file"><?php esc_html_e( 'JSON-файл', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-json-file" type="file" name="ltar_json_file" accept=".json,application/json" required>
						</div>
						<button type="submit" class="btn btn-primary">
							<i class="bi bi-upload me-1"></i><?php esc_html_e( 'Импортировать и заменить каталог', 'lithops-tariffs' ); ?>
						</button>
					</form>
					<ul class="list-unstyled mt-4 mb-0 ltar-summary-list">
						<li><strong><?php esc_html_e( 'Последний файл:', 'lithops-tariffs' ); ?></strong> <?php echo esc_html( ! empty( $settings['last_import_name'] ) ? $settings['last_import_name'] : __( 'не было', 'lithops-tariffs' ) ); ?></li>
						<li><strong><?php esc_html_e( 'Последний импорт:', 'lithops-tariffs' ); ?></strong> <?php echo esc_html( ! empty( $settings['last_import_gmt'] ) ? get_date_from_gmt( $settings['last_import_gmt'], 'd.m.Y H:i' ) : __( 'никогда', 'lithops-tariffs' ) ); ?></li>
						<li><strong><?php esc_html_e( 'Строк после импорта:', 'lithops-tariffs' ); ?></strong> <?php echo esc_html( (int) ( $settings['last_import_rows'] ?? 0 ) ); ?></li>
					</ul>
				</div>
			</div>
			<div class="col-xl-6">
				<div class="lhfe-card">
					<h2 class="h4 mb-3"><?php esc_html_e( 'REST-доступ для дочерних сайтов', 'lithops-tariffs' ); ?></h2>
					<p><?php esc_html_e( 'Дочерние сайты получают ERP-каталог через bridge-слой. Также они могут запрашивать одну уже resolved-строку через ERP endpoint.', 'lithops-tariffs' ); ?></p>
					<div class="mb-3">
						<label class="form-label" for="ltar-endpoint-copy"><?php esc_html_e( 'Endpoint', 'lithops-tariffs' ); ?></label>
						<div class="input-group">
							<input x-ref="endpointField" id="ltar-endpoint-copy" class="form-control" type="text" readonly value="<?php echo esc_attr( $endpoint ); ?>">
							<button class="btn btn-outline-secondary" type="button" @click.prevent="copyField($refs.endpointField, '<?php echo esc_attr__( 'Endpoint', 'lithops-tariffs' ); ?>')"><?php esc_html_e( 'Копировать', 'lithops-tariffs' ); ?></button>
						</div>
					</div>
					<div class="mb-3">
						<label class="form-label" for="ltar-token-copy"><?php esc_html_e( 'Токен доступа', 'lithops-tariffs' ); ?></label>
						<div class="input-group">
							<input x-ref="tokenField" id="ltar-token-copy" class="form-control" type="text" readonly value="<?php echo esc_attr( $token ); ?>">
							<button class="btn btn-outline-secondary" type="button" @click.prevent="copyField($refs.tokenField, '<?php echo esc_attr__( 'Токен доступа', 'lithops-tariffs' ); ?>')"><?php esc_html_e( 'Копировать', 'lithops-tariffs' ); ?></button>
						</div>
					</div>
					<div class="alert alert-info mb-0">
						<?php echo esc_html( $shared_token ? __( 'Используется общий enrollment token из ERP Sites Hub.', 'lithops-tariffs' ) : __( 'Используется локальный токен плагина Tariffs.', 'lithops-tariffs' ) ); ?>
						<template x-if="copied"><div class="ltar-copy-note" x-text="copied + ' скопировано'"></div></template>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render stub values block.
	 *
	 * @return void
	 */
	protected static function render_stub_values_section() {
		$stub_rows         = LTAR_DB::get_stub_rows();
		$country_only_rows = array();
		$country_pair_rows = array();

		foreach ( $stub_rows as $row ) {
			$scenario     = trim( (string) ( $row->based_on_scenario ?? '' ) );
			$price_source = trim( (string) ( $row->price_source ?? '' ) );

			if ( 'import_country_only' === $scenario || 'country_only_default' === $price_source ) {
				$country_only_rows[] = $row;
				continue;
			}

			if ( 'country_to_country' === $scenario || 'country_pair_stub' === $price_source ) {
				$country_pair_rows[] = $row;
			}
		}
		?>
		<details class="ltar-fallback-details ltar-stub-details mb-4">
			<summary><?php esc_html_e( 'Значения заглушек', 'lithops-tariffs' ); ?></summary>
			<div class="ltar-fallback-copy">
				<p><?php esc_html_e( 'Ниже показаны сами fallback-строки, которые используются resolver-ом, когда не находится точный маршрут.', 'lithops-tariffs' ); ?></p>

				<div class="ltar-stub-section">
					<h3><?php esc_html_e( 'Country-to-country заглушки', 'lithops-tariffs' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Используются, когда city-to-city или city-to-country / country-to-city не найдены, но есть пара стран экспорта и импорта.', 'lithops-tariffs' ); ?></p>
					<?php self::render_stub_table( $country_pair_rows, false ); ?>
				</div>

				<div class="ltar-stub-section">
					<h3><?php esc_html_e( 'Import-country-only заглушки', 'lithops-tariffs' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Используются как последний fallback по стране импорта, если точного маршрута и country-to-country заглушки нет.', 'lithops-tariffs' ); ?></p>
					<?php self::render_stub_table( $country_only_rows, true ); ?>
				</div>
			</div>
		</details>
		<?php
	}

	/**
	 * Render one stub table.
	 *
	 * @param array $rows            Stub rows.
	 * @param bool  $country_only    Whether table is for import-country-only stubs.
	 * @return void
	 */
	protected static function render_stub_table( $rows, $country_only ) {
		$rows         = is_array( $rows ) ? $rows : array();
		$country_only = (bool) $country_only;

		if ( empty( $rows ) ) {
			?>
			<div class="text-muted"><?php esc_html_e( 'Заглушки этого типа не найдены в каталоге.', 'lithops-tariffs' ); ?></div>
			<?php
			return;
		}
		?>
		<div class="table-responsive">
			<table class="table table-sm table-striped ltar-table ltar-stub-table align-middle">
				<thead>
					<tr>
						<?php if ( ! $country_only ) : ?>
							<th><?php esc_html_e( 'Откуда', 'lithops-tariffs' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Куда', 'lithops-tariffs' ); ?></th>
						<th><?php esc_html_e( 'Сервис', 'lithops-tariffs' ); ?></th>
						<th><?php esc_html_e( 'Цена', 'lithops-tariffs' ); ?></th>
						<th><?php esc_html_e( 'Срок', 'lithops-tariffs' ); ?></th>
						<th><?php esc_html_e( 'Источник', 'lithops-tariffs' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$from_label = trim( (string) ( $row->export_country ?: $row->export_country_code ) );
						$to_label   = trim( (string) ( $row->import_country ?: $row->import_country_code ) );
						$price      = trim( (string) $row->currency ) . ' ' . trim( (string) $row->price_min ) . ' - ' . trim( (string) $row->price_max );
						$transit    = trim( (string) $row->transit_min_days ) . ' - ' . trim( (string) $row->transit_max_days );
						?>
						<tr>
							<?php if ( ! $country_only ) : ?>
								<td><?php echo esc_html( $from_label ); ?></td>
							<?php endif; ?>
							<td><?php echo esc_html( $to_label ); ?></td>
							<td>
								<strong><?php echo esc_html( (string) $row->service ); ?></strong>
								<div class="text-muted small"><?php echo esc_html( (string) $row->service_label ); ?></div>
							</td>
							<td><?php echo esc_html( $price ); ?></td>
							<td><?php echo esc_html( $transit ); ?></td>
							<td>
								<span class="badge text-bg-light"><?php echo esc_html( (string) $row->price_source ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render catalog table, filters and pagination.
	 *
	 * @param array  $rows       Current rows.
	 * @param array  $filters    Active filters.
	 * @param int    $per_page   Rows per page.
	 * @param int    $paged      Current page.
	 * @param int    $total_rows Total rows.
	 * @param string $return_to  Return URL.
	 * @param string $reset_url  Reset URL.
	 * @return void
	 */
	protected static function render_catalog_table_section( $rows, $filters, $per_page, $paged, $total_rows, $return_to, $reset_url ) {
		$rows             = is_array( $rows ) ? $rows : array();
		$filters          = is_array( $filters ) ? $filters : array();
		$per_page         = (int) $per_page;
		$paged            = (int) $paged;
		$total_rows       = (int) $total_rows;
		$shown_from       = empty( $rows ) ? 0 : ( ( $paged - 1 ) * $per_page ) + 1;
		$shown_to         = ( ( $paged - 1 ) * $per_page ) + count( $rows );
		$export_countries = LTAR_DB::get_distinct_values( 'export_country' );
		$export_cities    = LTAR_DB::get_distinct_values( 'export_city' );
		$import_countries = LTAR_DB::get_distinct_values( 'import_country' );
		$import_cities    = LTAR_DB::get_distinct_values( 'import_city' );
		$active_order_by  = self::sanitize_sort_field( $filters['order_by'] ?? '' );
		$active_order     = self::sanitize_sort_direction( $filters['order'] ?? '' );
		$total_pages      = max( 1, (int) ceil( max( 1, $total_rows ) / max( 1, $per_page ) ) );
		$pagination_links = self::pagination_links( $paged, $total_pages );
		?>
		<div class="lhfe-card ltar-table-card">
			<div class="ltar-toolbar">
				<div>
					<h2 class="h4 mb-1"><?php esc_html_e( 'Каталог строк', 'lithops-tariffs' ); ?></h2>
					<p class="ltar-toolbar-meta mb-0">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: first row number, 2: last row number, 3: total rows */
								__( 'Показано %1$d-%2$d из %3$d строк', 'lithops-tariffs' ),
								(int) $shown_from,
								(int) $shown_to,
								(int) $total_rows
							)
						);
						?>
					</p>
				</div>
				<div class="ltar-toolbar-actions">
					<button type="button" class="btn btn-primary" @click.prevent="openCreate()">
						<i class="bi bi-plus-circle me-1"></i><?php esc_html_e( 'Новая строка тарифа', 'lithops-tariffs' ); ?>
					</button>
				</div>
			</div>

			<details class="ltar-fallback-details mb-4">
				<summary><?php esc_html_e( 'Правила fallback и исключения', 'lithops-tariffs' ); ?></summary>
				<div class="ltar-fallback-copy">
					<p><?php esc_html_e( 'Порядок работы resolver детерминирован и рассчитан на то, чтобы вернуть значение даже при отсутствии точного маршрута.', 'lithops-tariffs' ); ?></p>
					<ul>
						<li><?php esc_html_e( '1. Сначала ищется точная строка city-to-city для запрошенного сервиса.', 'lithops-tariffs' ); ?></li>
						<li><?php esc_html_e( '2. Если точного city-to-city нет, используется country-to-country заглушка для тех же стран экспорта и импорта.', 'lithops-tariffs' ); ?></li>
						<li><?php esc_html_e( '3. Если нет и country-to-country, используется import-country-only заглушка.', 'lithops-tariffs' ); ?></li>
						<li><?php esc_html_e( '4. Если точная строка существует, но в ней не хватает одного из показателей, добирается только недостающее поле из нижних fallback-слоёв.', 'lithops-tariffs' ); ?></li>
						<li><?php esc_html_e( '5. Обычные Pages обычно запрашивают import-country-only, а route CPT могут запрашивать city-to-country, country-to-city или city-to-city.', 'lithops-tariffs' ); ?></li>
						<li><?php esc_html_e( '6. Названия городов нормализуются при поиске, поэтому "Ho Chi Minh" и "Ho Chi Minh City" считаются одним и тем же маршрутом.', 'lithops-tariffs' ); ?></li>
						<li><?php esc_html_e( '7. Если ни один слой не содержит значения для нужного поля сервиса, поле остаётся пустым.', 'lithops-tariffs' ); ?></li>
					</ul>
				</div>
			</details>

			<?php self::render_stub_values_section(); ?>

			<form method="get" class="ltar-filter-form mb-4">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<?php if ( '' !== $active_order_by ) : ?>
					<input type="hidden" name="ltar_order_by" value="<?php echo esc_attr( $active_order_by ); ?>">
				<?php endif; ?>
				<?php if ( '' !== $active_order ) : ?>
					<input type="hidden" name="ltar_order" value="<?php echo esc_attr( $active_order ); ?>">
				<?php endif; ?>
				<div class="ltar-filter-grid">
					<div>
						<label class="form-label" for="ltar-search"><?php esc_html_e( 'Поиск', 'lithops-tariffs' ); ?></label>
						<input class="form-control" id="ltar-search" type="search" name="ltar_search" placeholder="<?php esc_attr_e( 'Ключ маршрута, страна, город, сервис', 'lithops-tariffs' ); ?>" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>">
					</div>
					<div>
						<label class="form-label" for="ltar-export-country-filter"><?php esc_html_e( 'Страна откуда', 'lithops-tariffs' ); ?></label>
						<input class="form-control" id="ltar-export-country-filter" type="text" name="ltar_export_country" list="ltar-export-country-options" placeholder="<?php esc_attr_e( 'Существующая страна экспорта', 'lithops-tariffs' ); ?>" value="<?php echo esc_attr( $filters['export_country'] ?? '' ); ?>">
					</div>
					<div>
						<label class="form-label" for="ltar-export-city-filter"><?php esc_html_e( 'Город отправления', 'lithops-tariffs' ); ?></label>
						<input class="form-control" id="ltar-export-city-filter" type="text" name="ltar_export_city" list="ltar-export-city-options" placeholder="<?php esc_attr_e( 'Существующий город экспорта', 'lithops-tariffs' ); ?>" value="<?php echo esc_attr( $filters['export_city'] ?? '' ); ?>">
					</div>
					<div>
						<label class="form-label" for="ltar-import-country-filter"><?php esc_html_e( 'Страна куда', 'lithops-tariffs' ); ?></label>
						<input class="form-control" id="ltar-import-country-filter" type="text" name="ltar_import_country" list="ltar-import-country-options" placeholder="<?php esc_attr_e( 'Существующая страна импорта', 'lithops-tariffs' ); ?>" value="<?php echo esc_attr( $filters['import_country'] ?? '' ); ?>">
					</div>
					<div>
						<label class="form-label" for="ltar-import-city-filter"><?php esc_html_e( 'Город назначения', 'lithops-tariffs' ); ?></label>
						<input class="form-control" id="ltar-import-city-filter" type="text" name="ltar_import_city" list="ltar-import-city-options" placeholder="<?php esc_attr_e( 'Существующий город импорта', 'lithops-tariffs' ); ?>" value="<?php echo esc_attr( $filters['import_city'] ?? '' ); ?>">
					</div>
					<div>
						<label class="form-label" for="ltar-per-page"><?php esc_html_e( 'Строк на странице', 'lithops-tariffs' ); ?></label>
						<select class="form-select" id="ltar-per-page" name="per_page">
							<?php foreach ( array( 25, 50, 100, 200 ) as $size ) : ?>
								<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $per_page, $size ); ?>><?php echo esc_html( $size ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="ltar-filter-actions">
					<button type="submit" class="btn btn-outline-primary"><?php esc_html_e( 'Применить фильтры', 'lithops-tariffs' ); ?></button>
					<a class="btn btn-outline-secondary" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Сбросить', 'lithops-tariffs' ); ?></a>
				</div>
				<datalist id="ltar-export-country-options">
					<?php foreach ( $export_countries as $country_name ) : ?>
						<option value="<?php echo esc_attr( $country_name ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<datalist id="ltar-export-city-options">
					<?php foreach ( $export_cities as $city_name ) : ?>
						<option value="<?php echo esc_attr( $city_name ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<datalist id="ltar-import-country-options">
					<?php foreach ( $import_countries as $country_name ) : ?>
						<option value="<?php echo esc_attr( $country_name ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<datalist id="ltar-import-city-options">
					<?php foreach ( $import_cities as $city_name ) : ?>
						<option value="<?php echo esc_attr( $city_name ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			</form>

			<div class="table-responsive">
				<table class="table table-sm table-hover ltar-table align-middle">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Маршрут', 'lithops-tariffs' ); ?></th>
							<th><?php esc_html_e( 'Сервис', 'lithops-tariffs' ); ?></th>
							<th class="ltar-col-numeric"><?php self::render_sort_header( __( 'Цена от', 'lithops-tariffs' ), 'price_min', $active_order_by, $active_order ); ?></th>
							<th class="ltar-col-numeric"><?php self::render_sort_header( __( 'Цена до', 'lithops-tariffs' ), 'price_max', $active_order_by, $active_order ); ?></th>
							<th class="ltar-col-numeric"><?php self::render_sort_header( __( 'Цена средняя', 'lithops-tariffs' ), 'price_avg', $active_order_by, $active_order ); ?></th>
							<th class="ltar-col-numeric"><?php self::render_sort_header( __( 'Срок от', 'lithops-tariffs' ), 'transit_min_days', $active_order_by, $active_order ); ?></th>
							<th class="ltar-col-numeric"><?php self::render_sort_header( __( 'Срок до', 'lithops-tariffs' ), 'transit_max_days', $active_order_by, $active_order ); ?></th>
							<th class="ltar-col-numeric"><?php self::render_sort_header( __( 'Срок средний', 'lithops-tariffs' ), 'transit_avg_days', $active_order_by, $active_order ); ?></th>
							<th><?php esc_html_e( 'Источник', 'lithops-tariffs' ); ?></th>
							<th class="text-end"><?php esc_html_e( 'Действия', 'lithops-tariffs' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr>
								<td colspan="10" class="text-muted"><?php esc_html_e( 'По текущим фильтрам строки не найдены.', 'lithops-tariffs' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$row_id         = (int) ( $row->id ?? 0 );
								$route_from     = trim( (string) ( '' !== (string) $row->export_city ? $row->export_city : $row->export_country ) );
								$route_to       = trim( (string) ( '' !== (string) $row->import_city ? $row->import_city : $row->import_country ) );
								$route_label    = trim( $route_from . ' -> ' . $route_to );
								$route_meta     = array_filter(
									array(
										trim( (string) $row->export_country_code ),
										trim( (string) $row->import_country_code ),
										trim( (string) $row->based_on_scenario ),
									)
								);
								$service_detail = trim( (string) $row->service_label );
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $route_label ); ?></strong>
										<div class="text-muted small"><?php echo esc_html( (string) $row->route_key ); ?></div>
										<?php if ( ! empty( $route_meta ) ) : ?>
											<div class="ltar-table-meta"><?php echo esc_html( implode( ' | ', $route_meta ) ); ?></div>
										<?php endif; ?>
									</td>
									<td>
										<strong><?php echo esc_html( (string) $row->service ); ?></strong>
										<?php if ( '' !== $service_detail ) : ?>
											<div class="text-muted small"><?php echo esc_html( $service_detail ); ?></div>
										<?php endif; ?>
									</td>
									<td class="ltar-col-numeric"><?php echo esc_html( self::format_money_value( $row->price_min, (string) $row->currency ) ); ?></td>
									<td class="ltar-col-numeric"><?php echo esc_html( self::format_money_value( $row->price_max, (string) $row->currency ) ); ?></td>
									<td class="ltar-col-numeric"><?php echo esc_html( self::format_money_value( $row->price_avg, (string) $row->currency ) ); ?></td>
									<td class="ltar-col-numeric"><?php echo esc_html( self::format_days_value( $row->transit_min_days ) ); ?></td>
									<td class="ltar-col-numeric"><?php echo esc_html( self::format_days_value( $row->transit_max_days ) ); ?></td>
									<td class="ltar-col-numeric"><?php echo esc_html( self::format_days_value( $row->transit_avg_days ) ); ?></td>
									<td>
										<span class="badge text-bg-light"><?php echo esc_html( (string) $row->price_source ); ?></span>
										<?php if ( ! empty( $row->reason ) ) : ?>
											<div class="text-muted small mt-1"><?php echo esc_html( (string) $row->reason ); ?></div>
										<?php endif; ?>
									</td>
									<td class="text-end">
										<button type="button" class="btn btn-sm btn-outline-primary" @click.prevent="openEditor(<?php echo esc_attr( $row_id ); ?>)"><?php esc_html_e( 'Редактировать', 'lithops-tariffs' ); ?></button>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="d-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Удалить эту строку тарифа?', 'lithops-tariffs' ) ); ?>');">
											<?php wp_nonce_field( 'ltar_delete_row' ); ?>
											<input type="hidden" name="action" value="ltar_delete_row">
											<input type="hidden" name="ltar_row_id" value="<?php echo esc_attr( $row_id ); ?>">
											<input type="hidden" name="ltar_return_to" value="<?php echo esc_attr( $return_to ); ?>">
											<button type="submit" class="btn btn-sm btn-outline-danger"><?php esc_html_e( 'Удалить', 'lithops-tariffs' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( ! empty( $pagination_links ) ) : ?>
				<nav class="ltar-pagination" aria-label="<?php esc_attr_e( 'Пагинация каталога', 'lithops-tariffs' ); ?>">
					<?php foreach ( $pagination_links as $link ) : ?>
						<?php echo wp_kses_post( $link ); ?>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render create/edit modal.
	 *
	 * @param string $return_to Return URL.
	 * @return void
	 */
	protected static function render_editor_modal( $return_to ) {
		?>
		<div class="ltar-modal-backdrop" x-cloak x-show="editorOpen" x-transition.opacity style="display:none;">
			<div class="ltar-modal-panel" @click.outside="closeEditor()">
				<div class="ltar-modal-header">
					<div>
						<h2 class="h4 mb-1" x-text="editorTitle()"></h2>
						<p class="text-muted mb-0"><?php esc_html_e( 'Создание или редактирование нормализованной ERP-строки тарифа.', 'lithops-tariffs' ); ?></p>
					</div>
					<button type="button" class="btn btn-outline-secondary btn-sm" @click.prevent="closeEditor()">
						<i class="bi bi-x-lg"></i>
					</button>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ltar_save_row' ); ?>
					<input type="hidden" name="action" value="ltar_save_row">
					<input type="hidden" name="ltar_return_to" value="<?php echo esc_attr( $return_to ); ?>">
					<input type="hidden" name="ltar_row_id" x-model="editor.ltar_row_id">

					<div class="row g-3">
						<div class="col-12">
							<label class="form-label" for="ltar-editor-route-key"><?php esc_html_e( 'Ключ маршрута', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-route-key" type="text" name="route_key" x-model="editor.route_key">
						</div>

						<div class="col-md-6">
							<label class="form-label" for="ltar-editor-service"><?php esc_html_e( 'Сервис', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-service" type="text" name="service" x-model="editor.service" required>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="ltar-editor-service-label"><?php esc_html_e( 'Название сервиса', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-service-label" type="text" name="service_label" x-model="editor.service_label">
						</div>

						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-export-country-code"><?php esc_html_e( 'Код страны экспорта', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-export-country-code" type="text" name="export_country_code" x-model="editor.export_country_code" maxlength="2">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-export-country"><?php esc_html_e( 'Страна экспорта', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-export-country" type="text" name="export_country" x-model="editor.export_country">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-export-city"><?php esc_html_e( 'Город экспорта', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-export-city" type="text" name="export_city" x-model="editor.export_city">
						</div>

						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-import-country-code"><?php esc_html_e( 'Код страны импорта', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-import-country-code" type="text" name="import_country_code" x-model="editor.import_country_code" maxlength="2" required>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-import-country"><?php esc_html_e( 'Страна импорта', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-import-country" type="text" name="import_country" x-model="editor.import_country">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-import-city"><?php esc_html_e( 'Город импорта', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-import-city" type="text" name="import_city" x-model="editor.import_city">
						</div>

						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-unit"><?php esc_html_e( 'Единица', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-unit" type="text" name="unit" x-model="editor.unit">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-currency"><?php esc_html_e( 'Валюта', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-currency" type="text" name="currency" x-model="editor.currency">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-price-source"><?php esc_html_e( 'Источник цены', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-price-source" type="text" name="price_source" x-model="editor.price_source">
						</div>

						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-price-min"><?php esc_html_e( 'Цена min', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-price-min" type="text" name="price_min" x-model="editor.price_min">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-price-max"><?php esc_html_e( 'Цена max', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-price-max" type="text" name="price_max" x-model="editor.price_max">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-price-avg"><?php esc_html_e( 'Цена avg', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-price-avg" type="text" name="price_avg" x-model="editor.price_avg">
						</div>

						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-transit-min"><?php esc_html_e( 'Срок min, дней', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-transit-min" type="text" name="transit_min_days" x-model="editor.transit_min_days">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-transit-max"><?php esc_html_e( 'Срок max, дней', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-transit-max" type="text" name="transit_max_days" x-model="editor.transit_max_days">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ltar-editor-transit-avg"><?php esc_html_e( 'Срок avg, дней', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-transit-avg" type="text" name="transit_avg_days" x-model="editor.transit_avg_days">
						</div>

						<div class="col-md-6">
							<label class="form-label" for="ltar-editor-based-on-scenario"><?php esc_html_e( 'Базовый сценарий', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-based-on-scenario" type="text" name="based_on_scenario" x-model="editor.based_on_scenario">
						</div>
						<div class="col-md-6">
							<label class="form-label" for="ltar-editor-reason"><?php esc_html_e( 'Причина / reason', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-reason" type="text" name="reason" x-model="editor.reason">
						</div>

						<div class="col-12">
							<label class="form-label" for="ltar-editor-based-on-route"><?php esc_html_e( 'Базовый маршрут', 'lithops-tariffs' ); ?></label>
							<input class="form-control" id="ltar-editor-based-on-route" type="text" name="based_on_route" x-model="editor.based_on_route">
						</div>

						<div class="col-12">
							<label class="form-label" for="ltar-editor-notes"><?php esc_html_e( 'Заметки', 'lithops-tariffs' ); ?></label>
							<textarea class="form-control" id="ltar-editor-notes" name="notes" rows="4" x-model="editor.notes"></textarea>
						</div>
					</div>

					<div class="ltar-modal-footer">
						<button type="button" class="btn btn-outline-secondary" @click.prevent="closeEditor()"><?php esc_html_e( 'Отмена', 'lithops-tariffs' ); ?></button>
						<button type="submit" class="btn btn-primary">
							<i class="bi bi-save me-1"></i><?php esc_html_e( 'Сохранить строку', 'lithops-tariffs' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}
