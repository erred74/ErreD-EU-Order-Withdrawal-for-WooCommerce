<?php
/**
 * Capability definitions.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the plugin's custom capabilities. Capabilities are granted on
 * activation and removed on uninstall, and are never granted to low-privilege roles such as
 * subscriber.
 */
final class Capabilities {

	/**
	 * Capability required to view and process withdrawal requests in the admin.
	 */
	public const MANAGE_REQUESTS = 'manage_recesso_requests';

	/**
	 * Roles that receive {@see self::MANAGE_REQUESTS} on activation.
	 *
	 * @var string[]
	 */
	private const PRIVILEGED_ROLES = array( 'administrator', 'shop_manager' );

	/**
	 * Grant the custom capabilities to the privileged roles.
	 */
	public static function add(): void {
		foreach ( self::PRIVILEGED_ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof \WP_Role ) {
				$role->add_cap( self::MANAGE_REQUESTS );
			}
		}
	}

	/**
	 * Remove the custom capabilities from every role that has them.
	 */
	public static function remove(): void {
		foreach ( wp_roles()->roles as $role_name => $unused ) {
			$role = get_role( $role_name );
			if ( $role instanceof \WP_Role ) {
				$role->remove_cap( self::MANAGE_REQUESTS );
			}
		}
	}
}
