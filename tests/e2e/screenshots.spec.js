/**
 * Generates the WordPress.org listing screenshots into .wordpress-org/.
 *
 * These are tagged @screenshot and excluded from the normal e2e run (see the test:e2e script);
 * run them on demand with `npm run screenshots`. They reuse the same wp-cli seeding as the e2e
 * suite, so the captures reflect the real, working flow.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { execFileSync } = require( 'child_process' );

const ENV_CWD = 'wp-content/plugins/wc-reso-ordini';
const OUT = '.wordpress-org';

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

function firstJson( output ) {
	const match = output.match( /\{[\s\S]*\}/ );
	if ( ! match ) {
		throw new Error( `No JSON in wp-cli output:\n${ output }` );
	}
	return JSON.parse( match[ 0 ] );
}

function seedEligibleOrder() {
	return firstJson(
		wpEval( `
use Recesso54bis\\Container;
use Recesso54bis\\Frontend\\FlowPage;
use Recesso54bis\\Support\\Settings;
update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
FlowPage::ensure();
$product = new WC_Product_Simple();
$product->set_name( 'Cuffie wireless' );
$product->set_regular_price( '79' );
$product->save();
$order = wc_create_order();
$order->add_product( wc_get_product( $product->get_id() ), 1 );
$order->set_billing_email( 'giulia.bianchi@example.test' );
$order->set_billing_first_name( 'Giulia' );
$order->set_billing_last_name( 'Bianchi' );
$order->set_status( 'completed' );
$order->set_date_completed( time() );
$order->save();
$container = new Container();
$url = $container->flow_urls()->declaration_url( FlowPage::url(), (int) $order->get_id(), time() + 14 * DAY_IN_SECONDS );
echo wp_json_encode( array( 'orderId' => (int) $order->get_id(), 'tokenUrl' => $url, 'name' => 'Giulia Bianchi', 'email' => 'giulia.bianchi@example.test' ) );
` )
	);
}

function seedConfirmedRequest() {
	wpEval( `
use Recesso54bis\\Container;
use Recesso54bis\\Support\\Settings;
update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
$product = new WC_Product_Simple();
$product->set_name( 'Tappetino yoga' );
$product->set_regular_price( '35' );
$product->save();
$order = wc_create_order();
$order->add_product( wc_get_product( $product->get_id() ), 1 );
$order->set_billing_email( 'marco.verdi@example.test' );
$order->set_status( 'completed' );
$order->set_date_completed( time() );
$order->save();
$c = new Container();
$req = $c->withdrawal_service()->create_declaration( $order, array(
	'consumer_name' => 'Marco Verdi',
	'contract_reference' => '#' . $order->get_id(),
	'confirmation_email' => 'marco.verdi@example.test',
), null );
$c->withdrawal_service()->confirm( $req->id, 'consumer' );
` );
}

test.describe( 'Guest flow screenshots @screenshot', () => {
	// Capture as a guest so the listing images have no admin bar.
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'guest flow screenshots @screenshot', async ( { page } ) => {
		const order = seedEligibleOrder();

		await page.goto( order.tokenUrl );
		await expect(
			page.locator( '.wp-block-recesso-digitale-flow' )
		).toBeVisible();
		await page.fill( 'input[name="consumer_name"]', order.name );
		await page.fill( 'input[name="confirmation_email"]', order.email );
		// At least one item must be chosen before the declaration can be sent.
		await page
			.locator( '.wp-block-recesso-digitale-flow__item-check' )
			.first()
			.check();
		await page.screenshot( {
			path: `${ OUT }/screenshot-1.png`,
			fullPage: true,
		} );

		await page.getByRole( 'button', { name: /continue/i } ).click();
		await expect(
			page.locator( '.wp-block-recesso-digitale-flow__confirm' )
		).toBeVisible();
		await page.screenshot( {
			path: `${ OUT }/screenshot-2.png`,
			fullPage: true,
		} );

		await page
			.locator( '.wp-block-recesso-digitale-flow__confirm' )
			.click();
		await expect(
			page.locator( '.wp-block-recesso-digitale-flow' )
		).toContainText( /confirmed/i );
		await page.screenshot( {
			path: `${ OUT }/screenshot-3.png`,
			fullPage: true,
		} );
	} );
} );

test.describe( 'Admin screenshot @screenshot', () => {
	test( 'admin screen screenshot @screenshot', async ( { page, admin } ) => {
		seedConfirmedRequest();

		await admin.visitAdminPage( 'admin.php', 'page=recesso-digitale' );
		await expect( page.locator( '#recesso-dig-admin-app' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: /refresh/i } )
		).toBeVisible();
		await page.screenshot( {
			path: `${ OUT }/screenshot-4.png`,
			fullPage: true,
		} );
	} );
} );

/**
 * A football-school enrolment (counted from the purchase) and a T-shirt (counted from delivery),
 * bought 20 days ago and delivered 2 days ago, withdrawn and confirmed today.
 *
 * @return {{requestId:number, orderId:number, courseId:number}} Seeded ids.
 */
