<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default plugin settings.
 *
 * @return array<string,mixed>
 */
function ltar_get_default_settings() {
	return array(
		'api_token_enc'      => '',
		'last_import_name'   => '',
		'last_import_gmt'    => '',
		'last_import_rows'   => 0,
		'last_import_notice' => '',
	);
}

/**
 * Get merged plugin settings.
 *
 * @return array<string,mixed>
 */
function ltar_get_settings() {
	return wp_parse_args( get_option( LTAR_OPTION_KEY, array() ), ltar_get_default_settings() );
}

/**
 * Shared crypto secret derived from WordPress salts.
 *
 * @return string
 */
function ltar_crypto_secret() {
	$secret = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );

	return hash( 'sha256', $secret );
}

/**
 * Encrypt string for storage.
 *
 * @param string $plain Plain text.
 * @return string
 */
function ltar_encrypt( $plain ) {
	if ( ! is_string( $plain ) || '' === $plain ) {
		return '';
	}

	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return $plain;
	}

	$key    = ltar_crypto_secret();
	$iv     = random_bytes( 12 );
	$tag    = '';
	$cipher = 'aes-256-gcm';
	$enc    = openssl_encrypt( $plain, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag );

	if ( false === $enc ) {
		return $plain;
	}

	return base64_encode( $iv . $tag . $enc );
}

/**
 * Decrypt string from storage.
 *
 * @param string $blob Stored blob.
 * @return string
 */
function ltar_decrypt( $blob ) {
	if ( ! is_string( $blob ) || '' === $blob ) {
		return '';
	}

	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return $blob;
	}

	$raw = base64_decode( $blob, true );

	if ( false === $raw || strlen( $raw ) < 28 ) {
		return '';
	}

	$iv     = substr( $raw, 0, 12 );
	$tag    = substr( $raw, 12, 16 );
	$data   = substr( $raw, 28 );
	$key    = ltar_crypto_secret();
	$cipher = 'aes-256-gcm';
	$dec    = openssl_decrypt( $data, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag );

	return false === $dec ? '' : $dec;
}

/**
 * Ensure a local token exists for fallback mode.
 *
 * @param array|null $settings Optional settings array.
 * @param bool       $persist Whether to persist generated token.
 * @return array<string,mixed>
 */
function ltar_ensure_api_token( $settings = null, $persist = true ) {
	$settings = is_array( $settings ) ? wp_parse_args( $settings, ltar_get_default_settings() ) : ltar_get_settings();

	if ( empty( $settings['api_token_enc'] ) ) {
		$settings['api_token_enc'] = ltar_encrypt( wp_generate_password( 48, false, false ) );

		if ( $persist ) {
			update_option( LTAR_OPTION_KEY, $settings, false );
		}
	}

	return $settings;
}

/**
 * Get token that should be used for REST access.
 * Prefers the shared token from ERP Sites Hub when available.
 *
 * @return string
 */
function ltar_get_auth_token() {
	if ( function_exists( 'lesh_ensure_enrollment_token' ) && function_exists( 'lesh_decrypt' ) ) {
		$lesh_settings = lesh_ensure_enrollment_token();
		$lesh_token    = lesh_decrypt( $lesh_settings['enrollment_token_enc'] ?? '' );

		if ( '' !== $lesh_token ) {
			return $lesh_token;
		}
	}

	$settings = ltar_ensure_api_token();

	return ltar_decrypt( $settings['api_token_enc'] ?? '' );
}

/**
 * Get public REST endpoint.
 *
 * @return string
 */
function ltar_get_endpoint_url() {
	return rest_url( 'ltar/v1/catalog' );
}

/**
 * Mask token for admin UI.
 *
 * @param string $value Raw token.
 * @return string
 */
function ltar_mask( $value ) {
	$length = strlen( (string) $value );

	if ( $length <= 6 ) {
		return str_repeat( '*', $length );
	}

	return substr( $value, 0, 3 ) . str_repeat( '*', max( 0, $length - 6 ) ) . substr( $value, -3 );
}

/**
 * Trim and sanitize a text field.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function ltar_text( $value ) {
	return sanitize_text_field( trim( (string) $value ) );
}

/**
 * Normalize two-letter country code.
 *
 * @param mixed $value Raw code.
 * @return string
 */
function ltar_country_code( $value ) {
	$value = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $value ) );

	return substr( $value, 0, 2 );
}

/**
 * Normalize a numeric value or return null.
 *
 * @param mixed $value Raw value.
 * @return float|null
 */
function ltar_float_or_null( $value ) {
	if ( is_string( $value ) ) {
		$value = str_replace( ',', '.', trim( $value ) );
	}

	if ( ! is_numeric( $value ) ) {
		return null;
	}

	return (float) $value;
}

/**
 * Normalize an integer value or return null.
 *
 * @param mixed $value Raw value.
 * @return int|null
 */
function ltar_int_or_null( $value ) {
	if ( is_string( $value ) ) {
		$value = trim( $value );
	}

	if ( ! is_numeric( $value ) ) {
		return null;
	}

	return (int) round( (float) $value );
}

/**
 * Calculate average when missing.
 *
 * @param float|int|null $min Minimum.
 * @param float|int|null $max Maximum.
 * @return float|int|null
 */
function ltar_average( $min, $max ) {
	if ( null === $min || null === $max ) {
		return null;
	}

	$avg = ( (float) $min + (float) $max ) / 2;

	return floor( $avg ) === $avg ? (int) $avg : round( $avg, 2 );
}

/**
 * Normalize service key to the SEO/JSON-compatible legacy format.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function ltar_service_key( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = str_replace( array( ' ', '-' ), '_', $value );

	$map = array(
		'lcl'             => 'sea_lcl',
		'sea_lcl'         => 'sea_lcl',
		'sea_lcl_freight' => 'sea_lcl',
		'fcl20'           => 'sea_fcl_20',
		'fcl_20'          => 'sea_fcl_20',
		'sea_fcl_20'      => 'sea_fcl_20',
		'20dc'            => 'sea_fcl_20',
		'20_dc'           => 'sea_fcl_20',
		'20ft'            => 'sea_fcl_20',
		'20_ft'           => 'sea_fcl_20',
		'fcl40'           => 'sea_fcl_40',
		'fcl_40'          => 'sea_fcl_40',
		'sea_fcl_40'      => 'sea_fcl_40',
		'40dc'            => 'sea_fcl_40',
		'40_dc'           => 'sea_fcl_40',
		'40ft'            => 'sea_fcl_40',
		'40_ft'           => 'sea_fcl_40',
		'fcl40hc'         => 'sea_fcl_40',
		'fcl_40hc'        => 'sea_fcl_40',
		'sea_fcl_40hc'    => 'sea_fcl_40',
		'40hc'            => 'sea_fcl_40',
		'40_hc'           => 'sea_fcl_40',
		'40_high_cube'    => 'sea_fcl_40',
		'reefer20'        => 'reefer20',
		'reefer_20'       => 'reefer20',
		'sea_reefer_20'   => 'reefer20',
		'20ref'           => 'reefer20',
		'20_ref'          => 'reefer20',
		'reefer40'        => 'reefer40',
		'reefer_40'       => 'reefer40',
		'sea_reefer_40'   => 'reefer40',
		'reefer40hc'      => 'reefer40',
		'reefer_40hc'     => 'reefer40',
		'sea_reefer_40hc' => 'reefer40',
		'40ref'           => 'reefer40',
		'40_ref'          => 'reefer40',
		'air'             => 'air',
		'air_freight'     => 'air',
		'airfreight'      => 'air',
	);

	return isset( $map[ $value ] ) ? $map[ $value ] : sanitize_key( $value );
}

/**
 * Detect scenario from route row fields.
 *
 * @param array $row Row data.
 * @return string
 */
