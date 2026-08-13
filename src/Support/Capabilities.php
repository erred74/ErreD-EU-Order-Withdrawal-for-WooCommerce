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
	 * Roles the manage capability is never granted to, whatever the settings say. Withdrawal requests
	 * hold personal data, and these roles exist for people who are not staff.
	 *
	 * @var string[]
	 */
	public const NEVER_GRANT = array( 'subscriber', 'customer' );

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
	 * Grant the manage capability to exactly the given roles, revoking it from every other role.
	 *
	 * The administrator is always granted and never revoked: it is the account that configures the
	 * roles in the first place, so making it revocable would let a site lock itself out of the screen.
	 * Roles below shop level are never granted, whatever is passed in.
	 *
	 * @param string[] $roles Role slugs that should hold {@see self::MANAGE_REQUESTS}.
	 */
	public static function sync( array $roles ): void {
		$granted = array( 'administrator' );
		foreach ( $roles as $role_name ) {
			$role_name = sanitize_key( (string) $role_name );
			if ( '' !== $role_name && ! in_array( $role_name, self::NEVER_GRANT, true ) ) {
				$granted[] = $role_name;
			}
		}
		$granted = array_unique( $granted );

		foreach ( wp_roles()->roles as $role_name => $unused ) {
			$role = get_role( (string) $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			if ( in_array( (string) $role_name, $granted, true ) ) {
				$role->add_cap( self::MANAGE_REQUESTS );
			} else {
				$role->remove_cap( self::MANAGE_REQUESTS );
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
