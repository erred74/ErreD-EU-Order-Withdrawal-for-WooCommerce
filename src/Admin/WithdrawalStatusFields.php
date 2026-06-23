<?php
/**
 * Per-product and per-category art. 59 withdrawal-status editor fields.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Right of withdrawal" status control to the product editor (General tab) and to the product
 * category add/edit screens, letting the merchant mark individual products or whole categories as
 * excluded (or explicitly allowed) under art. 59. The value is read by {@see \Recesso54bis\Integration\EligibilityAdapter}
 * ahead of the global option lists and the default policy.
 */
final class WithdrawalStatusFields {

	private const TERM_NONCE_ACTION = 'recesso_dig_term_status';
	private const TERM_NONCE_NAME   = '_recesso_dig_term_nonce';

	/**
	 * Hook the product and category fields.
	 */
	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );

		add_action( 'product_cat_add_form_fields', array( $this, 'render_category_add_field' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'render_category_edit_field' ) );
		add_action( 'created_product_cat', array( $this, 'save_category_field' ) );
		add_action( 'edited_product_cat', array( $this, 'save_category_field' ) );
	}

	/**
	 * The selectable withdrawal classifications (value => label), keyed to the art. 59 / Directive
	 * 2011/83/EU exceptions. The empty value inherits from the next, less specific level; its label
	 * differs between products (inherit from category) and categories (inherit the global default).
	 *
	 * @param bool $is_product Whether the control is rendered on a product (true) or a category (false).
	 *
	 * @return array<string, string>
	 */
	private function choices( bool $is_product ): array {
		$inherit_label = $is_product
			? __( '— Inherit from category (Standard)', 'erred-eu-order-withdrawal-for-woocommerce' )
			: __( '— Inherit (global default policy)', 'erred-eu-order-withdrawal-for-woocommerce' );

		return array(
			Settings::STATUS_INHERIT              => $inherit_label,
			Settings::STATUS_STANDARD             => __( 'Standard — withdrawal applies', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART16M_DIGITAL       => __( 'Digital content (Art. 16(m)) — excluded, requires mandatory consent at checkout', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART14_4A_SERVICE     => __( 'Service started early (Art. 14(4)(a)) — withdrawal applies, optional consent at checkout for pro-rata billing', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART16L_ACCOMMODATION => __( 'Dated accommodation, transport, car rental, catering or leisure (Art. 16(l)) — excluded, no consent needed', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART16_OTHER          => __( 'Other Article 16 exception — excluded (perishable, custom-made, hygiene-sealed, sealed media, etc.), no consent needed', 'erred-eu-order-withdrawal-for-woocommerce' ),
		);
	}

	/**
	 * Render the product-level select in the product editor's General tab.
	 */
	public function render_product_field(): void {
		global $post;
		$value = $post instanceof \WP_Post ? (string) get_post_meta( (int) $post->ID, Settings::META_PRODUCT_STATUS, true ) : '';

		woocommerce_wp_select(
			array(
				'id'          => Settings::META_PRODUCT_STATUS,
				'label'       => __( 'Withdrawal status', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Defines whether this product is excluded from the EU right of withdrawal and which checkout consent (if any) is shown to the customer. Categories can set a default that products inherit.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'value'       => $value,
				'options'     => $this->choices( true ),
			)
		);
	}

	/**
	 * Persist the product-level status onto the product object. Guarded by the WooCommerce product
	 * editor nonce and the per-product edit capability.
	 *
	 * @param \WC_Product $product The product being saved.
	 */
	public function save_product_field( \WC_Product $product ): void {
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		if ( ! isset( $_POST['woocommerce_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		$raw   = isset( $_POST[ Settings::META_PRODUCT_STATUS ] ) ? sanitize_key( wp_unslash( $_POST[ Settings::META_PRODUCT_STATUS ] ) ) : '';
		$value = $this->sanitize_status( $raw );

		if ( Settings::STATUS_INHERIT === $value ) {
			$product->delete_meta_data( Settings::META_PRODUCT_STATUS );
			return;
		}

		$product->update_meta_data( Settings::META_PRODUCT_STATUS, $value );
	}

	/**
	 * Render the status control on the "add category" screen.
	 */
	public function render_category_add_field(): void {
		?>
		<div class="form-field">
			<label for="<?php echo esc_attr( Settings::META_TERM_STATUS ); ?>"><?php esc_html_e( 'Right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<?php wp_nonce_field( self::TERM_NONCE_ACTION, self::TERM_NONCE_NAME ); ?>
			<?php $this->render_term_select( Settings::STATUS_INHERIT ); ?>
			<p><?php esc_html_e( 'Default art. 59 status for products in this category (products can still override it).', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the status control on the "edit category" screen.
	 *
	 * @param \WP_Term $term The category term being edited.
	 */
	public function render_category_edit_field( \WP_Term $term ): void {
		$value = (string) get_term_meta( $term->term_id, Settings::META_TERM_STATUS, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( Settings::META_TERM_STATUS ); ?>"><?php esc_html_e( 'Right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label></th>
			<td>
				<?php wp_nonce_field( self::TERM_NONCE_ACTION, self::TERM_NONCE_NAME ); ?>
				<?php $this->render_term_select( $value ); ?>
				<p class="description"><?php esc_html_e( 'Default art. 59 status for products in this category (products can still override it).', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the category-level status. Guarded by capability and nonce.
	 *
	 * @param int $term_id The category term id.
	 */
	public function save_category_field( int $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::TERM_NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::TERM_NONCE_NAME ] ) ), self::TERM_NONCE_ACTION ) ) {
			return;
		}

		$raw   = isset( $_POST[ Settings::META_TERM_STATUS ] ) ? sanitize_key( wp_unslash( $_POST[ Settings::META_TERM_STATUS ] ) ) : '';
		$value = $this->sanitize_status( $raw );

		if ( Settings::STATUS_INHERIT === $value ) {
			delete_term_meta( $term_id, Settings::META_TERM_STATUS );
			return;
		}

		update_term_meta( $term_id, Settings::META_TERM_STATUS, $value );
	}

	/**
	 * Render the shared term-level select control.
	 *
	 * @param string $current Currently selected value.
	 */
	private function render_term_select( string $current ): void {
		echo '<select name="' . esc_attr( Settings::META_TERM_STATUS ) . '" id="' . esc_attr( Settings::META_TERM_STATUS ) . '">';
		foreach ( $this->choices( false ) as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Constrain a raw status to one of the known values, defaulting to "inherit".
	 *
	 * @param string $value Raw status value.
	 */
	private function sanitize_status( string $value ): string {
		$valid = array_merge( Settings::allowing_statuses(), Settings::excluding_statuses() );

		return in_array( $value, $valid, true ) ? $value : Settings::STATUS_INHERIT;
	}
}
