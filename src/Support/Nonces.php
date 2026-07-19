<?php
/**
 * Nonce action names.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for nonce action strings. Action names are specific and never reused
 * across different privileged operations.
 */
final class Nonces {

	/**
	 * Nonce action for submitting the withdrawal declaration (step 1).
	 */
	public const SUBMIT = 'recesso_dig_submit';

	/**
	 * Nonce action for confirming the withdrawal (step 2, «conferma recesso»).
	 */
	public const CONFIRM = 'recesso_dig_confirm';

	/**
	 * Nonce action for the order lookup form (requesting a signed withdrawal link by email).
	 */
	public const LOOKUP = 'recesso_dig_lookup';
}
