/**
 * Recesso Digitale admin app: a filterable, paginated list of withdrawal requests with a detail
 * panel showing the audit timeline and processing actions. State and data come from the registered
 * wp.data store; components stay presentational.
 */
import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { useDebounce } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	Button,
	SelectControl,
	SearchControl,
	TextareaControl,
	Notice,
	Modal,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { STORE_NAME } from './store';

const STATUS_OPTIONS = [
	{
		value: '',
		label: __(
			'All statuses',
			'erred-eu-order-withdrawal-for-woocommerce'
		),
	},
	{
		value: 'pending',
		label: __( 'Pending', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'confirmed',
		label: __( 'Confirmed', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'acknowledged',
		label: __(
			'Acknowledged',
			'erred-eu-order-withdrawal-for-woocommerce'
		),
	},
	{
		value: 'accepted',
		label: __( 'Accepted', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'completed',
		label: __( 'Completed', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'refunded',
		label: __( 'Refunded', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'rejected',
		label: __( 'Rejected', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'expired',
		label: __( 'Expired', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
];

// The simplified decisions the merchant sets from the detail panel.
const SET_STATUS_OPTIONS = [
	{
		value: 'pending',
		label: __( 'Pending', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'accepted',
		label: __( 'Accepted', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'rejected',
		label: __( 'Rejected', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
	{
		value: 'completed',
		label: __( 'Completed', 'erred-eu-order-withdrawal-for-woocommerce' ),
	},
];

// Badge colours per admin status — mirror the orders-list column. Colour never carries meaning alone.
const STATUS_BADGE = {
	pending: { bg: '#dcdcde', fg: '#1d2327' },
	accepted: { bg: '#c6e1c6', fg: '#14502a' },
	rejected: { bg: '#f1adad', fg: '#7a1c1c' },
	completed: { bg: '#c8d7e1', fg: '#0a4b78' },
};

function StatusBadge( { status } ) {
	const colours = STATUS_BADGE[ status ] || STATUS_BADGE.pending;
	const label =
		( SET_STATUS_OPTIONS.find( ( o ) => o.value === status ) || {} )
			.label || status;
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '2px 8px',
				borderRadius: '4px',
				fontWeight: 600,
				background: colours.bg,
				color: colours.fg,
			} }
		>
			{ label }
		</span>
	);
}

// Human-readable, translatable labels for the audit-log event identifiers stored in the database, so
// the timeline never shows raw ids like "receipt_sent". Unknown events fall back to their raw value.
const EVENT_LABELS = {
	created: __(
		'Request created',
		'erred-eu-order-withdrawal-for-woocommerce'
	),
	confirmed: __(
		'Withdrawal confirmed',
		'erred-eu-order-withdrawal-for-woocommerce'
	),
	receipt_sent: __(
		'Receipt sent',
		'erred-eu-order-withdrawal-for-woocommerce'
	),
	status_change: __(
		'Status change',
		'erred-eu-order-withdrawal-for-woocommerce'
	),
	access_denied: __(
		'Access denied',
		'erred-eu-order-withdrawal-for-woocommerce'
	),
};

function eventLabel( event ) {
	return EVENT_LABELS[ event ] || event;
}

// The actor is stored as "consumer", "system" or "admin:{user_id}". Present it in a readable, localised
// form rather than the raw descriptor.
function actorLabel( actor ) {
	if ( 'consumer' === actor ) {
		return __( 'consumer', 'erred-eu-order-withdrawal-for-woocommerce' );
	}
	if ( 'system' === actor ) {
		return __( 'system', 'erred-eu-order-withdrawal-for-woocommerce' );
	}
	if ( actor && 0 === actor.indexOf( 'admin:' ) ) {
		return sprintf(
			/* translators: %s: administrator user id. */
			__(
				'administrator (#%s)',
				'erred-eu-order-withdrawal-for-woocommerce'
			),
			actor.slice( 'admin:'.length )
		);
	}
	return actor;
}

function Timeline( { timeline } ) {
	if ( ! timeline || ! timeline.length ) {
		return null;
	}
	return (
		<ul className="recesso-dig-admin__timeline">
			{ timeline.map( ( row ) => (
				<li key={ row.id }>
					<strong>{ row.created_at_gmt }</strong> —{ ' ' }
					{ eventLabel( row.event ) } ({ actorLabel( row.actor ) })
				</li>
			) ) }
		</ul>
	);
}

function Detail( { detail, busy, onClose, onProcess } ) {
	const [ reason, setReason ] = useState( '' );
	const [ adminStatus, setAdminStatus ] = useState( 'pending' );

	// Sync the local controls to the server state whenever a request opens or its decision changes
	// (e.g. after saving). Resetting the reason here clears it once a decision is recorded.
	const detailId = detail ? detail.id : null;
	const serverStatus = detail ? detail.admin_status : null;
	useEffect( () => {
		setAdminStatus( serverStatus || 'pending' );
		setReason( '' );
	}, [ detailId, serverStatus ] );

	if ( ! detail ) {
		return null;
	}
	const trimmedReason = reason.trim();
	const rejecting = 'rejected' === adminStatus;
	const saveDisabled =
		busy ||
		( rejecting && '' === trimmedReason ) ||
		adminStatus === detail.admin_status;
	return (
		<Modal
			title={
				__(
					'Withdrawal request',
					'erred-eu-order-withdrawal-for-woocommerce'
				) +
				' #' +
				detail.id
			}
			onRequestClose={ onClose }
		>
			<p>
				<strong>
					{ __(
						'Order',
						'erred-eu-order-withdrawal-for-woocommerce'
					) }
					:
				</strong>{ ' ' }
				{ detail.order_id } &nbsp;
				<strong>
					{ __(
						'Status',
						'erred-eu-order-withdrawal-for-woocommerce'
					) }
					:
				</strong>{ ' ' }
				<StatusBadge status={ detail.admin_status } />
			</p>
			<p>
				<strong>
					{ __(
						'Confirmed (GMT)',
						'erred-eu-order-withdrawal-for-woocommerce'
					) }
					:
				</strong>{ ' ' }
				{ detail.confirmed_at_gmt || '—' }
			</p>
			<p>
				<strong>
					{ __(
						'Acknowledged (GMT)',
						'erred-eu-order-withdrawal-for-woocommerce'
					) }
					:
				</strong>{ ' ' }
				{ detail.acknowledged_at_gmt || '—' }
			</p>

			{ detail.receipt_hash && (
				<p>
					<strong>
						{ __(
							'Receipt verification code',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
						:
					</strong>{ ' ' }
					<code style={ { wordBreak: 'break-all' } }>
						{ detail.receipt_hash }
					</code>
				</p>
			) }

			{ detail.withdrawal_reason && (
				<p>
					<strong>
						{ __(
							'Reason',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
						:
					</strong>{ ' ' }
					{ detail.withdrawal_reason }
				</p>
			) }

			{ detail.refund_iban && (
				<p>
					<strong>
						{ __(
							'Refund IBAN',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
						:
					</strong>{ ' ' }
					{ detail.refund_iban }
				</p>
			) }

			{ detail.items && detail.items.length > 0 && (
				<div>
					<p>
						<strong>
							{ __(
								'Items',
								'erred-eu-order-withdrawal-for-woocommerce'
							) }
							:
						</strong>{ ' ' }
						{ detail.is_partial
							? __(
									'Partial withdrawal',
									'erred-eu-order-withdrawal-for-woocommerce'
							  )
							: __(
									'Whole order',
									'erred-eu-order-withdrawal-for-woocommerce'
							  ) }
					</p>
					<ul className="recesso-dig-admin__items">
						{ detail.items.map( ( label, index ) => (
							<li key={ index }>{ label }</li>
						) ) }
					</ul>
				</div>
			) }

			{ detail.receipt_url ? (
				<p>
					<Button
						variant="secondary"
						href={ detail.receipt_url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'View receipt (PDF)',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</Button>
				</p>
			) : (
				<p>
					<em>
						{ __(
							'No receipt yet. Use “Regenerate receipt” to create it.',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</em>
				</p>
			) }

			<h3>
				{ __(
					'Audit timeline',
					'erred-eu-order-withdrawal-for-woocommerce'
				) }
			</h3>
			<Timeline timeline={ detail.timeline } />

			<SelectControl
				label={ __(
					'Set status',
					'erred-eu-order-withdrawal-for-woocommerce'
				) }
				value={ adminStatus }
				options={ SET_STATUS_OPTIONS }
				onChange={ setAdminStatus }
				__nextHasNoMarginBottom
			/>

			{ rejecting && (
				<TextareaControl
					label={ __(
						'Reason (required to reject — sent to the customer)',
						'erred-eu-order-withdrawal-for-woocommerce'
					) }
					value={ reason }
					onChange={ setReason }
					rows={ 3 }
					__nextHasNoMarginBottom
					style={ { marginTop: '1rem' } }
				/>
			) }

			<Flex
				justify="flex-start"
				gap={ 3 }
				style={ { marginTop: '1rem' } }
			>
				<FlexItem>
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => onProcess( detail.id, 'regenerate' ) }
					>
						{ __(
							'Regenerate receipt',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						disabled={ saveDisabled }
						onClick={ () =>
							onProcess(
								detail.id,
								'set_status',
								rejecting ? trimmedReason : '',
								adminStatus
							)
						}
					>
						{ __(
							'Save status',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</Button>
				</FlexItem>
				{ busy && (
					<FlexItem>
						<Spinner />
					</FlexItem>
				) }
			</Flex>
		</Modal>
	);
}

export default function App() {
	const {
		items,
		total,
		status,
		search,
		page,
		loading,
		busy,
		error,
		detail,
		perPage,
		stats,
	} = useSelect( ( select ) => {
		const s = select( STORE_NAME );
		return {
			items: s.getItems(),
			total: s.getTotal(),
			status: s.getStatus(),
			search: s.getSearch(),
			page: s.getPage(),
			loading: s.isLoading(),
			busy: s.isBusy(),
			error: s.getError(),
			detail: s.getDetail(),
			perPage: s.getPerPage(),
			stats: s.getStats(),
		};
	}, [] );

	const {
		loadList,
		loadStats,
		setFilter,
		setSearch,
		setPage,
		openDetail,
		closeDetail,
		process,
	} = useDispatch( STORE_NAME );

	// Local input mirror so typing stays responsive; the store (and the query) update on a debounce.
	const [ searchInput, setSearchInput ] = useState( search );
	const debouncedSetSearch = useDebounce( setSearch, 300 );

	useEffect( () => {
		loadList( status, page, search );
		loadStats();
	}, [ status, search, page, loadList, loadStats ] );

	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	const exportUrl =
		( typeof window !== 'undefined' &&
			window.recessoDigAdmin &&
			window.recessoDigAdmin.exportUrl ) ||
		'';

	return (
		<div className="recesso-dig-admin">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ stats && (
				<Flex
					justify="flex-start"
					gap={ 4 }
					style={ { marginBottom: '1rem' } }
					className="recesso-dig-admin__stats"
				>
					<FlexItem>
						<strong>{ stats.open }</strong>{ ' ' }
						{ __(
							'awaiting action',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</FlexItem>
					<FlexItem>
						<strong>{ stats.recent_confirmed }</strong>{ ' ' }
						{ __(
							'confirmed (30 days)',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</FlexItem>
					<FlexItem>
						<strong>{ stats.acknowledged }</strong>{ ' ' }
						{ __(
							'acknowledged',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</FlexItem>
					<FlexItem>
						<strong>{ stats.total }</strong>{ ' ' }
						{ __(
							'total',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</FlexItem>
				</Flex>
			) }

			<Flex
				justify="space-between"
				align="flex-end"
				style={ { marginBottom: '1rem' } }
			>
				<FlexItem>
					<SelectControl
						label={ __(
							'Filter by status',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
						value={ status }
						options={ STATUS_OPTIONS }
						onChange={ ( value ) => setFilter( value ) }
						__nextHasNoMarginBottom
					/>
				</FlexItem>
				<FlexItem>
					<SearchControl
						label={ __(
							'Search by order, name or email',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
						value={ searchInput }
						onChange={ ( value ) => {
							setSearchInput( value );
							debouncedSetSearch( value );
						} }
						__nextHasNoMarginBottom
					/>
				</FlexItem>
				<FlexItem>
					<Button
						variant="secondary"
						onClick={ () => loadList( status, page, search ) }
					>
						{ __(
							'Refresh',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</Button>
				</FlexItem>
				{ exportUrl && (
					<FlexItem>
						<Button
							variant="secondary"
							href={ addQueryArgs( exportUrl, {
								status,
								search,
							} ) }
						>
							{ __(
								'Export CSV',
								'erred-eu-order-withdrawal-for-woocommerce'
							) }
						</Button>
					</FlexItem>
				) }
			</Flex>

			{ loading ? (
				<Spinner />
			) : (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'ID',
									'erred-eu-order-withdrawal-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Order',
									'erred-eu-order-withdrawal-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Status',
									'erred-eu-order-withdrawal-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Confirmed (GMT)',
									'erred-eu-order-withdrawal-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Receipt',
									'erred-eu-order-withdrawal-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Actions',
									'erred-eu-order-withdrawal-for-woocommerce'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.length === 0 && (
							<tr>
								<td colSpan={ 6 }>
									{ __(
										'No withdrawal requests yet.',
										'erred-eu-order-withdrawal-for-woocommerce'
									) }
								</td>
							</tr>
						) }
						{ items.map( ( item ) => (
							<tr key={ item.id }>
								<td>{ item.id }</td>
								<td>{ item.order_id }</td>
								<td>
									<StatusBadge status={ item.admin_status } />
								</td>
								<td>{ item.confirmed_at_gmt || '—' }</td>
								<td>
									{ item.has_receipt
										? __(
												'Yes',
												'erred-eu-order-withdrawal-for-woocommerce'
										  )
										: '—' }
								</td>
								<td>
									<Button
										variant="link"
										onClick={ () => openDetail( item.id ) }
									>
										{ __(
											'View',
											'erred-eu-order-withdrawal-for-woocommerce'
										) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<Flex justify="flex-end" gap={ 2 } style={ { marginTop: '1rem' } }>
				<FlexItem>
					<Button
						variant="secondary"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __(
							'Previous',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</Button>
				</FlexItem>
				<FlexItem>
					{
						/* translators: 1: current page, 2: total pages. */
						__(
							'Page',
							'erred-eu-order-withdrawal-for-woocommerce'
						) + ` ${ page } / ${ totalPages }`
					}
				</FlexItem>
				<FlexItem>
					<Button
						variant="secondary"
						disabled={ page >= totalPages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __(
							'Next',
							'erred-eu-order-withdrawal-for-woocommerce'
						) }
					</Button>
				</FlexItem>
			</Flex>

			<Detail
				detail={ detail }
				busy={ busy }
				onClose={ closeDetail }
				onProcess={ process }
			/>
		</div>
	);
}
