<?php
/**
 * Fixed settings and constants.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only configuration.
 */
class Config {

	const CAPABILITY     = 'manage_woocommerce';
	const MENU_SLUG      = 'socialscore';
	const PLATFORM       = 'woocommerce';
	const BATCH_SIZE     = 500;
	const ACTION_GROUP   = 'socialscore';
	const TERMS_URL      = 'https://socialscore.io/terms';
	const PRIVACY_URL    = 'https://socialscore.io/privacy';
	const OPTION_CONN    = 'socialscore_connection';
	const OPTION_SYNC    = 'socialscore_sync_state';
	const TRANSIENT_CONF = 'socialscore_trigger_settings';
	const OPTION_LOST    = 'socialscore_connection_lost';
	const META_LABELS    = '_socialscore_labels';
	const META_ORDER_ID  = '_socialscore_order_id';
	const META_CHECKED   = '_socialscore_checked';
	const API_DEFAULT    = 'https://api.socialscore.io';

	/**
	 * Base URL of the SocialScore public API, without a trailing slash.
	 *
	 * @return string
	 */
	public static function api_base() {
		$base = defined( 'SOCIALSCORE_API_BASE' ) ? SOCIALSCORE_API_BASE : self::API_DEFAULT;
		return untrailingslashit( (string) apply_filters( 'socialscore_api_base', $base ) );
	}
}
