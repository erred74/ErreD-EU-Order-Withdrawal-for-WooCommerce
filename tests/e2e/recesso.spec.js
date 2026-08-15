/**
 * End-to-end suite for the withdrawal flow and admin app.
 *
 * Covers the two legally load-bearing journeys with WCAG 2.2 AA (axe) assertions on the plugin's
 * own markup (§11):
 *   1. the guest two-step server-rendered flow (declaration → «conferma recesso» → acknowledgement),
 *      asserting the durable outcome (status confirmed, dies a quo recorded) and rejecting a
 *      tampered token without leaking order existence;
 *   2. the React admin requests app rendering an accessible, populated list.
 *
 * Helpers are defined inline (no relative requires): Playwright 1.61's transform hook crashes on
 * local-file requires under Node 22.15, while Node builtins and node_modules resolve fine.
 */
const { execFileSync } = require( 'child_process' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { default: AxeBuilder } = require( '@axe-core/playwright' );

const ENV_CWD = 'wp-content/plugins/wc-reso-ordini';
const A11Y_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * Run a wp-cli command in the wp-env *tests* container and return its stdout.
 *
 * @param {string} php Raw PHP for `wp eval`.
 * @return {string} Combined stdout.
 */
function wpEval( php ) {
	return execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'tests-cli',
			`--env-cwd=${ ENV_CWD }`,
			'wp',
			'eval',
			php,
		],
		{ encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 }
	);
}

/**
 * Parse the first standalone JSON object out of noisy wp-env output.
 *
 * @param {string} output Raw stdout.
 * @return {Object} Parsed object.
 */
function firstJson( output ) {
	const match = output.match( /\{[\s\S]*\}/ );
	if ( ! match ) {
		throw new Error( `No JSON in wp-cli output:\n${ output }` );
	}
	return JSON.parse( match[ 0 ] );
}

/**
 * Create a completed, eligible order and return the guest withdrawal entry data (the same signed
 * link a consumer receives by email).
 *
 * @return {{orderId:number, tokenUrl:string, flowUrl:string, email:string, name:string}} The seeded order id, signed flow URL and consumer details.
 */
function seedEligibleOrder() {
	return firstJson(
		wpEval( `
use Recesso54bis\\Container;
use Recesso54bis\\Frontend\\FlowPage;
use Recesso54bis\\Support\\Settings;
update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
FlowPage::ensure();
$product = new WC_Product_Simple();
$product->set_name( 'E2E Recesso Product' );
$product->set_regular_price( '25' );
$product->save();
$order = wc_create_order();
$order->add_product( wc_get_product( $product->get_id() ), 1 );
$order->set_billing_email( 'e2e-consumer@example.test' );
$order->set_billing_first_name( 'Giulia' );
$order->set_billing_last_name( 'Bianchi' );
$order->set_status( 'completed' );
$order->set_date_completed( time() );
$order->save();
$container = new Container();
$expiry = time() + 14 * DAY_IN_SECONDS;
$url = $container->flow_urls()->declaration_url( FlowPage::url(), (int) $order->get_id(), $expiry );
echo wp_json_encode( array(
	'orderId' => (int) $order->get_id(),
	'tokenUrl' => $url,
	'flowUrl' => FlowPage::url(),
	'email' => 'e2e-consumer@example.test',
	'name' => 'Giulia Bianchi',
) );
` )
	);
}

/**
 * Seed an order and drive it to a confirmed, acknowledged request (so the admin list has a row with
 * a downloadable receipt) without going through the browser.
 *
 * @return {{orderId:number, requestId:number}} The seeded order id and the acknowledged request id.
 */