function ltar_detect_row_scenario( $row ) {
	$row = is_array( $row ) ? $row : array();

	$has_export_country = ! empty( $row['export_country'] ) || ! empty( $row['export_country_code'] );
	$has_export_city    = ! empty( $row['export_city'] );
	$has_import_country = ! empty( $row['import_country'] ) || ! empty( $row['import_country_code'] );
	$has_import_city    = ! empty( $row['import_city'] );

	if ( $has_import_country && ! $has_export_country && ! $has_export_city && ! $has_import_city ) {
		return 'import_country_only';
	}
	if ( $has_export_country && $has_import_country && ! $has_export_city && ! $has_import_city ) {
		return 'country_to_country';
	}
	if ( $has_export_country && $has_import_country && ! $has_export_city && $has_import_city ) {
		return 'country_to_city';
	}
	if ( $has_export_country && $has_import_country && $has_export_city && ! $has_import_city ) {
		return 'city_to_country';
	}
	if ( $has_export_country && $has_import_country && $has_export_city && $has_import_city ) {
		return 'city_to_city';
	}

	return $has_import_country ? 'import_country_only' : 'fallback';
}

/**
 * Build a synthetic route key when the source does not provide one.
 *
 * @param array $row Row data.
 * @return string
 */
function ltar_build_route_key( $row ) {
	$row = is_array( $row ) ? $row : array();

	$route_key = ltar_text( $row['route_key'] ?? ( $row['rate_key'] ?? '' ) );

	if ( '' !== $route_key ) {
		return $route_key;
	}

	$parts = array(
		ltar_country_code( $row['export_country_code'] ?? '' ),
		sanitize_title( (string) ( $row['export_city'] ?? '' ) ),
		ltar_country_code( $row['import_country_code'] ?? '' ),
		sanitize_title( (string) ( $row['import_city'] ?? '' ) ),
		ltar_service_key( $row['service'] ?? ( $row['mode'] ?? '' ) ),
	);

	$parts = array_filter( $parts );

	return implode( '__', $parts );
}

/**
 * Normalize a single row into the shared tariffs format.
 *
 * @param array $row      Raw row.
 * @param array $defaults Optional defaults.
 * @return array<string,mixed>
 */
function ltar_normalize_row( $row, $defaults = array() ) {
	$row      = is_array( $row ) ? $row : array();
	$defaults = is_array( $defaults ) ? $defaults : array();
	$service  = ltar_service_key( $row['service'] ?? ( $row['mode'] ?? ( $defaults['service'] ?? '' ) ) );
	$currency = strtoupper( ltar_text( $row['currency'] ?? ( $defaults['currency'] ?? 'USD' ) ) );
	$unit     = ltar_text( $row['unit'] ?? ( $defaults['unit'] ?? '' ) );

	$price_min = ltar_float_or_null( $row['price_min'] ?? null );
	$price_max = ltar_float_or_null( $row['price_max'] ?? null );
	$price_avg = ltar_float_or_null( $row['price_avg'] ?? ltar_average( $price_min, $price_max ) );

	$transit_min = ltar_int_or_null( $row['transit_min_days'] ?? null );
	$transit_max = ltar_int_or_null( $row['transit_max_days'] ?? null );
	$transit_avg = ltar_int_or_null( $row['transit_avg_days'] ?? ltar_average( $transit_min, $transit_max ) );

	$normalized = array(
		'route_key'           => ltar_build_route_key( $row ),
		'export_country'      => ltar_text( $row['export_country'] ?? '' ),
		'export_country_code' => ltar_country_code( $row['export_country_code'] ?? '' ),
		'export_city'         => ltar_text( $row['export_city'] ?? '' ),
		'import_country'      => ltar_text( $row['import_country'] ?? '' ),
		'import_country_code' => ltar_country_code( $row['import_country_code'] ?? '' ),
		'import_city'         => ltar_text( $row['import_city'] ?? '' ),
		'service'             => $service,
		'service_label'       => ltar_text( $row['service_label'] ?? ( $defaults['service_label'] ?? strtoupper( $service ) ) ),
		'unit'                => $unit,
		'currency'            => '' !== $currency ? $currency : 'USD',
		'price_min'           => $price_min,
		'price_max'           => $price_max,
		'price_avg'           => $price_avg,
		'transit_min_days'    => $transit_min,
		'transit_max_days'    => $transit_max,
		'transit_avg_days'    => $transit_avg,
		'based_on_route'      => ltar_text( $row['based_on_route'] ?? '' ),
		'based_on_scenario'   => sanitize_key( (string) ( $row['based_on_scenario'] ?? '' ) ),
		'reason'              => ltar_text( $row['reason'] ?? '' ),
		'price_source'        => ltar_text( $row['price_source'] ?? '' ),
		'notes'               => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
	);

	if ( '' === $normalized['based_on_scenario'] ) {
		$normalized['based_on_scenario'] = ltar_detect_row_scenario( $normalized );
	}

	if ( '' === $normalized['based_on_route'] ) {
		$from_label = '' !== $normalized['export_city'] ? $normalized['export_city'] : $normalized['export_country'];
		$to_label   = '' !== $normalized['import_city'] ? $normalized['import_city'] : $normalized['import_country'];

		if ( '' !== $from_label || '' !== $to_label ) {
			$normalized['based_on_route'] = trim( $from_label . ' -> ' . $to_label );
		}
	}

	return $normalized;
}

/**
 * Detect rows payload.
 *
 * @param mixed $payload Raw payload.
 * @return bool
 */
function ltar_payload_is_rows( $payload ) {
	if ( ! is_array( $payload ) || empty( $payload ) || $payload !== array_values( $payload ) ) {
		return false;
	}

	foreach ( $payload as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$has_import  = ! empty( $row['import_country'] ) || ! empty( $row['import_country_code'] );
		$has_service = ! empty( $row['service'] ) || ! empty( $row['mode'] );

		if ( $has_import && $has_service ) {
			return true;
		}
	}

	return false;
}

/**
 * Detect structured payload.
 *
 * @param mixed $payload Raw payload.
 * @return bool
 */
function ltar_payload_is_structured( $payload ) {
	return is_array( $payload )
		&& ! empty( $payload['country_only_defaults'] )
		&& is_array( $payload['country_only_defaults'] );
}

/**
 * Decode JSON string safely.
 *
 * @param string $raw Raw JSON.
 * @return array
 */
function ltar_decode_json( $raw ) {
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return array();
	}

	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Build export-country lookup.
 *
 * @param array $payload Source payload.
 * @return array<string,string>
 */
function ltar_export_country_lookup( $payload ) {
	$lookup = array(
		'CN' => 'China',
		'VN' => 'Vietnam',
	);

	if ( ! is_array( $payload ) ) {
		return $lookup;
	}

	if ( ! empty( $payload['export_countries'] ) && is_array( $payload['export_countries'] ) ) {
		foreach ( $payload['export_countries'] as $code => $row ) {
			$code = ltar_country_code( $code );
			if ( '' === $code ) {
				continue;
			}

			$name = '';
			if ( is_array( $row ) ) {
				$name = ltar_text( $row['name'] ?? '' );
			} elseif ( is_string( $row ) ) {
				$name = ltar_text( $row );
			}

			if ( '' !== $name ) {
				$lookup[ $code ] = $name;
			}
		}
	}

	return $lookup;
}

/**
 * Build import-country lookup with metadata.
 *
 * @param array $payload Source payload.
 * @return array<string,array<string,string>>
 */
function ltar_import_country_lookup( $payload ) {
	$lookup = array();

	if ( ! is_array( $payload ) ) {
		return $lookup;
	}

	if ( ! empty( $payload['import_countries'] ) && is_array( $payload['import_countries'] ) ) {
		foreach ( $payload['import_countries'] as $code => $row ) {
			$code = ltar_country_code( $code );
			if ( '' === $code || ! is_array( $row ) ) {
				continue;
			}

			$lookup[ $code ] = array(
				'name'                   => ltar_text( $row['name'] ?? '' ),
				'group'                  => sanitize_key( (string) ( $row['group'] ?? '' ) ),
				'default_city'           => ltar_text( $row['default_city'] ?? '' ),
				'default_export_country' => ltar_country_code( $row['default_export_country'] ?? '' ),
			);
		}
	}

	if ( ! empty( $payload['country_only_defaults'] ) && is_array( $payload['country_only_defaults'] ) ) {
		foreach ( $payload['country_only_defaults'] as $code => $row ) {
			$code = ltar_country_code( $code );
			if ( '' === $code ) {
				continue;
			}

			if ( ! isset( $lookup[ $code ] ) ) {
				$lookup[ $code ] = array(
					'name'                   => '',
					'group'                  => '',
					'default_city'           => '',
					'default_export_country' => '',
				);
			}

			if ( is_array( $row ) ) {
				if ( '' === $lookup[ $code ]['name'] && ! empty( $row['import_country'] ) ) {
					$lookup[ $code ]['name'] = ltar_text( $row['import_country'] );
				}
				if ( '' === $lookup[ $code ]['default_city'] ) {
					$lookup[ $code ]['default_city'] = ltar_text( $row['default_import_city'] ?? '' );
				}
				if ( '' === $lookup[ $code ]['default_export_country'] ) {
					$lookup[ $code ]['default_export_country'] = ltar_country_code( $row['default_export_country_code'] ?? ( $row['default_export_country'] ?? '' ) );
				}
			}
		}
	}

	return $lookup;
}

