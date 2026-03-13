<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database layer for the tariffs catalog.
 */
class LTAR_DB {

	/**
	 * Build WHERE clauses for catalog queries.
	 *
	 * @param array $args   Query arguments.
	 * @param array $params Prepared statement params.
	 * @return array<int,string>
	 */
	protected static function build_where_clauses( $args, &$params ) {
		global $wpdb;

		$args   = is_array( $args ) ? $args : array();
		$params = array();
		$where  = array();
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(route_key LIKE %s OR export_country LIKE %s OR export_city LIKE %s OR import_country LIKE %s OR import_city LIKE %s OR service LIKE %s OR service_label LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like, $like, $like, $like, $like ) );
		}

		$export_city = isset( $args['export_city'] ) ? trim( (string) $args['export_city'] ) : '';
		if ( '' !== $export_city ) {
			$where[]  = 'export_city = %s';
			$params[] = $export_city;
		}

		$import_city = isset( $args['import_city'] ) ? trim( (string) $args['import_city'] ) : '';
		if ( '' !== $import_city ) {
			$where[]  = 'import_city = %s';
			$params[] = $import_city;
		}

		return $where;
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'lithops_tariffs';
	}

	/**
	 * Create or upgrade the DB table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			route_key varchar(191) NOT NULL,
			export_country varchar(191) NOT NULL,
			export_country_code varchar(8) NOT NULL,
			export_city varchar(191) NOT NULL,
			import_country varchar(191) NOT NULL,
			import_country_code varchar(8) NOT NULL,
			import_city varchar(191) NOT NULL,
			service varchar(64) NOT NULL,
			service_label varchar(191) NOT NULL,
			unit varchar(64) NOT NULL,
			currency varchar(16) NOT NULL,
			price_min decimal(18,4) NULL,
			price_max decimal(18,4) NULL,
			price_avg decimal(18,4) NULL,
			transit_min_days int(11) NULL,
			transit_max_days int(11) NULL,
			transit_avg_days int(11) NULL,
			based_on_route varchar(191) NOT NULL,
			based_on_scenario varchar(64) NOT NULL,
			reason varchar(191) NOT NULL,
			price_source varchar(191) NOT NULL,
			notes text NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY route_service (route_key, service),
			KEY import_lookup (import_country_code, import_city),
			KEY export_lookup (export_country_code, export_city),
			KEY service_lookup (service)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Prepare normalized row for DB storage.
	 *
	 * @param array $row Normalized row.
	 * @return array<string,mixed>
	 */
	protected static function db_row( $row ) {
		$row = ltar_normalize_row( is_array( $row ) ? $row : array() );

		return array(
			'route_key'           => $row['route_key'],
			'export_country'      => $row['export_country'],
			'export_country_code' => $row['export_country_code'],
			'export_city'         => $row['export_city'],
			'import_country'      => $row['import_country'],
			'import_country_code' => $row['import_country_code'],
			'import_city'         => $row['import_city'],
			'service'             => $row['service'],
			'service_label'       => $row['service_label'],
			'unit'                => $row['unit'],
			'currency'            => $row['currency'],
			'price_min'           => $row['price_min'],
			'price_max'           => $row['price_max'],
			'price_avg'           => $row['price_avg'],
			'transit_min_days'    => $row['transit_min_days'],
			'transit_max_days'    => $row['transit_max_days'],
			'transit_avg_days'    => $row['transit_avg_days'],
			'based_on_route'      => $row['based_on_route'],
			'based_on_scenario'   => $row['based_on_scenario'],
			'reason'              => $row['reason'],
			'price_source'        => $row['price_source'],
			'notes'               => $row['notes'],
		);
	}

	/**
	 * Formats for DB writes.
	 *
	 * @return array<int,string>
	 */
	protected static function formats() {
		return array(
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%f', '%f', '%f', '%d', '%d', '%d',
			'%s', '%s', '%s', '%s', '%s',
		);
	}

	/**
	 * Get all rows.
	 *
	 * @param array $args Query args.
	 * @return array<int,object>
	 */
	public static function get_rows( $args = array() ) {
		global $wpdb;

		$args       = is_array( $args ) ? $args : array();
		$table_name = self::table();
		$sql        = "SELECT * FROM {$table_name}";
		$params     = array();
		$where      = self::build_where_clauses( $args, $params );

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY import_country ASC, import_city ASC, export_country ASC, export_city ASC, service ASC, id DESC';

		if ( ! empty( $args['limit'] ) ) {
			$sql .= ' LIMIT ' . max( 1, (int) $args['limit'] );
			$sql .= ' OFFSET ' . max( 0, (int) ( $args['offset'] ?? 0 ) );
		}

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Count rows with current filters.
	 *
	 * @param array $args Query arguments.
	 * @return int
	 */
	public static function count_rows( $args = array() ) {
		global $wpdb;

		$table_name = self::table();
		$sql        = "SELECT COUNT(*) FROM {$table_name}";
		$params     = array();
		$where      = self::build_where_clauses( $args, $params );

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get distinct non-empty values for a whitelisted column.
	 *
	 * @param string $column Column name.
	 * @return array<int,string>
	 */
	public static function get_distinct_values( $column ) {
		global $wpdb;

		$allowed = array(
			'export_city',
			'import_city',
		);
		$column  = sanitize_key( (string) $column );

		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$sql = 'SELECT DISTINCT ' . $column . ' FROM ' . self::table() . ' WHERE ' . $column . " <> '' ORDER BY " . $column . ' ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$values = (array) $wpdb->get_col( $sql );
		$values = array_map( 'trim', $values );
		$values = array_filter( $values );

		return array_values( array_unique( $values ) );
	}

	/**
	 * Get rows that act as fallback stubs.
	 *
	 * @return array<int,object>
	 */
	public static function get_stub_rows() {
		global $wpdb;

		$table_name = self::table();
		$sql        = "SELECT * FROM {$table_name}
			WHERE based_on_scenario IN ('import_country_only', 'country_to_country')
				OR price_source IN ('country_only_default', 'country_pair_stub')
			ORDER BY based_on_scenario ASC, import_country ASC, export_country ASC, service ASC, id DESC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Get a single row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get_row( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	/**
	 * Save a row.
	 *
	 * @param array $row Row data.
	 * @param int   $id  Optional id.
	 * @return int
	 */
	public static function save_row( $row, $id = 0 ) {
		global $wpdb;

		$table_name = self::table();
		$id         = (int) $id;
		$data       = self::db_row( $row );
		$now        = gmdate( 'Y-m-d H:i:s' );

		if ( $id > 0 ) {
			$data['updated_at_gmt'] = $now;
			$formats                = array_merge( self::formats(), array( '%s' ) );

			$wpdb->update(
				$table_name,
				$data,
				array( 'id' => $id ),
				$formats,
				array( '%d' )
			);

			return $id;
		}

		$data['created_at_gmt'] = $now;
		$data['updated_at_gmt'] = $now;

		$wpdb->insert(
			$table_name,
			$data,
			array_merge( self::formats(), array( '%s', '%s' ) )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Replace the whole catalog with a fresh row set.
	 *
	 * @param array $rows Normalized rows.
	 * @return int
	 */
	public static function replace_catalog( $rows ) {
		global $wpdb;

		$table_name = self::table();
		$rows       = is_array( $rows ) ? $rows : array();

		$wpdb->query( 'TRUNCATE TABLE ' . $table_name ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$inserted = 0;
		foreach ( $rows as $row ) {
			$id = self::save_row( $row );
			if ( $id > 0 ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public static function delete_row( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}

		$wpdb->delete(
			self::table(),
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Get high-level stats for the admin dashboard.
	 *
	 * @return array<string,int>
	 */
	public static function get_stats() {
		global $wpdb;

		$table = self::table();

		return array(
			'total'            => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'import_countries' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT import_country_code) FROM {$table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'export_countries' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT export_country_code) FROM {$table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'services'         => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT service) FROM {$table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