function seedConfirmedRequest() {
	return firstJson(
		wpEval( `
use Recesso54bis\\Container;
use Recesso54bis\\Support\\Settings;
update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
$product = new WC_Product_Simple();
$product->set_name( 'E2E Admin Product' );
$product->set_regular_price( '40' );
$product->save();
$order = wc_create_order();
$order->add_product( wc_get_product( $product->get_id() ), 1 );
$order->set_billing_email( 'e2e-admin-case@example.test' );
$order->set_status( 'completed' );
$order->set_date_completed( time() );
$order->save();
$c = new Container();
$req = $c->withdrawal_service()->create_declaration( $order, array(
	'consumer_name' => 'Marco Verdi',
	'contract_reference' => '#' . $order->get_id(),
	'confirmation_email' => 'e2e-admin-case@example.test',
), null );
$c->withdrawal_service()->confirm( $req->id, 'consumer' );
$c->receipt_scheduler()->generate( $req->id );
echo wp_json_encode( array( 'orderId' => (int) $order->get_id(), 'requestId' => (int) $req->id ) );
` )
	);
}

/**
 * Create a completed, eligible order with a single product line of quantity 4, and return the guest
 * entry data — for exercising partial-by-quantity withdrawal.
 *
 * @return {{orderId:number, tokenUrl:string, email:string, name:string}} The seeded order id, signed flow URL and consumer details.
 */
function seedQuantityOrder() {
	return firstJson(
		wpEval( `
use Recesso54bis\\Container;
use Recesso54bis\\Frontend\\FlowPage;
use Recesso54bis\\Support\\Settings;
update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
FlowPage::ensure();
$product = new WC_Product_Simple();
$product->set_name( 'E2E Quantity Wine' );
$product->set_regular_price( '15' );
$product->save();
$order = wc_create_order();
$order->add_product( wc_get_product( $product->get_id() ), 4 );
$order->set_billing_email( 'e2e-qty@example.test' );
$order->set_billing_first_name( 'Sara' );
$order->set_billing_last_name( 'Verdi' );
$order->set_status( 'completed' );
$order->set_date_completed( time() );
$order->save();
$container = new Container();
$expiry = time() + 14 * DAY_IN_SECONDS;
$url = $container->flow_urls()->declaration_url( FlowPage::url(), (int) $order->get_id(), $expiry );
echo wp_json_encode( array(
	'orderId' => (int) $order->get_id(),
	'tokenUrl' => $url,
	'email' => 'e2e-qty@example.test',
	'name' => 'Sara Verdi',
) );
` )
	);
}

/**
 * Assert the markup inside `selector` has no serious WCAG 2.2 AA violations.
 *
 * @param {import('@playwright/test').Page} page     The page.
 * @param {string}                          selector Container to scope the scan to.
 */
async function expectNoA11yViolations( page, selector ) {
	const { violations } = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( A11Y_TAGS )
		.analyze();
	expect(
		violations,
		`axe violations: ${ violations.map( ( v ) => v.id ).join( ', ' ) }`
	).toEqual( [] );
}

const FLOW = '.wp-block-recesso-digitale-flow';

