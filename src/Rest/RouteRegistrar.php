<?php
/**
 * REST route registrar.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Rest;

use Recesso54bis\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the REST controllers from the container and registers their routes on rest_api_init.
 */
final class RouteRegistrar {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Construct the registrar.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hook route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Instantiate the controllers and register every route.
	 */
	public function register_routes(): void {
		$controllers = array(
			new WithdrawalsController(
				$this->container->permission_gate(),
				$this->container->rate_limiter(),
				$this->container->log_repository(),
				$this->container->withdrawal_service(),
				$this->container->request_repository()
			),
			new EligibilityController(
				$this->container->permission_gate(),
				$this->container->rate_limiter(),
				$this->container->log_repository(),
				$this->container->eligibility_adapter()
			),
			new AdminWithdrawalsController(
				$this->container->permission_gate(),
				$this->container->rate_limiter(),
				$this->container->log_repository(),
				$this->container->request_repository(),
				$this->container->receipt_scheduler()
			),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
