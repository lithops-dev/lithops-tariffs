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
					'page_title' => __( 'Tariffs Catalog', 'lithops-tariffs' ),
					'menu_title' => __( 'Tariffs Catalog', 'lithops-tariffs' ),
					'capability' => LTAR_CAP,
					'menu_slug'  => self::MENU_SLUG,
					'callback'   => array( __CLASS__, 'render_page' ),
				)
			);

			return;
		}

		add_menu_page(
			__( 'Tariffs Catalog', 'lithops-tariffs' ),
			__( 'Tariffs Catalog', 'lithops-tariffs' ),
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

		$raw = file_get_contents( (string) $file['tmp_name'] );
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
	 * Redirect to admin page with notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	protected static function redirect_with_notice( $notice ) {
		$url = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'ltar_notice' => sanitize_key( (string) $notice ),
			),
			admin_url( 'admin.php' )
		);

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
			'imported'     => array( 'type' => 'success', 'text' => __( 'JSON импортирован, каталог тарифов обновлён.', 'lithops-tariffs' ) ),
			'import_empty' => array( 'type' => 'warning', 'text' => __( 'В JSON не найдено поддерживаемых тарифных строк.', 'lithops-tariffs' ) ),
			'import_error' => array( 'type' => 'danger', 'text' => __( 'Не удалось прочитать JSON-файл.', 'lithops-tariffs' ) ),
			'created'      => array( 'type' => 'success', 'text' => __( 'Строка тарифа создана.', 'lithops-tariffs' ) ),
			'updated'      => array( 'type' => 'success', 'text' => __( 'Строка тарифа обновлена.', 'lithops-tariffs' ) ),
			'deleted'      => array( 'type' => 'warning', 'text' => __( 'Строка тарифа удалена.', 'lithops-tariffs' ) ),
		);

		return $map[ $notice ] ?? array();
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
		$search        = isset( $_GET['ltar_search'] ) ? sanitize_text_field( wp_unslash( $_GET['ltar_search'] ) ) : '';
		$rows          = LTAR_DB::get_rows( array( 'search' => $search ) );
		$notice        = isset( $_GET['ltar_notice'] ) ? sanitize_key( wp_unslash( $_GET['ltar_notice'] ) ) : '';
		$notice_config = self::notice_config( $notice );
		$edit_id       = isset( $_GET['ltar_edit'] ) ? absint( wp_unslash( $_GET['ltar_edit'] ) ) : 0;
		$edit_row      = $edit_id > 0 ? LTAR_DB::get_row( $edit_id ) : null;
		$edit_data     = is_object( $edit_row ) ? get_object_vars( $edit_row ) : array();
		$endpoint      = ltar_get_endpoint_url();
		$token         = ltar_get_auth_token();
		$shared_token  = function_exists( 'lesh_ensure_enrollment_token' ) && function_exists( 'lesh_decrypt' );
		?>
		<div
			class="wrap ltar-admin-wrap"
			x-data="{
				copied: '',
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
			}"
		>
			<div class="lhfe-banner">
				<div>
					<h1 class="mb-2"><?php esc_html_e( 'Tariffs Catalog', 'lithops-tariffs' ); ?></h1>
					<p class="mb-0"><?php esc_html_e( 'ERP-каталог тарифов для SEO и вторичных сайтов: импорт JSON, ручной CRUD и REST-выдача строк в единый bridge/provider слой.', 'lithops-tariffs' ); ?></p>
				</div>
				<div class="lhfe-banner-meta">
					<span class="badge text-bg-light"><?php echo esc_html( 'v' . LTAR_VERSION ); ?></span>
					<span class="badge text-bg-info"><?php echo esc_html( sprintf( __( 'Строк: %d', 'lithops-tariffs' ), (int) $stats['total'] ) ); ?></span>
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
						<div class="metric-value"><?php echo esc_html( (int) $stats['total'] ); ?></div>
					</div>
				</div>
				<div class="col-xl-3 col-md-6">
					<div class="lhfe-card metric-card">
						<div class="metric-title"><?php esc_html_e( 'Import countries', 'lithops-tariffs' ); ?></div>
						<div class="metric-value"><?php echo esc_html( (int) $stats['import_countries'] ); ?></div>
					</div>
				</div>
				<div class="col-xl-3 col-md-6">
					<div class="lhfe-card metric-card">
						<div class="metric-title"><?php esc_html_e( 'Export countries', 'lithops-tariffs' ); ?></div>
						<div class="metric-value"><?php echo esc_html( (int) $stats['export_countries'] ); ?></div>
					</div>
				</div>
				<div class="col-xl-3 col-md-6">
					<div class="lhfe-card metric-card">
						<div class="metric-title"><?php esc_html_e( 'Сервисов', 'lithops-tariffs' ); ?></div>
						<div class="metric-value"><?php echo esc_html( (int) $stats['services'] ); ?></div>
					</div>
				</div>
			</div>

			<div class="row g-4 mb-4">
				<div class="col-lg-6">
					<div class="lhfe-card">
						<h2 class="h4 mb-3"><?php esc_html_e( 'Импорт JSON', 'lithops-tariffs' ); ?></h2>
						<p class="mb-3"><?php esc_html_e( 'Импорт полностью заменяет текущий каталог строк. Поддерживаются плоские массивы строк и структурированные JSON-файлы тарифов.', 'lithops-tariffs' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
							<?php wp_nonce_field( 'ltar_import_json' ); ?>
							<input type="hidden" name="action" value="ltar_import_json">
							<div class="mb-3">
								<label class="form-label" for="ltar-json-file"><?php esc_html_e( 'JSON-файл', 'lithops-tariffs' ); ?></label>
								<input class="form-control" id="ltar-json-file" type="file" name="ltar_json_file" accept=".json,application/json" required>
							</div>
							<button type="submit" class="btn btn-primary">
								<i class="bi bi-upload me-1"></i><?php esc_html_e( 'Импортировать и заменить каталог', 'lithops-tariffs' ); ?>
							</button>
						</form>
						<ul class="list-unstyled mt-4 mb-0 ltar-summary-list">
							<li><strong><?php esc_html_e( 'Последний файл:', 'lithops-tariffs' ); ?></strong> <?php echo esc_html( $settings['last_import_name'] ? $settings['last_import_name'] : __( 'не было', 'lithops-tariffs' ) ); ?></li>
							<li><strong><?php esc_html_e( 'Последний импорт:', 'lithops-tariffs' ); ?></strong> <?php echo esc_html( $settings['last_import_gmt'] ? get_date_from_gmt( $settings['last_import_gmt'], 'd.m.Y H:i' ) : __( 'не было', 'lithops-tariffs' ) ); ?></li>
							<li><strong><?php esc_html_e( 'Строк после импорта:', 'lithops-tariffs' ); ?></strong> <?php echo esc_html( (int) ( $settings['last_import_rows'] ?? 0 ) ); ?></li>
						</ul>
					</div>
				</div>
				<div class="col-lg-6">
					<div class="lhfe-card">
						<h2 class="h4 mb-3"><?php esc_html_e( 'REST-доступ для сайтов', 'lithops-tariffs' ); ?></h2>
						<p><?php esc_html_e( 'Вторичные сайты получают этот каталог через ERP Bridge и SEO Settings. Дополнительный endpoint на сайтах вручную настраивать не нужно, если уже подключён ERP Sites Hub.', 'lithops-tariffs' ); ?></p>
						<div class="mb-3">
							<label class="form-label" for="ltar-endpoint-copy"><?php esc_html_e( 'Endpoint', 'lithops-tariffs' ); ?></label>
							<div class="input-group">
								<input x-ref="endpointField" id="ltar-endpoint-copy" class="form-control" type="text" readonly value="<?php echo esc_attr( $endpoint ); ?>">
								<button class="btn btn-outline-secondary" type="button" @click.prevent="copyField($refs.endpointField, '<?php echo esc_attr__( 'Endpoint', 'lithops-tariffs' ); ?>')"><?php esc_html_e( 'Скопировать', 'lithops-tariffs' ); ?></button>
							</div>
						</div>
						<div class="mb-3">
							<label class="form-label" for="ltar-token-copy"><?php esc_html_e( 'Токен доступа', 'lithops-tariffs' ); ?></label>
							<div class="input-group">
								<input x-ref="tokenField" id="ltar-token-copy" class="form-control" type="text" readonly value="<?php echo esc_attr( $token ); ?>">
								<button class="btn btn-outline-secondary" type="button" @click.prevent="copyField($refs.tokenField, '<?php echo esc_attr__( 'Токен доступа', 'lithops-tariffs' ); ?>')"><?php esc_html_e( 'Скопировать', 'lithops-tariffs' ); ?></button>
							</div>
						</div>
						<div class="alert alert-info mb-0">
							<?php echo esc_html( $shared_token ? __( 'Используется общий enrollment token из ERP Sites Hub.', 'lithops-tariffs' ) : __( 'Используется локальный token плагина Tariffs.', 'lithops-tariffs' ) ); ?>
							<template x-if="copied"><div class="ltar-copy-note" x-text="copied + ' copied'"></div></template>
						</div>
					</div>
				</div>
			</div>

			<div class="row g-4 mb-4">
				<div class="col-lg-5">
					<div class="lhfe-card">
						<h2 class="h4 mb-3"><?php echo esc_html( $edit_id > 0 ? __( 'Редактирование строки', 'lithops-tariffs' ) : __( 'Новая строка тарифа', 'lithops-tariffs' ) ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'ltar_save_row' ); ?>
							<input type="hidden" name="action" value="ltar_save_row">
							<input type="hidden" name="ltar_row_id" value="<?php echo esc_attr( $edit_id ); ?>">
							<div class="row g-3">
								<div class="col-12">
									<label class="form-label" for="ltar-route-key"><?php esc_html_e( 'Route key', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-route-key" type="text" name="route_key" value="<?php echo esc_attr( $edit_data['route_key'] ?? '' ); ?>">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-service"><?php esc_html_e( 'Service', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-service" type="text" name="service" value="<?php echo esc_attr( $edit_data['service'] ?? '' ); ?>" required>
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-service-label"><?php esc_html_e( 'Service label', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-service-label" type="text" name="service_label" value="<?php echo esc_attr( $edit_data['service_label'] ?? '' ); ?>">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-export-country-code"><?php esc_html_e( 'Export country code', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-export-country-code" type="text" name="export_country_code" value="<?php echo esc_attr( $edit_data['export_country_code'] ?? '' ); ?>" maxlength="2">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-export-country"><?php esc_html_e( 'Export country', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-export-country" type="text" name="export_country" value="<?php echo esc_attr( $edit_data['export_country'] ?? '' ); ?>">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-export-city"><?php esc_html_e( 'Export city', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-export-city" type="text" name="export_city" value="<?php echo esc_attr( $edit_data['export_city'] ?? '' ); ?>">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-import-country-code"><?php esc_html_e( 'Import country code', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-import-country-code" type="text" name="import_country_code" value="<?php echo esc_attr( $edit_data['import_country_code'] ?? '' ); ?>" maxlength="2" required>
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-import-country"><?php esc_html_e( 'Import country', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-import-country" type="text" name="import_country" value="<?php echo esc_attr( $edit_data['import_country'] ?? '' ); ?>">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-import-city"><?php esc_html_e( 'Import city', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-import-city" type="text" name="import_city" value="<?php echo esc_attr( $edit_data['import_city'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-unit"><?php esc_html_e( 'Unit', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-unit" type="text" name="unit" value="<?php echo esc_attr( $edit_data['unit'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-currency"><?php esc_html_e( 'Currency', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-currency" type="text" name="currency" value="<?php echo esc_attr( $edit_data['currency'] ?? 'USD' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-price-source"><?php esc_html_e( 'Price source', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-price-source" type="text" name="price_source" value="<?php echo esc_attr( $edit_data['price_source'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-price-min"><?php esc_html_e( 'Price min', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-price-min" type="text" name="price_min" value="<?php echo esc_attr( $edit_data['price_min'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-price-max"><?php esc_html_e( 'Price max', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-price-max" type="text" name="price_max" value="<?php echo esc_attr( $edit_data['price_max'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-price-avg"><?php esc_html_e( 'Price avg', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-price-avg" type="text" name="price_avg" value="<?php echo esc_attr( $edit_data['price_avg'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-transit-min"><?php esc_html_e( 'Transit min days', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-transit-min" type="text" name="transit_min_days" value="<?php echo esc_attr( $edit_data['transit_min_days'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-transit-max"><?php esc_html_e( 'Transit max days', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-transit-max" type="text" name="transit_max_days" value="<?php echo esc_attr( $edit_data['transit_max_days'] ?? '' ); ?>">
								</div>
								<div class="col-md-4">
									<label class="form-label" for="ltar-transit-avg"><?php esc_html_e( 'Transit avg days', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-transit-avg" type="text" name="transit_avg_days" value="<?php echo esc_attr( $edit_data['transit_avg_days'] ?? '' ); ?>">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-based-on-scenario"><?php esc_html_e( 'Based on scenario', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-based-on-scenario" type="text" name="based_on_scenario" value="<?php echo esc_attr( $edit_data['based_on_scenario'] ?? '' ); ?>">
								</div>
								<div class="col-md-6">
									<label class="form-label" for="ltar-reason"><?php esc_html_e( 'Reason', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-reason" type="text" name="reason" value="<?php echo esc_attr( $edit_data['reason'] ?? '' ); ?>">
								</div>
								<div class="col-12">
									<label class="form-label" for="ltar-based-on-route"><?php esc_html_e( 'Based on route', 'lithops-tariffs' ); ?></label>
									<input class="form-control" id="ltar-based-on-route" type="text" name="based_on_route" value="<?php echo esc_attr( $edit_data['based_on_route'] ?? '' ); ?>">
								</div>
								<div class="col-12">
									<label class="form-label" for="ltar-notes"><?php esc_html_e( 'Notes', 'lithops-tariffs' ); ?></label>
									<textarea class="form-control" id="ltar-notes" name="notes" rows="3"><?php echo esc_textarea( $edit_data['notes'] ?? '' ); ?></textarea>
								</div>
							</div>
							<div class="d-flex gap-2 mt-4">
								<button type="submit" class="btn btn-primary">
									<i class="bi bi-save me-1"></i><?php echo esc_html( $edit_id > 0 ? __( 'Сохранить изменения', 'lithops-tariffs' ) : __( 'Добавить строку', 'lithops-tariffs' ) ); ?>
								</button>
								<?php if ( $edit_id > 0 ) : ?>
									<a class="btn btn-outline-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Отменить редактирование', 'lithops-tariffs' ); ?></a>
								<?php endif; ?>
							</div>
						</form>
					</div>
				</div>
				<div class="col-lg-7">
					<div class="lhfe-card">
						<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
							<h2 class="h4 mb-0"><?php esc_html_e( 'Каталог строк', 'lithops-tariffs' ); ?></h2>
							<form method="get" class="ltar-search-form">
								<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
								<input class="form-control" type="search" name="ltar_search" placeholder="<?php esc_attr_e( 'Поиск по маршруту, стране, городу, сервису', 'lithops-tariffs' ); ?>" value="<?php echo esc_attr( $search ); ?>">
							</form>
						</div>
						<div class="table-responsive">
							<table class="table table-sm table-hover ltar-table align-middle">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Route', 'lithops-tariffs' ); ?></th>
										<th><?php esc_html_e( 'Service', 'lithops-tariffs' ); ?></th>
										<th><?php esc_html_e( 'Price', 'lithops-tariffs' ); ?></th>
										<th><?php esc_html_e( 'Transit', 'lithops-tariffs' ); ?></th>
										<th><?php esc_html_e( 'Source', 'lithops-tariffs' ); ?></th>
										<th class="text-end"><?php esc_html_e( 'Действия', 'lithops-tariffs' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $rows ) ) : ?>
										<tr>
											<td colspan="6" class="text-muted"><?php esc_html_e( 'Каталог пуст. Импортируйте JSON или добавьте строку вручную.', 'lithops-tariffs' ); ?></td>
										</tr>
									<?php else : ?>
										<?php foreach ( $rows as $row ) : ?>
											<?php
											$row_id = (int) ( $row->id ?? 0 );
											$route  = trim( (string) ( ( $row->export_city ?: $row->export_country ) . ' -> ' . ( $row->import_city ?: $row->import_country ) ) );
											$price  = trim( (string) ( $row->currency . ' ' . $row->price_min . ' - ' . $row->price_max ) );
											$days   = trim( (string) ( $row->transit_min_days . ' - ' . $row->transit_max_days ) );
											?>
											<tr>
												<td>
													<strong><?php echo esc_html( $route ); ?></strong>
													<div class="text-muted small"><?php echo esc_html( (string) $row->route_key ); ?></div>
												</td>
												<td>
													<strong><?php echo esc_html( (string) $row->service ); ?></strong>
													<div class="text-muted small"><?php echo esc_html( (string) $row->service_label ); ?></div>
												</td>
												<td><?php echo esc_html( $price ); ?></td>
												<td><?php echo esc_html( $days ); ?></td>
												<td><span class="badge text-bg-light"><?php echo esc_html( (string) $row->price_source ); ?></span></td>
												<td class="text-end">
													<a class="btn btn-sm btn-outline-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'ltar_edit' => $row_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'lithops-tariffs' ); ?></a>
													<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="d-inline" onsubmit="return confirm('<?php echo esc_js( __( 'Удалить эту строку?', 'lithops-tariffs' ) ); ?>');">
														<?php wp_nonce_field( 'ltar_delete_row' ); ?>
														<input type="hidden" name="action" value="ltar_delete_row">
														<input type="hidden" name="ltar_row_id" value="<?php echo esc_attr( $row_id ); ?>">
														<button type="submit" class="btn btn-sm btn-outline-danger"><?php esc_html_e( 'Delete', 'lithops-tariffs' ); ?></button>
													</form>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
