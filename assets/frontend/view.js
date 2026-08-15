/**
 * Progressive enhancement for the server-rendered withdrawal flow.
 *
 * The flow works fully without JavaScript (real <form> POSTs + page reloads, with native HTML5
 * field validation). When this script runs it only *enhances* the experience: it submits each step
 * in the background to the same nonce-guarded admin-post handler, then swaps the rendered flow
 * fragment in place — no full page reload — and manages focus for assistive technology. It never
 * duplicates the server's logic or security: the server remains the single source of truth, the
 * browser still enforces field validation before submit, and any failure falls back to a normal
 * submit.
 */
import domReady from '@wordpress/dom-ready';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import './style.css';

const FLOW = '.wp-block-recesso-digitale-flow';
const FORM = '.wp-block-recesso-digitale-flow__form';
const ITEM = '.wp-block-recesso-digitale-flow__item';
const CHECK = '.wp-block-recesso-digitale-flow__item-check';
const QTY_WRAP = '.wp-block-recesso-digitale-flow__item-qty';
const QTY_INPUT = '.wp-block-recesso-digitale-flow__qty-input';
const ENHANCED = 'recessoDigEnhanced';
const ERROR = '.wp-block-recesso-digitale-flow__error';
const MODEL_FORM = '.wp-block-recesso-digitale-model-form';
const PRINT_BUTTON = '.wp-block-recesso-digitale-model-form__print-button';

/**
 * Attach the submit handler to the form inside a flow container (once), and wire the per-line
 * checkbox → quantity enhancement.
 *
 * @param {Element} container The flow container.
 */
function enhance( container ) {
	const form = container.querySelector( FORM );
	if ( ! form || form.dataset[ ENHANCED ] ) {
		return;
	}
	form.dataset[ ENHANCED ] = '1';
	form.addEventListener( 'submit', onSubmit );
	setupSelection( container );
}

/**
 * Enhance the declaration's item picker: a line's quantity control is revealed and enabled only when
 * its checkbox is ticked, and the row is marked selected. Without this script the quantity input
 * stays visible and the server ignores quantities for unticked lines, so the flow still works.
 *
 * @param {Element} container The flow container.
 */
function setupSelection( container ) {
	const checks = container.querySelectorAll( CHECK );
	checks.forEach( ( check ) => {
		const item = check.closest( ITEM );
		if ( ! item ) {
			return;
		}
		const wrap = item.querySelector( QTY_WRAP );
		const input = item.querySelector( QTY_INPUT );

		const sync = () => {
			item.classList.toggle(
				'wp-block-recesso-digitale-flow__item--selected',
				check.checked
			);
			if ( wrap ) {
				wrap.hidden = ! check.checked;
			}
			if ( input ) {
				input.disabled = ! check.checked;
			}
		};

		check.addEventListener( 'change', sync );
		sync();
	} );
}

/**
 * Whether the declaration's item selection is valid (at least one line ticked). Returns true for any
 * step that has no item checkboxes (e.g. the confirm step).
 *
 * @param {HTMLFormElement} form The flow form.
 * @return {boolean} True when the selection is acceptable.
 */
function hasSelection( form ) {
	const checks = form.querySelectorAll( CHECK );
	if ( checks.length === 0 ) {
		return true;
	}
	return Array.from( checks ).some( ( check ) => check.checked );
}

/**
 * Intercept a flow form submission, send it in the background and swap in the next step. The browser
 * has already enforced native field validation by the time a submit event fires.
 *
 * @param {SubmitEvent} event The submit event.
 */
