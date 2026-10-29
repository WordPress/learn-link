<?php
namespace WPOrg_Learn\Learn_Progress\Dates;

defined( 'ABSPATH' ) || defined( 'WPINC' ) || die();

/**
 * Convert a MySQL datetime string stored in UTC (e.g., comment_date_gmt)
 * into an RFC 3339 UTC string ('Y-m-d\TH:i:s\Z').
 *
 * Contract §6.4: All dates leaving Learn must be normalized to RFC 3339 UTC with a 'Z' suffix.
 *
 * @param string|null $mysql MySQL datetime string (e.g., '2026-09-08 18:44:10').
 * @return string|null RFC 3339 UTC string with 'Z' suffix, or null on empty/invalid input.
 */
function from_gmt( $mysql ) {
	if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
		return null;
	}
	$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql, new \DateTimeZone( 'UTC' ) );
	return $date ? $date->format( 'Y-m-d\TH:i:s\Z' ) : null;
}

/**
 * Convert a MySQL datetime string stored in the site's local time zone
 * (e.g., Sensei 'start' meta or current_time( 'mysql' )) into an RFC 3339 UTC string ('Y-m-d\TH:i:s\Z').
 *
 * @param string|null             $mysql     MySQL datetime string in local time (e.g., '2026-09-08 12:44:10').
 * @param \DateTimeZone|null      $site_zone Site time zone. If null, falls back to wp_timezone().
 * @return string|null RFC 3339 UTC string with 'Z' suffix, or null on empty/invalid input.
 */
function from_local( $mysql, ?\DateTimeZone $site_zone = null ) {
	if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
		return null;
	}
	$zone = $site_zone ?: ( function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' ) );
	$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql, $zone );
	return $date ? $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) : null;
}
