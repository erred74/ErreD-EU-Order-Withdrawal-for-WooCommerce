<?php
/**
 * PII-redacting logger.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Audit/diagnostic logger. Routes through the WooCommerce logger when available and redacts
 * personal data (emails, names, tokens) from context before it is written, so default-level logs
 * never leak PII.
 */
final class Logger {

	/**
	 * Log source/channel.
	 */
	private const SOURCE = 'recesso-digitale';

	/**
	 * Context keys whose values must be redacted before logging.
	 *
	 * @var string[]
	 */
	private const REDACTED_KEYS = array(
		'email',
		'confirmation_email',
		'consumer_name',
		'name',
		'token',
		'secret',
		'ip',
		'request_ip',
	);

	/**
	 * Log an informational event.
	 *
	 * @param string               $message Human-readable message (no PII).
	 * @param array<string, mixed> $context Structured context (redacted before writing).
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string               $message Human-readable message (no PII).
	 * @param array<string, mixed> $context Structured context (redacted before writing).
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	/**
	 * Log an error.
	 *
	 * @param string               $message Human-readable message (no PII).
	 * @param array<string, mixed> $context Structured context (redacted before writing).
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Write the log line through the best available backend.
	 *
	 * @param string               $level   Log level (info|warning|error).
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private function write( string $level, string $message, array $context ): void {
		$redacted = $this->redact( $context );

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->log(
				$level,
				$message,
				array(
					'source'  => self::SOURCE,
					'context' => $redacted,
				)
			);
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$encoded = wp_json_encode( $redacted );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug fallback only.
			error_log( sprintf( '[%s] %s %s', self::SOURCE, $message, false === $encoded ? '' : $encoded ) );
		}
	}

	/**
	 * Replace the values of known PII keys with a redaction marker.
	 *
	 * @param array<string, mixed> $context Raw context.
	 *
	 * @return array<string, mixed> Redacted context.
	 */
	private function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), self::REDACTED_KEYS, true ) ) {
				$context[ $key ] = '[redacted]';
			}
		}

		return $context;
	}
}