/**
 * Collect service definitions from payload.
 *
 * @param array $payload Source payload.
 * @return array<string,array<string,string>>
 */
function ltar_service_definitions( $payload ) {
	$definitions = array();

	if ( ! is_array( $payload ) ) {
		return $definitions;
	}

	if ( ! empty( $payload['service_labels'] ) && is_array( $payload['service_labels'] ) ) {
		foreach ( $payload['service_labels'] as $key => $label ) {
			$service = ltar_service_key( $key );
			if ( '' === $service ) {
				continue;
			}

			if ( ! isset( $definitions[ $service ] ) ) {
				$definitions[ $service ] = array(
					'service_label' => '',
					'unit'          => '',
				);
			}

			$definitions[ $service ]['service_label'] = ltar_text( $label );
		}
	}

	if ( ! empty( $payload['units'] ) && is_array( $payload['units'] ) ) {
		foreach ( $payload['units'] as $key => $unit ) {
			$service = ltar_service_key( $key );
			if ( '' === $service ) {
				continue;
			}

			if ( ! isset( $definitions[ $service ] ) ) {
				$definitions[ $service ] = array(
					'service_label' => '',
					'unit'          => '',
				);
			}

			$definitions[ $service ]['unit'] = ltar_text( $unit );
		}
	}

	if ( ! empty( $payload['services'] ) && is_array( $payload['services'] ) ) {
		foreach ( $payload['services'] as $key => $row ) {
			$service = ltar_service_key( $key );
			if ( '' === $service ) {
				continue;
			}

			if ( ! isset( $definitions[ $service ] ) ) {
				$definitions[ $service ] = array(
					'service_label' => '',
					'unit'          => '',
				);
			}

			if ( is_array( $row ) ) {
				if ( '' === $definitions[ $service ]['service_label'] ) {
					$definitions[ $service ]['service_label'] = ltar_text( $row['label'] ?? '' );
				}
				if ( '' === $definitions[ $service ]['unit'] ) {
					$definitions[ $service ]['unit'] = ltar_text( $row['unit'] ?? '' );
				}
			}
		}
	}

	return $definitions;
}

/**
 * Build pair map from structured payload.
 *
 * @param array $payload          Source payload.
 * @param array $import_countries Import metadata.
 * @return array<string,array<string,mixed>>
 */
function ltar_structured_pair_map( $payload, $import_countries ) {
	$pairs = array();

	if ( ! is_array( $payload ) ) {
		return $pairs;
	}

	if ( ! empty( $payload['country_pairs'] ) && is_array( $payload['country_pairs'] ) ) {
		foreach ( $payload['country_pairs'] as $pair_key => $pair_row ) {
			if ( ! is_array( $pair_row ) ) {
				continue;
			}

			$export_code = '';
			$import_code = '';

			if ( preg_match( '/^([A-Z]{2})[_-]([A-Z]{2})$/', strtoupper( (string) $pair_key ), $matches ) ) {
				$export_code = $matches[1];
				$import_code = $matches[2];
			}

			$export_code = ltar_country_code( $pair_row['export_country_code'] ?? $export_code );
			$import_code = ltar_country_code( $pair_row['import_country_code'] ?? $import_code );

			if ( '' === $export_code || '' === $import_code || empty( $pair_row['services'] ) || ! is_array( $pair_row['services'] ) ) {
				continue;
			}

			$pairs[ $export_code . '_' . $import_code ] = array(
				'export_code' => $export_code,
				'import_code' => $import_code,
				'services'    => $pair_row['services'],
				'label'       => ltar_text( $pair_row['label'] ?? '' ),
			);
		}
	}

	if ( ! empty( $pairs ) ) {
		return $pairs;
	}

	if ( empty( $payload['lane_matrix'] ) || ! is_array( $payload['lane_matrix'] ) ) {
		return $pairs;
	}

	foreach ( $payload['lane_matrix'] as $export_code_raw => $groups ) {
		$export_code = ltar_country_code( $export_code_raw );

		if ( '' === $export_code || ! is_array( $groups ) ) {
			continue;
		}

		foreach ( $import_countries as $import_code => $import_config ) {
			$group = sanitize_key( (string) ( $import_config['group'] ?? '' ) );

			if ( '' === $group || empty( $groups[ $group ] ) || ! is_array( $groups[ $group ] ) ) {
				continue;
			}

			$pairs[ $export_code . '_' . $import_code ] = array(
				'export_code' => $export_code,
				'import_code' => $import_code,
				'services'    => $groups[ $group ],
				'label'       => '',
			);
		}
	}

	return $pairs;
}

/**
 * Collect import cities for a structured import country.
 *
 * @param array  $payload      Source payload.
 * @param string $import_code  Country code.
 * @param string $default_city Default city.
 * @return array<string,array<string,int|float>>
 */
function ltar_collect_import_cities( $payload, $import_code, $default_city ) {
	$cities = array();

	if ( ! empty( $payload['import_city_modifiers'][ $import_code ] ) && is_array( $payload['import_city_modifiers'][ $import_code ] ) ) {
		foreach ( $payload['import_city_modifiers'][ $import_code ] as $city_name => $modifier ) {
			$city_name = ltar_text( $city_name );
			if ( '' === $city_name ) {
				continue;
			}
			$cities[ $city_name ] = is_array( $modifier ) ? $modifier : array();
		}
	}

	$default_city = ltar_text( $default_city );
	if ( '' !== $default_city && ! isset( $cities[ $default_city ] ) ) {
		$cities[ $default_city ] = array(
			'price_delta_percent' => 0,
			'days_delta'          => 0,
		);
	}

	return $cities;
}

/**
 * Collect export cities for a structured export country.
 *
 * @param array  $payload     Source payload.
 * @param string $export_code Country code.
 * @return array<string,array<string,int|float>>
 */
function ltar_collect_export_cities( $payload, $export_code ) {
	$cities = array();

	if ( ! empty( $payload['export_city_modifiers'][ $export_code ] ) && is_array( $payload['export_city_modifiers'][ $export_code ] ) ) {
		foreach ( $payload['export_city_modifiers'][ $export_code ] as $city_name => $modifier ) {
			$city_name = ltar_text( $city_name );
			if ( '' === $city_name ) {
				continue;
			}
			$cities[ $city_name ] = is_array( $modifier ) ? $modifier : array();
		}
	}

	return $cities;
}

/**
 * Apply price/day modifiers to a base rate row.
 *
 * @param array  $base_row         Base service row.
 * @param array  $export_modifier  Export-city modifier.
 * @param array  $import_modifier  Import-city modifier.
 * @param string $currency_default Default currency.
 * @param string $unit_default     Default unit.
 * @return array<string,mixed>
 */
