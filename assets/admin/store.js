/**
 * Data store (built on the wp.data API) for the Recesso Digitale admin.
 *
 * Holds the list/detail UI state and performs all data access through @wordpress/api-fetch (which
 * injects the REST cookie nonce automatically). Async actions use generator controls, so components
 * never fetch directly and there is no prop-drilling of server data.
 */
import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export const STORE_NAME = 'erred-eu-order-withdrawal-for-woocommerce';

const API_BASE = '/recesso-digitale/v1/admin/withdrawals';
const PER_PAGE = 20;

const DEFAULT_STATE = {
	items: [],
	total: 0,
	status: '',
	search: '',
	page: 1,
	loading: false,
	busy: false,
	error: '',
	detail: null,
	stats: null,
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'SET_LOADING':
			return { ...state, loading: action.loading };
		case 'SET_LIST':
			return {
				...state,
				items: action.items,
				total: action.total,
				loading: false,
				error: '',
			};
		case 'SET_FILTER':
			return { ...state, status: action.status, page: 1 };
		case 'SET_SEARCH':
			return { ...state, search: action.search, page: 1 };
		case 'SET_PAGE':
			return { ...state, page: action.page };
		case 'SET_DETAIL':
			return { ...state, detail: action.detail, busy: false };
		case 'SET_STATS':
			return { ...state, stats: action.stats };
		case 'SET_BUSY':
			return { ...state, busy: action.busy };
		case 'SET_ERROR':
			return {
				...state,
				error: action.error,
				loading: false,
				busy: false,
			};
		default:
			return state;
	}
};

const actions = {
	setFilter: ( status ) => ( { type: 'SET_FILTER', status } ),
	setSearch: ( search ) => ( { type: 'SET_SEARCH', search } ),
	setPage: ( page ) => ( { type: 'SET_PAGE', page } ),
	closeDetail: () => ( { type: 'SET_DETAIL', detail: null } ),

	*loadList( status, page, search = '' ) {
		yield { type: 'SET_LOADING', loading: true };
		try {
			const path = addQueryArgs( API_BASE, {
				status,
				search,
				page,
				per_page: PER_PAGE,
			} );
			const response = yield {
				type: 'API',
				options: { path, parse: false },
			};
			const total = parseInt(
				response.headers.get( 'X-WP-Total' ) || '0',
				10
			);
			const items = yield { type: 'JSON', response };
			yield { type: 'SET_LIST', items, total };
		} catch ( e ) {
			yield { type: 'SET_ERROR', error: e.message || 'Error' };
		}
	},

	*loadStats() {
		try {
			const stats = yield {
				type: 'API',
				options: { path: `${ API_BASE }/stats` },
			};
			yield { type: 'SET_STATS', stats };
		} catch ( e ) {
			// Stats are non-critical; ignore failures silently.
		}
	},

	*openDetail( id ) {
		try {
			const detail = yield {
				type: 'API',
				options: { path: `${ API_BASE }/${ id }` },
			};
			yield { type: 'SET_DETAIL', detail };
		} catch ( e ) {
			yield { type: 'SET_ERROR', error: e.message || 'Error' };
		}
	},

	*process( id, action, reason = '', status = '' ) {
		yield { type: 'SET_BUSY', busy: true };
		try {
			const detail = yield {
				type: 'API',
				options: {
					path: `${ API_BASE }/${ id }/status`,
					method: 'POST',
					data: { action, reason, status },
				},
			};
			yield { type: 'SET_DETAIL', detail };
		} catch ( e ) {
			yield { type: 'SET_ERROR', error: e.message || 'Error' };
		}
	},
};

const selectors = {
	getItems: ( state ) => state.items,
	getTotal: ( state ) => state.total,
	getStatus: ( state ) => state.status,
	getSearch: ( state ) => state.search,
	getPage: ( state ) => state.page,
	isLoading: ( state ) => state.loading,
	isBusy: ( state ) => state.busy,
	getError: ( state ) => state.error,
	getDetail: ( state ) => state.detail,
	getStats: ( state ) => state.stats,
	getPerPage: () => PER_PAGE,
};

const controls = {
	API: ( { options } ) => apiFetch( options ),
	JSON: ( { response } ) => response.json(),
};

export function registerStore() {
	register(
		createReduxStore( STORE_NAME, {
			reducer,
			actions,
			selectors,
			controls,
		} )
	);
}
