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
		'20ft'            => 'sea_fcl_20',
		'20_ft'           => 'sea_fcl_20',
		'fcl40'           => 'sea_fcl_40',
		'fcl_40'          => 'sea_fcl_40',
		'sea_fcl_40'      => 'sea_fcl_40',
		'40ft'            => 'sea_fcl_40',
		'40_ft'           => 'sea_fcl_40',
		'fcl40hc'         => 'sea_fcl_40hc',
		'fcl_40hc'        => 'sea_fcl_40hc',
		'sea_fcl_40hc'    => 'sea_fcl_40hc',
		'40hc'            => 'sea_fcl_40hc',
		'40_hc'           => 'sea_fcl_40hc',
		'40_high_cube'    => 'sea_fcl_40hc',
		'reefer20'        => 'reefer20',
		'reefer_20'       => 'reefer20',
		'sea_reefer_20'   => 'reefer20',
		'reefer40hc'      => 'reefer40hc',
		'reefer_40hc'     => 'reefer40hc',
		'sea_reefer_40hc' => 'reefer40hc',
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
