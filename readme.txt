=== Lithops Tariffs ===
Contributors: endure-route, lithops
Tags: erp, tariffs, json, seo, logistics
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later

Central ERP tariffs catalog for Endure Route. Imports JSON, stores normalized tariff rows, provides CRUD in wp-admin, and exposes a protected REST endpoint for secondary sites.

== Features ==
* JSON import from ERP admin with full catalog replacement.
* Tabular CRUD for tariff rows directly in WordPress admin.
* Protected REST route `GET /wp-json/ltar/v1/catalog`.
* Protected REST route `POST /wp-json/ltar/v1/resolve` for ERP-side route resolution with stubs/fallbacks.
* Supports structured payloads (`country_only_defaults`, `country_pairs`, `lane_matrix`) and flat rows.
* Reuses the enrollment token from `lithops-erp-sites-connector` when available.

== Usage ==
1. Install `lithops-tariffs` on the ERP site.
2. Open `Tariffs Catalog` in wp-admin.
3. Import a JSON file or create rows manually.
4. On secondary sites configure `lithops-erp-bridge` with the ERP endpoint/token from `ERP Sites Connector`.
5. SEO plugins will receive the centralized catalog through `lithops-seo-settings`.
6. Child sites may also request a resolved tariff row through `ltar/v1/resolve`, so city-city, country-country and country-only stubs stay centralized on ERP.

== JSON Support ==
Supported formats:

* Structured ERP payload with `country_only_defaults`, `country_pairs`, `lane_matrix`.
* Flat `rows` arrays with normalized tariff rows.
* Manual CRUD rows, including `import_country_only` scenarios without export fields.

== Security ==
* REST access requires `X-LEB-Enrollment-Token`.
* Token is reused from `ERP Sites Connector` when present, otherwise a local token is generated.
* Stored tokens are encrypted with WordPress salts when OpenSSL is available.

== Changelog ==

= 1.1.3 =
* Added pagination, city filters, modal create/edit flow and fallback explainer to the ERP catalog screen.
* Fixed city alias resolution so exact routes like "Ho Chi Minh" and "Ho Chi Minh City" resolve to the same lane.
* Child-site admin previews now bypass bridge cache to show the latest resolved tariff row immediately.

= 1.1.2 =
* Fixed ERP fallback resolution so import-country-only defaults still apply when export country is known but no exact route price exists.

= 1.1.1 =
* Bumped version after ERP-side resolver and tooling updates.

= 1.1.0 =
* Added ERP-side `POST /wp-json/ltar/v1/resolve` endpoint.
* Centralized country-country and country-only fallback resolution on ERP.

= 1.0.0 =
* Initial release.
* JSON import with normalization into flat tariff rows.
* Admin CRUD UI with search and edit mode.
* Protected REST catalog endpoint for secondary sites.