function ltar_apply_modifiers_to_rate( $base_row, $export_modifier, $import_modifier, $currency_default, $unit_default ) {
	$base_row        = is_array( $base_row ) ? $base_row : array();
	$export_modifier = is_array( $export_modifier ) ? $export_modifier : array();
	$import_modifier = is_array( $import_modifier ) ? $import_modifier : array();

	$price_delta = (float) ( $export_modifier['price_delta_percent'] ?? 0 ) + (float) ( $import_modifier['price_delta_percent'] ?? 0 );
	$days_delta  = (int) round( (float) ( $export_modifier['days_delta'] ?? 0 ) + (float) ( $import_modifier['days_delta'] ?? 0 ) );

	$price_min = ltar_float_or_null( $base_row['price_min'] ?? null );
	$price_max = ltar_float_or_null( $base_row['price_max'] ?? null );
	$price_avg = ltar_float_or_null( $base_row['price_avg'] ?? null );

	if ( null !== $price_min ) {
		$price_min = round( $price_min * ( 1 + ( $price_delta / 100 ) ), 2 );
	}
	if ( null !== $price_max ) {
		$price_max = round( $price_max * ( 1 + ( $price_delta / 100 ) ), 2 );
	}
	if ( null !== $price_avg ) {
		$price_avg = round( $price_avg * ( 1 + ( $price_delta / 100 ) ), 2 );
	}
	if ( null === $price_avg ) {
		$price_avg = ltar_average( $price_min, $price_max );
	}

	$transit_min = ltar_int_or_null( $base_row['transit_min_days'] ?? null );
	$transit_max = ltar_int_or_null( $base_row['transit_max_days'] ?? null );
	$transit_avg = ltar_int_or_null( $base_row['transit_avg_days'] ?? null );

	if ( null !== $transit_min ) {
		$transit_min = max( 0, $transit_min + $days_delta );
	}
	if ( null !== $transit_max ) {
		$transit_max = max( 0, $transit_max + $days_delta );
	}
	if ( null !== $transit_avg ) {
		$transit_avg = max( 0, $transit_avg + $days_delta );
	}
	if ( null === $transit_avg ) {
		$transit_avg = ltar_average( $transit_min, $transit_max );
	}

	return array(
		'price_min'        => $price_min,
		'price_max'        => $price_max,
		'price_avg'        => $price_avg,
		'transit_min_days' => $transit_min,
		'transit_max_days' => $transit_max,
		'transit_avg_days' => $transit_avg,
		'currency'         => strtoupper( ltar_text( $base_row['currency'] ?? $currency_default ) ),
		'unit'             => ltar_text( $base_row['unit'] ?? $unit_default ),
	);
}

/**
 * Expand structured JSON payload into flat route rows.
 *
 * @param array $payload Structured payload.
 * @return array<int,array<string,mixed>>
 */
function ltar_expand_structured_payload( $payload ) {
	$payload           = is_array( $payload ) ? $payload : array();
	$currency_default  = strtoupper( ltar_text( $payload['currency_default'] ?? 'USD' ) );
	$service_defs      = ltar_service_definitions( $payload );
	$import_lookup     = ltar_import_country_lookup( $payload );
	$export_lookup     = ltar_export_country_lookup( $payload );
	$pair_map          = ltar_structured_pair_map( $payload, $import_lookup );
	$rows_map          = array();

	if ( ! empty( $payload['country_only_defaults'] ) && is_array( $payload['country_only_defaults'] ) ) {
		foreach ( $payload['country_only_defaults'] as $import_code_raw => $config ) {
			$import_code = ltar_country_code( $import_code_raw );

			if ( '' === $import_code || ! is_array( $config ) || empty( $config['services'] ) || ! is_array( $config['services'] ) ) {
				continue;
			}

			$import_name       = ltar_text( $config['import_country'] ?? ( $import_lookup[ $import_code ]['name'] ?? '' ) );
			$default_import    = ltar_text( $config['default_import_city'] ?? ( $import_lookup[ $import_code ]['default_city'] ?? '' ) );
			$default_export    = ltar_country_code( $config['default_export_country_code'] ?? ( $config['default_export_country'] ?? ( $import_lookup[ $import_code ]['default_export_country'] ?? '' ) ) );
			$default_export_nm = '' !== $default_export ? ( $export_lookup[ $default_export ] ?? $default_export ) : '';
			$scenario          = sanitize_key( (string) ( $config['based_on_scenario'] ?? 'import_country_only' ) );
			$reason            = ltar_text( $config['reason'] ?? 'default_market_reference' );

			foreach ( $config['services'] as $service_key_raw => $service_row ) {
				if ( ! is_array( $service_row ) ) {
					continue;
				}

				$service_key = ltar_service_key( $service_key_raw );
				if ( '' === $service_key ) {
					continue;
				}

				$service_defaults = $service_defs[ $service_key ] ?? array();
				$rate             = ltar_apply_modifiers_to_rate( $service_row, array(), array(), $currency_default, $service_defaults['unit'] ?? '' );
				$row              = array(
					'export_country'      => $default_export_nm,
					'export_country_code' => $default_export,
					'export_city'         => '',
					'import_country'      => $import_name,
					'import_country_code' => $import_code,
					'import_city'         => $default_import,
					'service'             => $service_key,
					'service_label'       => $service_defaults['service_label'] ?? '',
					'unit'                => $service_defaults['unit'] ?? '',
					'currency'            => $rate['currency'],
					'price_min'           => $rate['price_min'],
					'price_max'           => $rate['price_max'],
					'price_avg'           => $rate['price_avg'],
					'transit_min_days'    => $rate['transit_min_days'],
					'transit_max_days'    => $rate['transit_max_days'],
					'transit_avg_days'    => $rate['transit_avg_days'],
					'based_on_scenario'   => '' !== $scenario ? $scenario : 'import_country_only',
					'based_on_route'      => ltar_text( $service_row['based_on_route'] ?? ( $config['based_on_route'] ?? '' ) ),
					'reason'              => $reason,
					'price_source'        => ltar_text( $service_row['price_source'] ?? 'country_only_default' ),
				);

				$normalized = ltar_normalize_row( $row );
				$signature  = implode(
					'|',
					array(
						$normalized['export_country_code'],
						$normalized['export_city'],
						$normalized['import_country_code'],
						$normalized['import_city'],
						$normalized['service'],
					)
				);

				$rows_map[ $signature ] = $normalized;
			}
		}
	}

	foreach ( $pair_map as $pair ) {
		$export_code  = $pair['export_code'] ?? '';
		$import_code  = $pair['import_code'] ?? '';
		$services     = $pair['services'] ?? array();

		if ( '' === $export_code || '' === $import_code || empty( $services ) || ! is_array( $services ) ) {
			continue;
		}

		$export_name   = $export_lookup[ $export_code ] ?? $export_code;
		$import_name   = $import_lookup[ $import_code ]['name'] ?? $import_code;
		$default_city  = $import_lookup[ $import_code ]['default_city'] ?? '';
		$export_cities = ltar_collect_export_cities( $payload, $export_code );
		$import_cities = ltar_collect_import_cities( $payload, $import_code, $default_city );

		foreach ( $services as $service_key_raw => $service_row ) {
			if ( ! is_array( $service_row ) ) {
				continue;
			}

			$service_key = ltar_service_key( $service_key_raw );
			if ( '' === $service_key ) {
				continue;
			}

			$service_defaults = $service_defs[ $service_key ] ?? array();
			$base_rate        = ltar_apply_modifiers_to_rate( $service_row, array(), array(), $currency_default, $service_defaults['unit'] ?? '' );
			$base_reason      = ltar_text( $service_row['reason'] ?? '' );
			if ( '' === $base_reason ) {
				$base_reason = 'country_pair_stub';
			} elseif ( false === strpos( $base_reason, 'country_pair_stub' ) ) {
				$base_reason .= ',country_pair_stub';
			}

			$base_row = array(
				'export_country'      => $export_name,
				'export_country_code' => $export_code,
				'export_city'         => '',
				'import_country'      => $import_name,
				'import_country_code' => $import_code,
				'import_city'         => '',
				'service'             => $service_key,
				'service_label'       => $service_defaults['service_label'] ?? '',
				'unit'                => $service_defaults['unit'] ?? '',
				'currency'            => $base_rate['currency'],
				'price_min'           => $base_rate['price_min'],
				'price_max'           => $base_rate['price_max'],
				'price_avg'           => $base_rate['price_avg'],
				'transit_min_days'    => $base_rate['transit_min_days'],
				'transit_max_days'    => $base_rate['transit_max_days'],
				'transit_avg_days'    => $base_rate['transit_avg_days'],
				'based_on_scenario'   => 'country_to_country',
				'reason'              => $base_reason,
				'price_source'        => ltar_text( $service_row['price_source'] ?? 'country_pair_stub' ),
			);

			$normalized = ltar_normalize_row( $base_row );
			$signature  = implode(
				'|',
				array(
					$normalized['export_country_code'],
					$normalized['export_city'],
					$normalized['import_country_code'],
					$normalized['import_city'],
					$normalized['service'],
				)
			);

			$rows_map[ $signature ] = $normalized;
			$variants         = array();

			if ( empty( $export_cities ) && empty( $import_cities ) ) {
				$variants[] = array(
					'export_city'       => '',
					'import_city'       => '',
					'export_modifier'   => array(),
					'import_modifier'   => array(),
					'based_on_scenario' => 'country_to_country',
				);
			} elseif ( empty( $export_cities ) ) {
				foreach ( $import_cities as $import_city => $import_modifier ) {
					$variants[] = array(
						'export_city'       => '',
						'import_city'       => $import_city,
						'export_modifier'   => array(),
						'import_modifier'   => $import_modifier,
						'based_on_scenario' => 'country_to_city',
					);
				}
			} elseif ( empty( $import_cities ) ) {
				foreach ( $export_cities as $export_city => $export_modifier ) {
					$variants[] = array(
						'export_city'       => $export_city,
						'import_city'       => '',
						'export_modifier'   => $export_modifier,
						'import_modifier'   => array(),
						'based_on_scenario' => 'city_to_country',
					);
				}
			} else {
				foreach ( $export_cities as $export_city => $export_modifier ) {
					foreach ( $import_cities as $import_city => $import_modifier ) {
						$variants[] = array(
							'export_city'       => $export_city,
							'import_city'       => $import_city,
							'export_modifier'   => $export_modifier,
							'import_modifier'   => $import_modifier,
							'based_on_scenario' => 'city_to_city',
						);
					}
				}
			}

			foreach ( $variants as $variant ) {
				$rate = ltar_apply_modifiers_to_rate(
					$service_row,
					$variant['export_modifier'],
					$variant['import_modifier'],
					$currency_default,
					$service_defaults['unit'] ?? ''
				);

				$row = array(
					'export_country'      => $export_name,
					'export_country_code' => $export_code,
					'export_city'         => $variant['export_city'],
					'import_country'      => $import_name,
					'import_country_code' => $import_code,
					'import_city'         => $variant['import_city'],
					'service'             => $service_key,
					'service_label'       => $service_defaults['service_label'] ?? '',
					'unit'                => $service_defaults['unit'] ?? '',
					'currency'            => $rate['currency'],
					'price_min'           => $rate['price_min'],
					'price_max'           => $rate['price_max'],
					'price_avg'           => $rate['price_avg'],
					'transit_min_days'    => $rate['transit_min_days'],
					'transit_max_days'    => $rate['transit_max_days'],
					'transit_avg_days'    => $rate['transit_avg_days'],
					'based_on_scenario'   => $variant['based_on_scenario'],
					'price_source'        => ltar_text( $service_row['price_source'] ?? 'erp_import' ),
				);

				$normalized = ltar_normalize_row( $row );
				$signature  = implode(
					'|',
					array(
						$normalized['export_country_code'],
						$normalized['export_city'],
						$normalized['import_country_code'],
						$normalized['import_city'],
						$normalized['service'],
					)
				);

				$rows_map[ $signature ] = $normalized;
			}
		}
	}

	return array_values( $rows_map );
}

