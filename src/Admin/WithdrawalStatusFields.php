<?php
/**
 * Per-product and per-category art. 59 withdrawal-status editor fields.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Domain\Eligibility\WindowBasis;
use Recesso54bis\Integration\Multilingual;
use Recesso54bis\Integration\WindowBasisResolver;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Right of withdrawal" status control to the product editor (General tab) and to the product
 * category add/edit screens, letting the merchant mark individual products or whole categories as
 * excluded (or explicitly allowed) under art. 59. The value is read by {@see \Recesso54bis\Integration\EligibilityAdapter}
 * ahead of the global option lists and the default policy.
 *
 * A second control, next to it, sets what the withdrawal period is counted from, so a store selling
 * both goods and services can count each from the right event. Read by {@see WindowBasisResolver}.
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
	 * On a translation whose original has a value, "inherit" takes that value first, and the label
	 * says so — otherwise a translator would see "inherit from category" while the original's
	 * setting is what actually applies.
	 *
	 * @param bool   $is_product Whether the control is rendered on a product (true) or a category (false).
	 * @param string $original   The original translation's value, '' when there is none.
	 *
	 * @return array<string, string>
	 */
	private function choices( bool $is_product, string $original = '' ): array {
		$inherit_label = $is_product
			? __( '— Inherit from category (Standard)', 'erred-eu-order-withdrawal-for-woocommerce' )
			: __( '— Inherit (global default policy)', 'erred-eu-order-withdrawal-for-woocommerce' );

		$choices = array(
			Settings::STATUS_INHERIT              => $inherit_label,
			Settings::STATUS_STANDARD             => __( 'Standard — withdrawal applies', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART16M_DIGITAL       => __( 'Digital content (Art. 16(m)) — excluded, requires mandatory consent at checkout', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART14_4A_SERVICE     => __( 'Service started early (Art. 14(4)(a)) — withdrawal applies, optional consent at checkout for pro-rata billing', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART16L_ACCOMMODATION => __( 'Dated accommodation, transport, car rental, catering or leisure (Art. 16(l)) — excluded, no consent needed', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::STATUS_ART16_OTHER          => __( 'Other Article 16 exception — excluded (perishable, custom-made, hygiene-sealed, sealed media, etc.), no consent needed', 'erred-eu-order-withdrawal-for-woocommerce' ),
		);

		return $this->with_original( $choices, $original );
	}

	/**
	 * Relabel the "inherit" choice when a translation inherits its original's value.
	 *
	 * @param array<string, string> $choices  Value => label, '' being "inherit".
	 * @param string                $original The original translation's value, '' when there is none.
	 *
	 * @return array<string, string>
	 */
	private function with_original( array $choices, string $original ): array {
		if ( '' === $original || ! isset( $choices[ $original ] ) ) {
			return $choices;
		}

		$choices[''] = sprintf(
			/* translators: %s: the setting on the original-language product or category. */
			__( '— Same as the original language: %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
			$choices[ $original ]
		);

		return $choices;
	}

	/**
	 * The value set on the original of a translated product, '' when it is not a translation or the
	 * original has none.
	 *
	 * @param int    $product_id Product id.
	 * @param string $key        Meta key.
	 */
	private function original_product_value( int $product_id, string $key ): string {
		$original = Multilingual::original_product( $product_id );

		return $original === $product_id ? '' : (string) get_post_meta( $original, $key, true );
	}

	/**
	 * The value set on the original of a translated category, '' when it is not a translation.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key.
	 */
	private function original_category_value( int $term_id, string $key ): string {
		$original = Multilingual::original_category( $term_id );

		return $original === $term_id ? '' : (string) get_term_meta( $original, $key, true );
	}

	/**
	 * The selectable period bases (value => label). The empty value inherits; its label names the
	 * store-wide setting it currently resolves to, so "inherit" never hides what it means.
	 *
	 * @param bool   $is_product Whether the control is rendered on a product (true) or a category (false).
	 * @param string $original   The original translation's value, '' when there is none.
	 *
	 * @return array<string, string>
	 */
	private function basis_choices( bool $is_product, string $original = '' ): array {
		$store = Settings::TRIGGER_CONCLUSION === ( new Settings() )->start_trigger()
			? __( 'purchase', 'erred-eu-order-withdrawal-for-woocommerce' )
			: __( 'delivery', 'erred-eu-order-withdrawal-for-woocommerce' );

		$inherit_label = $is_product
			/* translators: %s: the store-wide setting, "delivery" or "purchase". */
			? sprintf( __( '— Inherit from category (store setting: %s)', 'erred-eu-order-withdrawal-for-woocommerce' ), $store )
			/* translators: %s: the store-wide setting, "delivery" or "purchase". */
			: sprintf( __( '— Inherit (store setting: %s)', 'erred-eu-order-withdrawal-for-woocommerce' ), $store );

		$choices = array(
			''                      => $inherit_label,
			WindowBasis::DELIVERY   => __( 'Delivery — goods', 'erred-eu-order-withdrawal-for-woocommerce' ),
			WindowBasis::CONCLUSION => __( 'Purchase — services and digital content', 'erred-eu-order-withdrawal-for-woocommerce' ),
		);

		return $this->with_original( $choices, WindowBasisResolver::stored( $original ) );
	}

	/**
	 * What the period-basis control means, shared by the product and category screens.
	 */
	private function basis_description(): string {
		return __( 'For goods the period runs from delivery, which WooCommerce records as the order being completed. For services, such as a course or a membership, and for digital content it runs from the purchase. Set this where your catalogue mixes the two, so each item in an order gets its own deadline.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Render the product-level select in the product editor's General tab.
	 */
	public function render_product_field(): void {
		global $post;
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$value   = $post_id > 0 ? (string) get_post_meta( $post_id, Settings::META_PRODUCT_STATUS, true ) : '';

		woocommerce_wp_select(
			array(
				'id'          => Settings::META_PRODUCT_STATUS,
				'label'       => __( 'Withdrawal status', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Defines whether this product is excluded from the EU right of withdrawal and which checkout consent (if any) is shown to the customer. Categories can set a default that products inherit.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'value'       => $value,
				'options'     => $this->choices( true, $post_id > 0 ? $this->original_product_value( $post_id, Settings::META_PRODUCT_STATUS ) : '' ),
			)
		);

		$basis = $post_id > 0 ? WindowBasisResolver::stored( get_post_meta( $post_id, Settings::META_PRODUCT_WINDOW_BASIS, true ) ) : '';

		woocommerce_wp_select(
			array(
				'id'          => Settings::META_PRODUCT_WINDOW_BASIS,
				'label'       => __( 'Withdrawal period starts from', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'desc_tip'    => true,
				'description' => $this->basis_description(),
				'value'       => $basis,
				'options'     => $this->basis_choices( true, $post_id > 0 ? $this->original_product_value( $post_id, Settings::META_PRODUCT_WINDOW_BASIS ) : '' ),
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
		} else {
			$product->update_meta_data( Settings::META_PRODUCT_STATUS, $value );
		}

		$raw_basis = isset( $_POST[ Settings::META_PRODUCT_WINDOW_BASIS ] ) ? sanitize_key( wp_unslash( $_POST[ Settings::META_PRODUCT_WINDOW_BASIS ] ) ) : '';
		$basis     = WindowBasisResolver::stored( $raw_basis );

		if ( '' === $basis ) {
			$product->delete_meta_data( Settings::META_PRODUCT_WINDOW_BASIS );
		} else {
			$product->update_meta_data( Settings::META_PRODUCT_WINDOW_BASIS, $basis );
		}
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
		<div class="form-field">
			<label for="<?php echo esc_attr( Settings::META_TERM_WINDOW_BASIS ); ?>"><?php esc_html_e( 'Withdrawal period starts from', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<?php $this->render_basis_select( '' ); ?>
			<p><?php echo esc_html( $this->basis_description() ); ?></p>
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
				<?php $this->render_term_select( $value, $this->original_category_value( $term->term_id, Settings::META_TERM_STATUS ) ); ?>
				<p class="description"><?php esc_html_e( 'Default art. 59 status for products in this category (products can still override it).', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( Settings::META_TERM_WINDOW_BASIS ); ?>"><?php esc_html_e( 'Withdrawal period starts from', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label></th>
			<td>
				<?php $this->render_basis_select( WindowBasisResolver::stored( get_term_meta( $term->term_id, Settings::META_TERM_WINDOW_BASIS, true ) ), $this->original_category_value( $term->term_id, Settings::META_TERM_WINDOW_BASIS ) ); ?>
				<p class="description"><?php echo esc_html( $this->basis_description() ); ?></p>
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
		} else {
			update_term_meta( $term_id, Settings::META_TERM_STATUS, $value );
		}

		$raw_basis = isset( $_POST[ Settings::META_TERM_WINDOW_BASIS ] ) ? sanitize_key( wp_unslash( $_POST[ Settings::META_TERM_WINDOW_BASIS ] ) ) : '';
		$basis     = WindowBasisResolver::stored( $raw_basis );

		if ( '' === $basis ) {
			delete_term_meta( $term_id, Settings::META_TERM_WINDOW_BASIS );
		} else {
			update_term_meta( $term_id, Settings::META_TERM_WINDOW_BASIS, $basis );
		}
	}

	/**
	 * Render the shared term-level select control.
	 *
	 * @param string $current  Currently selected value.
	 * @param string $original The original translation's value, '' when there is none.
	 */
	private function render_term_select( string $current, string $original = '' ): void {
		echo '<select name="' . esc_attr( Settings::META_TERM_STATUS ) . '" id="' . esc_attr( Settings::META_TERM_STATUS ) . '">';
		foreach ( $this->choices( false, $original ) as $value => $label ) {
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
	 * Render the term-level period-basis select.
	 *
	 * @param string $current  Currently selected value ('' for inherit).
	 * @param string $original The original translation's value, '' when there is none.
	 */
	private function render_basis_select( string $current, string $original = '' ): void {
		echo '<select name="' . esc_attr( Settings::META_TERM_WINDOW_BASIS ) . '" id="' . esc_attr( Settings::META_TERM_WINDOW_BASIS ) . '">';
		foreach ( $this->basis_choices( false, $original ) as $value => $label ) {
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
