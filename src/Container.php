<?php
/**
 * Lightweight service container.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis;

use Recesso54bis\Domain\Eligibility\EligibilityEngine;
use Recesso54bis\Frontend\FlowController;
use Recesso54bis\Frontend\FlowUrls;
use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Integration\ReceiptScheduler;
use Recesso54bis\Integration\SubscriptionsAdapter;
use Recesso54bis\Integration\WithdrawalService;
use Recesso54bis\Pdf\ReceiptBuilder;
use Recesso54bis\Pdf\ReceiptDownloadController;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Rest\PermissionGate;
use Recesso54bis\Support\Clock;
use Recesso54bis\Support\Logger;
use Recesso54bis\Support\OrderToken;
use Recesso54bis\Support\RateLimiter;
use Recesso54bis\Support\Settings;
use Recesso54bis\Support\SystemClock;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and memoises the plugin's shared services. A deliberately minimal container: enough to wire
 * dependencies without a third-party package, keeping construction in one auditable place.
 */
final class Container {

	/**
	 * Memoised singletons keyed by service name.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Clock service.
	 */
	public function clock(): Clock {
		return $this->shared( 'clock', static fn(): Clock => new SystemClock() );
	}

	/**
	 * Logger service.
	 */
	public function logger(): Logger {
		return $this->shared( 'logger', static fn(): Logger => new Logger() );
	}

	/**
	 * Settings reader.
	 */
	public function settings(): Settings {
		return $this->shared( 'settings', static fn(): Settings => new Settings() );
	}

	/**
	 * Request repository.
	 */
	public function request_repository(): RequestRepository {
		return $this->shared( 'request_repository', static fn(): RequestRepository => new RequestRepository() );
	}

	/**
	 * Log repository.
	 */
	public function log_repository(): LogRepository {
		return $this->shared( 'log_repository', static fn(): LogRepository => new LogRepository() );
	}

	/**
	 * Eligibility engine (pure domain core).
	 */
	public function eligibility_engine(): EligibilityEngine {
		return $this->shared( 'eligibility_engine', static fn(): EligibilityEngine => new EligibilityEngine() );
	}

	/**
	 * Eligibility adapter (WooCommerce bridge).
	 */
	public function eligibility_adapter(): EligibilityAdapter {
		return $this->shared(
			'eligibility_adapter',
			fn(): EligibilityAdapter => new EligibilityAdapter(
				$this->eligibility_engine(),
				$this->settings(),
				$this->request_repository(),
				$this->clock()
			)
		);
	}

	/**
	 * Order token verifier.
	 */
	public function order_token(): OrderToken {
		return $this->shared( 'order_token', static fn(): OrderToken => new OrderToken() );
	}

	/**
	 * Rate limiter.
	 */
	public function rate_limiter(): RateLimiter {
		return $this->shared( 'rate_limiter', static fn(): RateLimiter => new RateLimiter() );
	}

	/**
	 * Withdrawal coordination service.
	 */
	public function withdrawal_service(): WithdrawalService {
		return $this->shared(
			'withdrawal_service',
			fn(): WithdrawalService => new WithdrawalService(
				$this->request_repository(),
				$this->log_repository(),
				$this->eligibility_adapter(),
				$this->clock()
			)
		);
	}

	/**
	 * REST permission gate.
	 */
	public function permission_gate(): PermissionGate {
		return $this->shared( 'permission_gate', fn(): PermissionGate => new PermissionGate( $this->order_token() ) );
	}

	/**
	 * Frontend flow URL builder.
	 */
	public function flow_urls(): FlowUrls {
		return $this->shared( 'flow_urls', fn(): FlowUrls => new FlowUrls( $this->order_token() ) );
	}

	/**
	 * Durable-medium receipt builder.
	 */
	public function receipt_builder(): ReceiptBuilder {
		return $this->shared( 'receipt_builder', static fn(): ReceiptBuilder => new ReceiptBuilder() );
	}

	/**
	 * Receipt generation scheduler.
	 */
	public function receipt_scheduler(): ReceiptScheduler {
		return $this->shared(
			'receipt_scheduler',
			fn(): ReceiptScheduler => new ReceiptScheduler(
				$this->receipt_builder(),
				$this->request_repository(),
				$this->log_repository(),
				$this->order_token(),
				$this->clock()
			)
		);
	}

	/**
	 * Optional WooCommerce Subscriptions adapter (cancels subscriptions on confirmed withdrawal).
	 */
	public function subscriptions_adapter(): SubscriptionsAdapter {
		return $this->shared(
			'subscriptions_adapter',
			fn(): SubscriptionsAdapter => new SubscriptionsAdapter( $this->log_repository() )
		);
	}

	/**
	 * Receipt download controller.
	 */
	public function receipt_download_controller(): ReceiptDownloadController {
		return $this->shared(
			'receipt_download_controller',
			fn(): ReceiptDownloadController => new ReceiptDownloadController(
				$this->request_repository(),
				$this->permission_gate()
			)
		);
	}

	/**
	 * Frontend flow controller.
	 */
	public function flow_controller(): FlowController {
		return $this->shared(
			'flow_controller',
			fn(): FlowController => new FlowController(
				$this->withdrawal_service(),
				$this->request_repository(),
				$this->permission_gate(),
				$this->eligibility_adapter()
			)
		);
	}

	/**
	 * Memoise a service built by the factory under the given key.
	 *
	 * @template T of object
	 *
	 * @param string       $key     Service key.
	 * @param callable():T $factory Factory building the service.
	 *
	 * @return T
	 */
	private function shared( string $key, callable $factory ): object {
		if ( ! isset( $this->instances[ $key ] ) ) {
			$this->instances[ $key ] = $factory();
		}

		/**
		 * The memoised instance, of the type produced by the factory.
		 *
		 * @var T $instance
		 */
		$instance = $this->instances[ $key ];

		return $instance;
	}
}