/**
 * Convert any supported payload into flat route rows.
 *
 * @param array $payload Source payload.
 * @return array<int,array<string,mixed>>
 */
function ltar_rows_from_payload( $payload ) {
	$payload = is_array( $payload ) ? $payload : array();

	if ( isset( $payload['rows'] ) && is_array( $payload['rows'] ) && ltar_payload_is_rows( $payload['rows'] ) ) {
		$payload = $payload['rows'];
	}

	if ( ltar_payload_is_rows( $payload ) ) {
		$rows = array();

		foreach ( $payload as $row ) {
			$normalized = ltar_normalize_row( $row );
			if ( '' === $normalized['service'] || '' === $normalized['import_country_code'] ) {
				continue;
			}
			$rows[] = $normalized;
		}

		return $rows;
	}

	if ( ltar_payload_is_structured( $payload ) ) {
		return ltar_expand_structured_payload( $payload );
	}

	return array();
}

/**
 * Prepare DB rows for REST/admin output.
 *
 * @param array $rows Raw DB rows.
 * @return array<int,array<string,mixed>>
 */
function ltar_prepare_rows_for_output( $rows ) {
	$out = array();

	foreach ( (array) $rows as $row ) {
		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}
		if ( ! is_array( $row ) ) {
			continue;
		}

		$out[] = array(
			'id'                  => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'route_key'           => ltar_text( $row['route_key'] ?? '' ),
			'export_country'      => ltar_text( $row['export_country'] ?? '' ),
			'export_country_code' => ltar_country_code( $row['export_country_code'] ?? '' ),
			'export_city'         => ltar_text( $row['export_city'] ?? '' ),
			'import_country'      => ltar_text( $row['import_country'] ?? '' ),
			'import_country_code' => ltar_country_code( $row['import_country_code'] ?? '' ),
			'import_city'         => ltar_text( $row['import_city'] ?? '' ),
			'service'             => ltar_service_key( $row['service'] ?? '' ),
			'service_label'       => ltar_text( $row['service_label'] ?? '' ),
			'unit'                => ltar_text( $row['unit'] ?? '' ),
			'currency'            => strtoupper( ltar_text( $row['currency'] ?? 'USD' ) ),
			'price_min'           => ltar_float_or_null( $row['price_min'] ?? null ),
			'price_max'           => ltar_float_or_null( $row['price_max'] ?? null ),
			'price_avg'           => ltar_float_or_null( $row['price_avg'] ?? null ),
			'transit_min_days'    => ltar_int_or_null( $row['transit_min_days'] ?? null ),
			'transit_max_days'    => ltar_int_or_null( $row['transit_max_days'] ?? null ),
			'transit_avg_days'    => ltar_int_or_null( $row['transit_avg_days'] ?? null ),
			'based_on_route'      => ltar_text( $row['based_on_route'] ?? '' ),
			'based_on_scenario'   => sanitize_key( (string) ( $row['based_on_scenario'] ?? '' ) ),
			'reason'              => ltar_text( $row['reason'] ?? '' ),
			'price_source'        => ltar_text( $row['price_source'] ?? '' ),
			'notes'               => sanitize_textarea_field( (string) ( $row['notes'] ?? '' ) ),
			'updated_at_gmt'      => ltar_text( $row['updated_at_gmt'] ?? '' ),
		);
	}

	return $out;
}

/**
 * Normalize string for matching.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function ltar_match_string( $value ) {
	$value = remove_accents( (string) $value );
	$value = strtolower( trim( $value ) );

	if ( '' === $value ) {
		return '';
	}

	$value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
	$value = preg_replace( '/\s+/', ' ', (string) $value );

	return trim( (string) $value );
}

/**
 * Build match variants for city aliases.
 *
 * @param mixed $value Raw city value.
 * @return array<int,string>
 */
function ltar_city_match_variants( $value ) {
	$base = ltar_match_string( $value );
	if ( '' === $base ) {
		return array();
	}

	$variants = array(
		$base => $base,
	);

	$without_city = preg_replace( '/\bcity$/', '', $base );
	$without_city = trim( preg_replace( '/\s+/', ' ', (string) $without_city ) );
	if ( '' !== $without_city ) {
		$variants[ $without_city ] = $without_city;
	}

	return array_values( $variants );
}

/**
 * Compare city labels allowing simple aliases like "City" suffix.
 *
 * @param mixed $left  First city value.
 * @param mixed $right Second city value.
 * @return bool
 */
function ltar_city_matches( $left, $right ) {
	$left_variants  = ltar_city_match_variants( $left );
	$right_variants = ltar_city_match_variants( $right );

	if ( empty( $left_variants ) || empty( $right_variants ) ) {
		return false;
	}

	return ! empty( array_intersect( $left_variants, $right_variants ) );
}

/**
 * Find country code by exact country name.
 *
 * @param string $name   Country name.
 * @param array  $lookup Code => name map.
 * @return string
 */
function ltar_find_country_code_by_name( $name, $lookup ) {
	$name = ltar_match_string( $name );
	if ( '' === $name || ! is_array( $lookup ) ) {
		return '';
	}

	foreach ( $lookup as $code => $label ) {
		if ( ltar_match_string( $label ) === $name ) {
			return strtoupper( trim( (string) $code ) );
		}
	}

	return '';
}