async function onSubmit( event ) {
	const form = event.currentTarget;

	// Inline validation the browser cannot express natively: at least one item must be selected.
	// Runs regardless of fetch support so the consumer is not bounced to the server to learn this.
	if ( ! hasSelection( form ) ) {
		event.preventDefault();
		const firstCheck = form.querySelector( CHECK );
		if ( firstCheck ) {
			firstCheck.focus();
		}
		speak(
			__(
				'Select at least one item to withdraw.',
				'erred-eu-order-withdrawal-for-woocommerce'
			),
			'assertive'
		);
		return;
	}

	// Only enhance when fetch is available; otherwise let the browser submit normally.
	if ( typeof window.fetch !== 'function' ) {
		return;
	}

	event.preventDefault();
	setBusy( form, true );

	// Use getAttribute: the form has an <input name="action">, which shadows form.action.
	const endpoint = form.getAttribute( 'action' );

	try {
		const response = await window.fetch( endpoint, {
			method: 'POST',
			body: new window.FormData( form ),
			credentials: 'same-origin',
			redirect: 'follow',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
		} );

		const markup = await response.text();
		const nextFlow = new window.DOMParser()
			.parseFromString( markup, 'text/html' )
			.querySelector( FLOW );

		const current = document.querySelector( FLOW );
		if ( ! nextFlow || ! current ) {
			// Unexpected response shape: fall back to a real navigation.
			window.location.assign( response.url || window.location.href );
			return;
		}

		current.replaceWith( nextFlow );
		if ( response.url ) {
			window.history.pushState( {}, '', response.url );
		}
		afterSwap( nextFlow );
	} catch ( e ) {
		// Network/parse failure: degrade to a normal submit.
		form.removeEventListener( 'submit', onSubmit );
		form.submit();
	}
}

/**
 * After replacing the flow fragment: re-bind, move focus to the new heading and announce it.
 *
 * @param {Element} container The new flow container.
 */
function afterSwap( container ) {
	enhance( container );

	// A validation error is what the reader needs first: sending them to the step heading instead
	// would announce "Withdrawal declaration" and leave them to discover on their own that the
	// submission was refused.
	const target =
		container.querySelector( ERROR ) ||
		container.querySelector( '.wp-block-recesso-digitale-flow__title' ) ||
		container;
	target.setAttribute( 'tabindex', '-1' );
	target.focus();

	const text = ( target.textContent || '' ).trim();
	if ( text ) {
		speak( text );
	}
}

/**
 * Move focus to the validation error after a full page reload (the no-JS submit path, where the
 * server re-renders the form with `recesso_dig_error` on the URL). role="alert" is announced
 * unreliably when the message is already present at load, so focus does the work.
 *
 * @param {Element} container The flow container.
 */
function focusInitialError( container ) {
	const error = container.querySelector( ERROR );
	if ( ! error ) {
		return;
	}

	error.focus();

	const text = ( error.textContent || '' ).trim();
	if ( text ) {
		speak( text );
	}
}

/**
 * Toggle a busy state on the form's submit control.
 *
 * @param {HTMLFormElement} form The form.
 * @param {boolean}         busy Whether the form is submitting.
 */
function setBusy( form, busy ) {
	const submit = form.querySelector( '[type="submit"]' );
	if ( submit ) {
		submit.disabled = busy;
		submit.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	}
}

/**
 * Enhance the Annex I.B model withdrawal form: reveal the "Open printable version" control (hidden
 * without JavaScript, since it depends on the print API) and wire it to the browser print dialog. The
 * form itself remains visible and printable through the browser menu without this enhancement.
 */
function enhanceModelForms() {
	document.querySelectorAll( MODEL_FORM ).forEach( ( modelForm ) => {
		const button = modelForm.querySelector( PRINT_BUTTON );
		if ( ! button || modelForm.dataset[ ENHANCED ] ) {
			return;
		}
		modelForm.dataset[ ENHANCED ] = '1';
		modelForm.classList.add( 'is-enhanced' );
		button.addEventListener( 'click', () => window.print() );
	} );
}

domReady( () => {
	const root = document.querySelector( FLOW );
	if ( root ) {
		enhance( root );
		focusInitialError( root );
	}

	enhanceModelForms();

	// The back/forward buttons should re-render the server state for the URL.
	window.addEventListener( 'popstate', () => window.location.reload() );
} );