test.describe( 'Guest withdrawal flow (server-rendered)', () => {
	// Run as a guest: drop the admin storage state the base config applies globally.
	test.use( { storageState: { cookies: [], origins: [] } } );

	let order;

	test.beforeAll( () => {
		order = seedEligibleOrder();
	} );

	test( 'declaration → «conferma recesso» records the dies a quo and is accessible', async ( {
		page,
	} ) => {
		// Step 1 — declaration, reached via the signed token link.
		await page.goto( order.tokenUrl );
		await expect( page.locator( FLOW ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: /continue/i } )
		).toBeVisible();
		await expectNoA11yViolations( page, FLOW );

		await page.fill( 'input[name="consumer_name"]', order.name );
		await page.fill( 'input[name="confirmation_email"]', order.email );
		// Select the product to withdraw (checkboxes start unticked, matching the picker design).
		await page
			.locator( '.wp-block-recesso-digitale-flow__item-check' )
			.first()
			.check();
		await page.getByRole( 'button', { name: /continue/i } ).click();

		// Step 2 — explicit «conferma recesso».
		const confirmButton = page.locator(
			'.wp-block-recesso-digitale-flow__confirm'
		);
		await expect( confirmButton ).toBeVisible();
		await expectNoA11yViolations( page, FLOW );
		await confirmButton.click();

		// Acknowledgement screen.
		await expect( page.locator( FLOW ) ).toContainText( /confirmed/i );
		await expectNoA11yViolations( page, FLOW );

		// The legal facts are recorded: confirmed_at_gmt set (the dies a quo) and the durable receipt is
		// generated synchronously on confirmation (so the status advances to "acknowledged" and a
		// receipt_hash exists immediately — the consumer's PDF/email is produced at once, not deferred).
		const row = firstJson(
			wpEval( `global $wpdb;
				$t = $wpdb->prefix . 'recesso_dig_requests';
				$r = $wpdb->get_row( $wpdb->prepare( "SELECT status, confirmed_at_gmt, receipt_hash FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT 1", ${ order.orderId } ), ARRAY_A );
				echo wp_json_encode( $r );` )
		);
		expect( row.status ).toBe( 'acknowledged' );
		expect( row.confirmed_at_gmt ).toBeTruthy();
		expect( row.confirmed_at_gmt ).not.toBe( '0000-00-00 00:00:00' );
		expect( row.receipt_hash ).toBeTruthy();
	} );

	test( '«Edit your details» returns to step one, reachable by keyboard', async ( {
		page,
	} ) => {
		const edited = seedEligibleOrder();

		await page.goto( edited.tokenUrl );
		await page.fill( 'input[name="consumer_name"]', 'Prima Versione' );
		await page.fill( 'input[name="confirmation_email"]', edited.email );
		await page
			.locator( '.wp-block-recesso-digitale-flow__item-check' )
			.first()
			.check();
		await page.getByRole( 'button', { name: /continue/i } ).click();

		// The review step shows what was entered, and offers the way back.
		await expect( page.locator( FLOW ) ).toContainText( 'Prima Versione' );
		const editLink = page.getByRole( 'link', {
			name: /edit your details/i,
		} );
		await expect( editLink ).toBeVisible();
		await expectNoA11yViolations( page, FLOW );

		// Reached with the keyboard alone, not just the mouse.
		await editLink.focus();
		await expect( editLink ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		// Back on step one, where a corrected declaration replaces the unconfirmed one.
		await expect(
			page.getByRole( 'button', { name: /continue/i } )
		).toBeVisible();
		await page.fill( 'input[name="consumer_name"]', 'Seconda Versione' );
		await page.fill( 'input[name="confirmation_email"]', edited.email );
		await page
			.locator( '.wp-block-recesso-digitale-flow__item-check' )
			.first()
			.check();
		await page.getByRole( 'button', { name: /continue/i } ).click();

		await expect( page.locator( FLOW ) ).toContainText(
			'Seconda Versione'
		);

		// Exactly one request for the order: editing replaced the pending declaration.
		const count = firstJson(
			wpEval( `global $wpdb;
				$t = $wpdb->prefix . 'recesso_dig_requests';
				echo wp_json_encode( array( 'n' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE order_id = %d", ${ edited.orderId } ) ) ) );` )
		);
		expect( count.n ).toBe( 1 );
	} );

	test( 'a consumer can withdraw a chosen quantity of a line (partial by quantity)', async ( {
		page,
	} ) => {
		const order4 = seedQuantityOrder();
		await page.goto( order4.tokenUrl );
		await expect( page.locator( FLOW ) ).toBeVisible();

		// One product line of 4 units. Tick it to reveal its quantity selector, which defaults to the 4
		// available; reduce it to 2.
		await page
			.locator( '.wp-block-recesso-digitale-flow__item-check' )
			.first()
			.check();
		const qty = page.locator( 'input[name^="requested_qty"]' );
		await expect( qty ).toHaveCount( 1 );
		await expect( qty ).toHaveValue( '4' );
		await qty.fill( '2' );

		await page.fill( 'input[name="consumer_name"]', order4.name );
		await page.fill( 'input[name="confirmation_email"]', order4.email );
		await page.getByRole( 'button', { name: /continue/i } ).click();

		const confirmButton = page.locator(
			'.wp-block-recesso-digitale-flow__confirm'
		);
		await expect( confirmButton ).toBeVisible();
		await confirmButton.click();
		await expect( page.locator( FLOW ) ).toContainText( /confirmed/i );

		// The recorded request withdraws 2 units of the single line (a partial-by-quantity withdrawal).
		const row = firstJson(
			wpEval( `global $wpdb;
				$t = $wpdb->prefix . 'recesso_dig_requests';
				$r = $wpdb->get_row( $wpdb->prepare( "SELECT requested_items FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT 1", ${ order4.orderId } ), ARRAY_A );
				echo wp_json_encode( $r );` )
		);
		const items = JSON.parse( row.requested_items );
		const quantities = Object.values( items );
		expect( quantities ).toHaveLength( 1 );
		expect( quantities[ 0 ] ).toBe( 2 );
	} );

	test( 'a tampered token is refused without leaking order existence', async ( {
		page,
	} ) => {
		const tampered = order.tokenUrl.replace( /(token=[^&]+)/, '$1XYZ' );
		await page.goto( tampered );
		await expect(
			page.locator( 'input[name="consumer_name"]' )
		).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).toContainText(
			/not valid or has expired/i
		);
	} );

	test( 'JS enhances both steps without a full page reload', async ( {
		page,
	} ) => {
		const fresh = seedEligibleOrder();
		await page.goto( fresh.tokenUrl );
		await expect( page.locator( FLOW ) ).toBeVisible();

		// A marker on window is wiped by any full-document navigation; if it survives each step,
		// the transition happened in-place (the progressive enhancement, not a reload).
		await page.evaluate( () => {
			window.__rdNoReload = true;
		} );

		await page.fill( 'input[name="consumer_name"]', fresh.name );
		await page.fill( 'input[name="confirmation_email"]', fresh.email );
		await page
			.locator( '.wp-block-recesso-digitale-flow__item-check' )
			.first()
			.check();
		await page.getByRole( 'button', { name: /continue/i } ).click();

		const confirmButton = page.locator(
			'.wp-block-recesso-digitale-flow__confirm'
		);
		await expect( confirmButton ).toBeVisible();
		expect( await page.evaluate( () => window.__rdNoReload ) ).toBe( true );

		await confirmButton.click();
		await expect( page.locator( FLOW ) ).toContainText( /confirmed/i );
		expect( await page.evaluate( () => window.__rdNoReload ) ).toBe( true );
	} );

	test( 'native validation blocks an invalid email without leaving the step', async ( {
		page,
	} ) => {
		const fresh = seedEligibleOrder();
		await page.goto( fresh.tokenUrl );

		await page.fill( 'input[name="consumer_name"]', fresh.name );
		await page.fill( 'input[name="confirmation_email"]', 'not-an-email' );
		await page.getByRole( 'button', { name: /continue/i } ).click();

		// Native HTML5 validation keeps the consumer on the declaration step (no confirm step) and the
		// email field reports as invalid — no JavaScript-specific validation needed.
		await expect(
			page.locator( '.wp-block-recesso-digitale-flow__confirm' )
		).toHaveCount( 0 );
		const valid = await page
			.locator( 'input[name="confirmation_email"]' )
			.evaluate( ( el ) => el.checkValidity() );
		expect( valid ).toBe( false );
	} );

	test( 'submitting with no item selected stays on the declaration step', async ( {
		page,
	} ) => {
		const fresh = seedEligibleOrder();
		await page.goto( fresh.tokenUrl );
		await expect( page.locator( FLOW ) ).toBeVisible();

		// Valid identity fields but no product ticked: the step-1 inline validation must hold the
		// consumer on the declaration (the server enforces the same rule without JavaScript).
		await page.fill( 'input[name="consumer_name"]', fresh.name );
		await page.fill( 'input[name="confirmation_email"]', fresh.email );
		await page.getByRole( 'button', { name: /continue/i } ).click();

		await expect(
			page.locator( '.wp-block-recesso-digitale-flow__confirm' )
		).toHaveCount( 0 );
		await expect(
			page.locator( 'input[name="consumer_name"]' )
		).toBeVisible();
	} );

	test( 'the consumer self-declaration is required, accessible and recorded in the receipt', async ( {
		page,
	} ) => {
		// The merchant asks for the "bought as a consumer" declaration and hides the intro paragraph.
		wpEval(
			`update_option( 'recesso_dig_consumer_declaration_enabled', '1' );
			update_option( 'recesso_dig_form_intro_enabled', '0' );`
		);

		try {
			const fresh = seedEligibleOrder();
			await page.goto( fresh.tokenUrl );
			await expect( page.locator( FLOW ) ).toBeVisible();

			// The intro is gone and the declaration is present — and the form is still accessible.
			await expect(
				page.locator( '.wp-block-recesso-digitale-flow__intro' )
			).toHaveCount( 0 );
			const declaration = page.locator( '#recesso-dig-consumer' );
			await expect( declaration ).toBeVisible();
			await expectNoA11yViolations( page, FLOW );

			await page.fill( 'input[name="consumer_name"]', fresh.name );
			await page.fill( 'input[name="confirmation_email"]', fresh.email );
			await page
				.locator( '.wp-block-recesso-digitale-flow__item-check' )
				.first()
				.check();

			// Leaving the declaration unticked must hold the consumer on step 1.
			await page.getByRole( 'button', { name: /continue/i } ).click();
			await expect(
				page.locator( '.wp-block-recesso-digitale-flow__confirm' )
			).toHaveCount( 0 );

			await declaration.check();
			await page.getByRole( 'button', { name: /continue/i } ).click();
			await page
				.locator( '.wp-block-recesso-digitale-flow__confirm' )
				.click();
			await expect( page.locator( FLOW ) ).toContainText( /confirmed/i );

			// The exact wording agreed is stored as evidence, not a bare flag.
			const row = firstJson(
				wpEval( `global $wpdb;
					$t = $wpdb->prefix . 'recesso_dig_requests';
					$r = $wpdb->get_row( $wpdb->prepare( "SELECT consumer_declaration FROM {$t} WHERE order_id = %d ORDER BY id DESC LIMIT 1", ${ fresh.orderId } ), ARRAY_A );
					echo wp_json_encode( $r );` )
			);
			expect( row.consumer_declaration ).toContain( 'consumer' );
		} finally {
			wpEval(
				`delete_option( 'recesso_dig_consumer_declaration_enabled' );
				delete_option( 'recesso_dig_form_intro_enabled' );`
			);
		}
	} );
} );

test.describe( 'Admin requests app (React)', () => {
	let seeded;

	test.beforeAll( () => {
		seeded = seedConfirmedRequest();
	} );

	test( 'renders an accessible list containing the confirmed request', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=recesso-digitale' );

		const app = page.locator( '#recesso-dig-admin-app' );
		await expect( app ).toBeVisible();

		// React-only controls confirm the app (not just the no-JS fallback) has mounted.
		await expect(
			page.getByRole( 'button', { name: /refresh/i } )
		).toBeVisible();
		await expect(
			page.getByRole( 'combobox', { name: /filter by status/i } )
		).toBeVisible();

		// The seeded confirmed order appears once the REST list resolves.
		await expect(
			app.getByText( String( seeded.orderId ) ).first()
		).toBeVisible();

		await expectNoA11yViolations( page, '#recesso-dig-admin-app' );
	} );
} );