/**
 * Check whether row has any numeric metrics.
 *
 * @param array $row Prepared row.
 * @return bool
 */
function ltar_has_numeric_metrics( $row ) {
	$row = is_array( $row ) ? $row : array();

	foreach ( array( 'price_min', 'price_max', 'transit_min_days', 'transit_max_days' ) as $field ) {
		if ( is_numeric( $row[ $field ] ?? null ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Split reason string into unique tokens.
 *
 * @param mixed $value Raw reason value.
 * @return array<int,string>
 */
function ltar_reason_tokens( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return array();
	}

	$tokens = array();
	foreach ( explode( ',', $value ) as $item ) {
		$item = trim( (string) $item );
		if ( '' !== $item ) {
			$tokens[ $item ] = $item;
		}
	}

	return array_values( $tokens );
}

/**
 * Resolve route scenario from prepared row.
 *
 * @param array $row Prepared row.
 * @return string
 */
function ltar_resolve_row_scenario( $row ) {
	$row = is_array( $row ) ? $row : array();

	$scenario = sanitize_key( (string) ( $row['based_on_scenario'] ?? '' ) );
	if ( '' !== $scenario ) {
		return $scenario;
	}

	$has_export_country = ! empty( $row['export_country_code'] ) || ! empty( $row['export_country'] );
	$has_import_country = ! empty( $row['import_country_code'] ) || ! empty( $row['import_country'] );
	$has_export_city    = '' !== trim( (string) ( $row['export_city'] ?? '' ) );
	$has_import_city    = '' !== trim( (string) ( $row['import_city'] ?? '' ) );

	if ( $has_import_country && ! $has_export_country && ! $has_export_city && ! $has_import_city ) {
		return 'import_country_only';
	}
	if ( $has_export_country && $has_import_country && $has_export_city && $has_import_city ) {
		return 'city_to_city';
	}
	if ( $has_export_country && $has_import_country && $has_export_city ) {
		return 'city_to_country';
	}
	if ( $has_export_country && $has_import_country && $has_import_city ) {
		return 'country_to_city';
	}
	if ( $has_export_country && $has_import_country ) {
		return 'country_to_country';
	}

	return '';
}

/**
 * Check whether row should be treated as country-only fallback.
 *
 * @param array $row Prepared row.
 * @return bool
 */
function ltar_is_country_only_fallback_row( $row ) {
	$row = is_array( $row ) ? $row : array();

	if ( 'import_country_only' === ltar_resolve_row_scenario( $row ) ) {
		return true;
	}

	$price_source = strtolower( trim( (string) ( $row['price_source'] ?? '' ) ) );
	if ( 'country_only_default' === $price_source ) {
		return true;
	}

	foreach ( array( 'default_market_reference', 'country_only_service_aggregate', 'fallback_country_only' ) as $token ) {
		if ( in_array( $token, ltar_reason_tokens( $row['reason'] ?? '' ), true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Aggregate a set of prepared rows into one resolved layer.
 *
 * @param array $rows Prepared rows.
 * @return array<string,mixed>
 */
function ltar_aggregate_rows( $rows ) {
	$rows = is_array( $rows ) ? array_values( $rows ) : array();
	if ( empty( $rows ) ) {
		return array();
	}

	$first             = $rows[0];
	$price_min         = null;
	$price_max         = null;
	$transit_min       = null;
	$transit_max       = null;
	$currency          = '';
	$unit              = '';
	$service_label     = '';
	$based_on_route    = '';
	$based_on_scenario = '';
	$reason_tokens     = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		if ( is_numeric( $row['price_min'] ?? null ) ) {
			$candidate = (float) $row['price_min'];
			$price_min = ( null === $price_min ) ? $candidate : min( $price_min, $candidate );
		}
		if ( is_numeric( $row['price_max'] ?? null ) ) {
			$candidate = (float) $row['price_max'];
			$price_max = ( null === $price_max ) ? $candidate : max( $price_max, $candidate );
		}
		if ( is_numeric( $row['transit_min_days'] ?? null ) ) {
			$candidate   = (int) $row['transit_min_days'];
			$transit_min = ( null === $transit_min ) ? $candidate : min( $transit_min, $candidate );
		}
		if ( is_numeric( $row['transit_max_days'] ?? null ) ) {
			$candidate   = (int) $row['transit_max_days'];
			$transit_max = ( null === $transit_max ) ? $candidate : max( $transit_max, $candidate );
		}

		if ( '' === $currency && ! empty( $row['currency'] ) ) {
			$currency = trim( (string) $row['currency'] );
		}
		if ( '' === $unit && ! empty( $row['unit'] ) ) {
			$unit = trim( (string) $row['unit'] );
		}
		if ( '' === $service_label && ! empty( $row['service_label'] ) ) {
			$service_label = trim( (string) $row['service_label'] );
		}
		if ( '' === $based_on_route && ! empty( $row['based_on_route'] ) ) {
			$based_on_route = trim( (string) $row['based_on_route'] );
		}
		if ( '' === $based_on_scenario ) {
			$based_on_scenario = ltar_resolve_row_scenario( $row );
		}

		foreach ( ltar_reason_tokens( $row['reason'] ?? '' ) as $token ) {
			$reason_tokens[ $token ] = $token;
		}
	}

	return array(
		'export_country'      => trim( (string) ( $first['export_country'] ?? '' ) ),
		'export_country_code' => strtoupper( trim( (string) ( $first['export_country_code'] ?? '' ) ) ),
		'export_city'         => trim( (string) ( $first['export_city'] ?? '' ) ),
		'import_country'      => trim( (string) ( $first['import_country'] ?? '' ) ),
		'import_country_code' => strtoupper( trim( (string) ( $first['import_country_code'] ?? '' ) ) ),
		'import_city'         => trim( (string) ( $first['import_city'] ?? '' ) ),
		'service_label'       => $service_label,
		'price_min'           => $price_min,
		'price_max'           => $price_max,
		'transit_min_days'    => $transit_min,
		'transit_max_days'    => $transit_max,
		'currency'            => $currency,
		'unit'                => $unit,
		'based_on_route'      => $based_on_route,
		'based_on_scenario'   => $based_on_scenario,
		'reason'              => implode( ',', array_values( $reason_tokens ) ),
	);
}

/**
 * Fill missing metrics from fallback layer.
 *
 * @param array $base     Primary layer.
 * @param array $fallback Fallback layer.
 * @return array{0:array,1:bool}
 */
function ltar_merge_metrics( $base, $fallback ) {
	$base     = is_array( $base ) ? $base : array();
	$fallback = is_array( $fallback ) ? $fallback : array();

	if ( empty( $fallback ) ) {
		return array( $base, false );
	}

	$used = false;

	foreach ( array( 'price_min', 'price_max', 'transit_min_days', 'transit_max_days' ) as $field ) {
		if ( ! is_numeric( $base[ $field ] ?? null ) && is_numeric( $fallback[ $field ] ?? null ) ) {
			$base[ $field ] = $fallback[ $field ];
			$used           = true;
		}
	}

	foreach (
		array(
			'currency',
			'unit',
			'service_label',
			'based_on_route',
			'based_on_scenario',
			'export_country',
			'export_country_code',
			'export_city',
			'import_country',
			'import_country_code',
			'import_city',
		) as $field
	) {
		if ( '' === trim( (string) ( $base[ $field ] ?? '' ) ) && '' !== trim( (string) ( $fallback[ $field ] ?? '' ) ) ) {
			$base[ $field ] = $fallback[ $field ];
			$used           = true;
		}
	}

	if ( $used && '' === trim( (string) ( $base['reason'] ?? '' ) ) && '' !== trim( (string) ( $fallback['reason'] ?? '' ) ) ) {
		$base['reason'] = trim( (string) $fallback['reason'] );
	}

	return array( $base, $used );
}

/**
 * Resolve one tariff request against prepared ERP rows.
 *
 * @param array $request Raw request.
 * @param array $rows    Optional prepared rows.
 * @return array<string,mixed>
 */
function ltar_resolve_catalog_request( $request, $rows = array() ) {
	$request = is_array( $request ) ? $request : array();
	$rows    = is_array( $rows ) ? $rows : array();

	if ( empty( $rows ) ) {
		$rows = ltar_prepare_rows_for_output( LTAR_DB::get_rows() );
	}
	if ( empty( $rows ) ) {
		return array();
	}

	$service = ltar_service_key( $request['service'] ?? '' );
	if ( '' === $service ) {
		return array();
	}

	$scenario    = sanitize_key( (string) ( $request['scenario'] ?? '' ) );
	$import_code = strtoupper( trim( (string) ( $request['import_country_code'] ?? '' ) ) );
	$export_code = strtoupper( trim( (string) ( $request['export_country_code'] ?? '' ) ) );
	$import_name = trim( (string) ( $request['import_country'] ?? '' ) );
	$export_name = trim( (string) ( $request['export_country'] ?? '' ) );
	$import_city = trim( (string) ( $request['import_city'] ?? '' ) );
	$export_city = trim( (string) ( $request['export_city'] ?? '' ) );

	$import_lookup = array();
	$export_lookup = array();
	$prepared      = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$row_service = ltar_service_key( $row['service'] ?? '' );
		if ( $row_service !== $service ) {
			continue;
		}

		$row_import_code = strtoupper( trim( (string) ( $row['import_country_code'] ?? '' ) ) );
		$row_export_code = strtoupper( trim( (string) ( $row['export_country_code'] ?? '' ) ) );
		$row_import_name = trim( (string) ( $row['import_country'] ?? '' ) );
		$row_export_name = trim( (string) ( $row['export_country'] ?? '' ) );

		if ( '' !== $row_import_code && '' !== $row_import_name ) {
			$import_lookup[ $row_import_code ] = $row_import_name;
		}
		if ( '' !== $row_export_code && '' !== $row_export_name ) {
			$export_lookup[ $row_export_code ] = $row_export_name;
		}

		$prepared[] = array(
			'export_country'      => $row_export_name,
			'export_country_code' => $row_export_code,
			'export_city'         => trim( (string) ( $row['export_city'] ?? '' ) ),
			'import_country'      => $row_import_name,
			'import_country_code' => $row_import_code,
			'import_city'         => trim( (string) ( $row['import_city'] ?? '' ) ),
			'service_label'       => trim( (string) ( $row['service_label'] ?? '' ) ),
			'price_min'           => is_numeric( $row['price_min'] ?? null ) ? (float) $row['price_min'] : null,
			'price_max'           => is_numeric( $row['price_max'] ?? null ) ? (float) $row['price_max'] : null,
			'transit_min_days'    => is_numeric( $row['transit_min_days'] ?? null ) ? (int) $row['transit_min_days'] : null,
			'transit_max_days'    => is_numeric( $row['transit_max_days'] ?? null ) ? (int) $row['transit_max_days'] : null,
			'currency'            => trim( (string) ( $row['currency'] ?? '' ) ),
			'unit'                => trim( (string) ( $row['unit'] ?? '' ) ),
			'based_on_route'      => trim( (string) ( $row['based_on_route'] ?? '' ) ),
			'based_on_scenario'   => ltar_resolve_row_scenario( $row ),
			'reason'              => trim( (string) ( $row['reason'] ?? '' ) ),
			'price_source'        => trim( (string) ( $row['price_source'] ?? '' ) ),
		);
	}

	if ( empty( $prepared ) ) {
		return array();
	}

	if ( '' === $scenario ) {
		$has_export_city = '' !== $export_city;
		$has_import_city = '' !== $import_city;
		$has_export      = '' !== $export_code || '' !== $export_name;
		if ( $has_export_city && $has_import_city ) {
			$scenario = 'city_to_city';
		} elseif ( $has_export_city && $has_export ) {
			$scenario = 'city_to_country';
		} elseif ( $has_import_city && $has_export ) {
			$scenario = 'country_to_city';
		} elseif ( $has_export ) {
			$scenario = 'country_to_country';
		} else {
			$scenario = 'import_country_only';
		}
	}

	if ( '' === $import_code ) {
		$raw = strtoupper( trim( (string) $import_name ) );
		if ( '' !== $raw && preg_match( '/^[A-Z]{2}$/', $raw ) ) {
			$import_code = $raw;
		} else {
			$import_code = ltar_find_country_code_by_name( $import_name, $import_lookup );
		}
	}
	if ( '' === $export_code ) {
		$raw = strtoupper( trim( (string) $export_name ) );
		if ( '' !== $raw && preg_match( '/^[A-Z]{2}$/', $raw ) ) {
			$export_code = $raw;
		} else {
			$export_code = ltar_find_country_code_by_name( $export_name, $export_lookup );
		}
	}

	$import_name_norm = ltar_match_string( $import_name );
	$export_name_norm = ltar_match_string( $export_name );
	$country_rows     = array();

	foreach ( $prepared as $row ) {
		$import_ok = true;
		if ( '' !== $import_code ) {
			$import_ok = $row['import_country_code'] === $import_code;
		} elseif ( '' !== $import_name_norm ) {
			$import_ok = ltar_match_string( $row['import_country'] ) === $import_name_norm;
		}
		if ( ! $import_ok ) {
			continue;
		}

		$export_required = 'import_country_only' !== $scenario;
		$is_country_only = ltar_is_country_only_fallback_row( $row );
		if ( $export_required && ! $is_country_only ) {
			$export_ok = true;
			if ( '' !== $export_code ) {
				$export_ok = $row['export_country_code'] === $export_code;
			} elseif ( '' !== $export_name_norm ) {
				$export_ok = ltar_match_string( $row['export_country'] ) === $export_name_norm;
			}
			if ( ! $export_ok ) {
				continue;
			}
		}

		$country_rows[] = $row;
	}

	if ( empty( $country_rows ) ) {
		return array();
	}

	$country_only_rows = array();
	$pair_stub_rows    = array();
	$city_rows         = array();

	foreach ( $country_rows as $row ) {
		if ( ltar_is_country_only_fallback_row( $row ) ) {
			$country_only_rows[] = $row;
			continue;
		}

		$has_export_city = '' !== trim( (string) ( $row['export_city'] ?? '' ) );
		$has_import_city = '' !== trim( (string) ( $row['import_city'] ?? '' ) );

		if ( ! $has_export_city && ! $has_import_city ) {
			$pair_stub_rows[] = $row;
			continue;
		}

		$city_rows[] = $row;
	}

	$export_city_norm   = ltar_match_string( $export_city );
	$import_city_norm   = ltar_match_string( $import_city );
	$unknown_export     = false;
	$unknown_import     = false;
	$exact_rows         = array();

	switch ( $scenario ) {
		case 'city_to_city':
			$export_scope = $city_rows;
			if ( '' !== $export_city_norm ) {
				$export_scope = array();
				foreach ( $city_rows as $row ) {
					if ( ltar_city_matches( $row['export_city'], $export_city ) ) {
						$export_scope[] = $row;
					}
				}
				if ( empty( $export_scope ) ) {
					$unknown_export = true;
				}
			}

			if ( ! empty( $export_scope ) ) {
				$exact_rows = $export_scope;
				if ( '' !== $import_city_norm ) {
					$tmp = array();
					foreach ( $export_scope as $row ) {
						if ( ltar_city_matches( $row['import_city'], $import_city ) ) {
							$tmp[] = $row;
						}
					}
					if ( ! empty( $tmp ) ) {
						$exact_rows = $tmp;
					} else {
						$exact_rows    = array();
						$unknown_import = true;
					}
				}
			}
			break;

		case 'city_to_country':
			if ( '' !== $export_city_norm ) {
				foreach ( $city_rows as $row ) {
					if ( ltar_city_matches( $row['export_city'], $export_city ) ) {
						$exact_rows[] = $row;
					}
				}
				if ( empty( $exact_rows ) ) {
					$unknown_export = true;
				}
			} else {
				$exact_rows = $city_rows;
			}
			break;

		case 'country_to_city':
			if ( '' !== $import_city_norm ) {
				foreach ( $city_rows as $row ) {
					if ( ltar_city_matches( $row['import_city'], $import_city ) ) {
						$exact_rows[] = $row;
					}
				}
				if ( empty( $exact_rows ) ) {
					$unknown_import = true;
				}
			} else {
				$exact_rows = $city_rows;
			}
			break;

		case 'import_country_only':
			$exact_rows = array();
			break;

		case 'country_to_country':
		default:
			$exact_rows = $city_rows;
			break;
	}

	$exact_result   = ltar_aggregate_rows( $exact_rows );
	$pair_result    = ltar_aggregate_rows( $pair_stub_rows );
	$country_result = ltar_aggregate_rows( $country_only_rows );

	$exact_has   = ltar_has_numeric_metrics( $exact_result );
	$pair_has    = ltar_has_numeric_metrics( $pair_result );
	$country_has = ltar_has_numeric_metrics( $country_result );

	$used_pair_primary    = false;
	$used_pair_fill       = false;
	$used_country_primary = false;
	$used_country_fill    = false;

	if ( 'import_country_only' === $scenario ) {
		if ( ! $country_has ) {
			return array();
		}

		$final  = $country_result;
		$source = $country_result;
	} else {
		$final  = $exact_has ? $exact_result : array();
		$source = $exact_has ? $exact_result : array();

		if ( $exact_has && $pair_has ) {
			list( $final, $used_pair_fill ) = ltar_merge_metrics( $final, $pair_result );
		} elseif ( ! $exact_has && $pair_has ) {
			$final             = $pair_result;
			$source            = $pair_result;
			$used_pair_primary = true;
		}

		if ( ltar_has_numeric_metrics( $final ) && $country_has ) {
			list( $final, $used_country_fill ) = ltar_merge_metrics( $final, $country_result );
		} elseif ( ! ltar_has_numeric_metrics( $final ) && $country_has ) {
			$final                = $country_result;
			$source               = $country_result;
			$used_country_primary = true;
		}

		if ( ! ltar_has_numeric_metrics( $final ) ) {
			return array();
		}
	}

	if ( empty( $source ) ) {
		$source = $pair_has ? $pair_result : $country_result;
	}

	$price_min   = is_numeric( $final['price_min'] ?? null ) ? (float) $final['price_min'] : null;
	$price_max   = is_numeric( $final['price_max'] ?? null ) ? (float) $final['price_max'] : null;
	$transit_min = is_numeric( $final['transit_min_days'] ?? null ) ? (int) $final['transit_min_days'] : null;
	$transit_max = is_numeric( $final['transit_max_days'] ?? null ) ? (int) $final['transit_max_days'] : null;

	if ( null === $price_min && null === $price_max && null === $transit_min && null === $transit_max ) {
		return array();
	}

	$source_export_code = strtoupper( trim( (string) ( $source['export_country_code'] ?? '' ) ) );
	$source_import_code = strtoupper( trim( (string) ( $source['import_country_code'] ?? '' ) ) );
	$source_export_name = trim( (string) ( $source['export_country'] ?? '' ) );
	$source_import_name = trim( (string) ( $source['import_country'] ?? '' ) );
	$source_export_city = trim( (string) ( $source['export_city'] ?? '' ) );
	$source_import_city = trim( (string) ( $source['import_city'] ?? '' ) );

	if ( '' === $import_code ) {
		$import_code = $source_import_code;
	}
	if ( '' === $export_code ) {
		$export_code = $source_export_code;
	}
	if ( '' === $import_name ) {
		$import_name = '' !== $source_import_name ? $source_import_name : ( $import_lookup[ $import_code ] ?? $import_code );
	}
	if ( '' === $export_name ) {
		$export_name = '' !== $source_export_name ? $source_export_name : ( $export_lookup[ $export_code ] ?? $export_code );
	}

	$price_avg   = ltar_average( $price_min, $price_max );
	$transit_avg = ltar_average( $transit_min, $transit_max );

	$resolved_export_city = '';
	$resolved_import_city = '';

	if ( $exact_has ) {
		if ( in_array( $scenario, array( 'city_to_country', 'city_to_city' ), true ) ) {
			$resolved_export_city = '' !== $export_city ? $export_city : $source_export_city;
		}
		if ( in_array( $scenario, array( 'country_to_city', 'city_to_city' ), true ) ) {
			$resolved_import_city = '' !== $import_city ? $import_city : $source_import_city;
		}
	} else {
		$resolved_export_city = $source_export_city;
		$resolved_import_city = $source_import_city;
	}

	if ( 'import_country_only' === $scenario && '' === $resolved_import_city ) {
		$resolved_import_city = $source_import_city;
	}

	$based_on_route = trim( (string) ( $source['based_on_route'] ?? '' ) );
	if ( $exact_has || '' === $based_on_route ) {
		$route_from = '' !== $resolved_export_city ? $resolved_export_city : $export_name;
		$route_to   = '' !== $resolved_import_city ? $resolved_import_city : $import_name;

		if ( 'country_to_country' === $scenario ) {
			$route_from = $export_name;
			$route_to   = $import_name;
		} elseif ( 'city_to_country' === $scenario ) {
			$route_from = '' !== $resolved_export_city ? $resolved_export_city : $export_name;
			$route_to   = $import_name;
		} elseif ( 'country_to_city' === $scenario ) {
			$route_from = $export_name;
			$route_to   = '' !== $resolved_import_city ? $resolved_import_city : $import_name;
		}

		$based_on_route = trim( $route_from . ' -> ' . $route_to );
	}

	$reason_tokens = array();
	if ( $unknown_export ) {
		$reason_tokens['unknown_export_city'] = 'unknown_export_city';
	}
	if ( $unknown_import ) {
		$reason_tokens['unknown_import_city'] = 'unknown_import_city';
	}
	if ( $used_pair_primary ) {
		$reason_tokens['fallback_country_pair'] = 'fallback_country_pair';
	}
	if ( $used_pair_fill ) {
		$reason_tokens['fill_from_country_pair'] = 'fill_from_country_pair';
	}
	if ( $used_country_primary || 'import_country_only' === $scenario ) {
		$reason_tokens['fallback_country_only'] = 'fallback_country_only';
	}
	if ( $used_country_fill ) {
		$reason_tokens['fill_from_country_only'] = 'fill_from_country_only';
	}

	foreach (
		array(
			$exact_has ? $exact_result : array(),
			( $used_pair_primary || $used_pair_fill ) ? $pair_result : array(),
			( $used_country_primary || $used_country_fill || 'import_country_only' === $scenario ) ? $country_result : array(),
		) as $layer
	) {
		foreach ( ltar_reason_tokens( $layer['reason'] ?? '' ) as $token ) {
			$reason_tokens[ $token ] = $token;
		}
	}

	$service_label     = trim( (string) ( $final['service_label'] ?? ( $source['service_label'] ?? strtoupper( $service ) ) ) );
	$currency          = trim( (string) ( $final['currency'] ?? ( $source['currency'] ?? 'USD' ) ) );
	$unit              = trim( (string) ( $final['unit'] ?? ( $source['unit'] ?? '' ) ) );
	$based_on_scenario = trim( (string) ( $source['based_on_scenario'] ?? '' ) );

	if ( '' === $based_on_scenario ) {
		$based_on_scenario = '' !== $scenario ? $scenario : 'import_country_only';
	}

	return array(
		'scenario'           => '' !== $scenario ? $scenario : 'import_country_only',
		'service'            => $service,
		'service_label'      => $service_label,
		'export_country'     => $export_name,
		'export_country_code'=> $export_code,
		'export_city'        => $resolved_export_city,
		'import_country'     => $import_name,
		'import_country_code'=> $import_code,
		'import_city'        => $resolved_import_city,
		'price_min'          => $price_min,
		'price_max'          => $price_max,
		'price_avg'          => $price_avg,
		'price_exact'        => ( 'city_to_city' === $scenario && $exact_has ) ? $price_avg : $price_min,
		'transit_min_days'   => $transit_min,
		'transit_max_days'   => $transit_max,
		'transit_avg_days'   => $transit_avg,
		'transit_exact_days' => ( 'city_to_city' === $scenario && $exact_has ) ? $transit_avg : $transit_min,
		'currency'           => '' !== $currency ? $currency : 'USD',
		'unit'               => $unit,
		'based_on_route'     => $based_on_route,
		'based_on_scenario'  => $based_on_scenario,
		'reason'             => implode( ',', array_values( $reason_tokens ) ),
	);
}