function seedMixedRequest() {
	return firstJson(
		wpEval( `
use Recesso54bis\\Container;
use Recesso54bis\\Support\\Settings;
update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
$tee = new WC_Product_Simple();
$tee->set_name( 'Maglietta ufficiale' );
$tee->set_regular_price( '25' );
$tee->save();
$course = new WC_Product_Simple();
$course->set_name( 'Iscrizione scuola calcio' );
$course->set_regular_price( '180' );
$course->set_virtual( true );
$course->update_meta_data( Settings::META_PRODUCT_STATUS, Settings::STATUS_ART14_4A_SERVICE );
$course->update_meta_data( Settings::META_PRODUCT_WINDOW_BASIS, Settings::TRIGGER_CONCLUSION );
$course->save();
$order = wc_create_order();
$order->add_product( wc_get_product( $tee->get_id() ), 1 );
$order->add_product( wc_get_product( $course->get_id() ), 1 );
$order->set_billing_email( 'anna.neri@example.test' );
$order->set_date_created( time() - 20 * DAY_IN_SECONDS );
$order->set_status( 'completed' );
$order->set_date_completed( time() - 2 * DAY_IN_SECONDS );
$order->save();
$c = new Container();
$req = $c->withdrawal_service()->create_declaration( $order, array(
	'consumer_name' => 'Anna Neri',
	'contract_reference' => '#' . $order->get_id(),
	'confirmation_email' => 'anna.neri@example.test',
), null );
$c->withdrawal_service()->confirm( $req->id, 'consumer' );
echo wp_json_encode( array( 'requestId' => (int) $req->id, 'orderId' => (int) $order->get_id(), 'courseId' => (int) $course->get_id() ) );
` )
	);
}

test.describe( 'Per-item period screenshots @screenshot', () => {
	let mixed;

	test.beforeAll( () => {
		mixed = seedMixedRequest();
	} );

	test( 'request detail screenshot @screenshot', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=recesso-digitale' );
		const row = page
			.locator( '#recesso-dig-admin-app' )
			.getByRole( 'row' )
			.filter( { hasText: String( mixed.orderId ) } )
			.first();
		await row.getByRole( 'button', { name: /view/i } ).click();

		const dialog = page.getByRole( 'dialog' );
		await expect( dialog ).toContainText( /withdrawal periods/i );
		await dialog.screenshot( { path: `${ OUT }/screenshot-5.png` } );
	} );

	test( 'product editor screenshot @screenshot', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage(
			'post.php',
			`post=${ mixed.courseId }&action=edit`
		);
		const panel = page.locator( '#woocommerce-product-data' );
		await expect(
			panel.locator( '#_recesso_dig_window_basis' )
		).toBeVisible();
		await panel.scrollIntoViewIfNeeded();
		await panel.screenshot( { path: `${ OUT }/screenshot-6.png` } );
	} );
} );
