/**
 * Relays the sandboxed RAG backend UI's endpoint calls to RagProxyHandler.
 *
 * The iframe (served by RagUiHandler) has an opaque origin: it cannot make a
 * credentialed request to the wiki and is never given the CSRF token. Its fetch()
 * shim posts { kzRag: 'request' } messages here instead. This page - wiki code,
 * on the wiki origin - accepts them only from that iframe, only for the
 * endpoints listed on the iframe element, performs the call with the user's
 * session and CSRF token, and posts the response back.
 *
 * The testers also look up a source page's ID by title in the wiki's action API
 * ({ kzRag: 'pageLookup' }). Those are accepted only from a frame whose element
 * carries data-kzchatbot-page-lookup, and only as a single-title action=query
 * read. The request sent to api.php is rebuilt here from the validated values,
 * never forwarded as given.
 *
 * See kolzchut/kz-mediawiki-main#148.
 */
( function () {
	'use strict';

	const METHODS = { GET: true, POST: true };

	/**
	 * What the backend's WikiAPI.getPageId() sends, and nothing more:
	 * action=query&format=json&titles=<title>&formatversion=2&origin=*.
	 * A value of true means any single value is checked separately (titles);
	 * otherwise the list of values allowed. `origin` is accepted so the backend
	 * code needs no change, but is not forwarded: the call is same-origin.
	 */
	const LOOKUP_PARAMS = {
		action: [ 'query' ],
		format: [ 'json' ],
		formatversion: [ '1', '2' ],
		origin: [ '*' ],
		titles: true
	};
	const LOOKUP_FORWARDED = [ 'action', 'format', 'formatversion', 'titles' ];

	/**
	 * Validate a page lookup request.
	 *
	 * @param {Object} d The message
	 * @return {URLSearchParams|string} The api.php query to send, or why the
	 *  request is refused
	 */
	function parsePageLookup( d ) {
		if ( d.method !== 'GET' || d.body !== null ) {
			return 'only GET without a body is allowed';
		}
		if ( typeof d.query !== 'string' || /[#\s]/.test( d.query ) ) {
			return 'malformed query';
		}
		const given = {};
		for ( const [ key, value ] of new URLSearchParams( d.query ) ) {
			if ( !Object.prototype.hasOwnProperty.call( LOOKUP_PARAMS, key ) ) {
				return 'parameter "' + key + '" is not allowed';
			}
			if ( Object.prototype.hasOwnProperty.call( given, key ) ) {
				return 'parameter "' + key + '" is repeated';
			}
			if ( LOOKUP_PARAMS[ key ] !== true && !LOOKUP_PARAMS[ key ].includes( value ) ) {
				return key + '=' + value + ' is not allowed';
			}
			given[ key ] = value;
		}
		if ( given.action !== 'query' || given.format !== 'json' ) {
			return 'only action=query&format=json is allowed';
		}
		// One title: a "|" or U+001F would make it a multi-value list.
		const title = given.titles;
		if ( typeof title !== 'string' || !title.trim() || title.length > 255 ||
			title.includes( '|' ) || title.includes( '\x1f' ) ) {
			return 'exactly one title is required';
		}
		const query = new URLSearchParams();
		LOOKUP_FORWARDED.forEach( ( key ) => {
			if ( Object.prototype.hasOwnProperty.call( given, key ) ) {
				query.set( key, given[ key ] );
			}
		} );
		return query;
	}

	function initFrame( frame ) {
		const base = frame.getAttribute( 'data-kzchatbot-proxy-base' );
		let paths;
		try {
			paths = JSON.parse( frame.getAttribute( 'data-kzchatbot-proxy-paths' ) || '[]' );
		} catch ( e ) {
			return;
		}
		if ( !base || !Array.isArray( paths ) ) {
			return;
		}
		const pageLookup = frame.hasAttribute( 'data-kzchatbot-page-lookup' );
		const apiUrl = mw.config.get( 'wgScriptPath' ) + '/api.php';

		// The frame's origin is opaque, so '*' is the only target it matches.
		// Responses go only to the window the request came from.
		function post( msg ) {
			if ( frame.contentWindow ) {
				frame.contentWindow.postMessage( msg, '*' );
			}
		}

		function isValid( d ) {
			return typeof d.id === 'number' &&
				typeof d.path === 'string' && paths.includes( d.path ) &&
				Object.prototype.hasOwnProperty.call( METHODS, d.method ) &&
				typeof d.query === 'string' && !/[#\s]/.test( d.query ) &&
				( d.body === null || typeof d.body === 'string' ) &&
				!( d.method === 'GET' && d.body !== null );
		}

		function relay( id, url, options ) {
			fetch( url, options )
				.then( ( r ) => r.text().then( ( text ) => {
					post( {
						kzRag: 'response',
						id: id,
						status: r.status,
						statusText: r.statusText,
						contentType: r.headers.get( 'Content-Type' ) || '',
						body: text
					} );
				} ) )
				.catch( ( err ) => {
					post( { kzRag: 'response', id: id, error: String( err && err.message || err ) } );
				} );
		}

		function handlePageLookup( d ) {
			if ( !pageLookup ) {
				post( { kzRag: 'response', id: d.id, error: 'page lookup refused: not enabled for this interface' } );
				return;
			}
			const query = parsePageLookup( d );
			if ( typeof query === 'string' ) {
				post( { kzRag: 'response', id: d.id, error: 'page lookup refused: ' + query } );
				return;
			}
			// Same-origin, as the call was before the frame was sandboxed, so it
			// carries the edge's cookies like any page request. It also carries the
			// session, but with no token and this fixed read-only parameter set the
			// answer is what an anonymous reader gets: whether the page exists, and
			// its ID.
			relay( d.id, apiUrl + '?' + query.toString(), {
				method: 'GET',
				credentials: 'same-origin',
				headers: { Accept: 'application/json' }
			} );
		}

		window.addEventListener( 'message', ( e ) => {
			const d = e.data;
			if ( !frame.contentWindow || e.source !== frame.contentWindow || !d || typeof d !== 'object' ) {
				return;
			}
			if ( d.kzRag === 'hello' ) {
				post( { kzRag: 'ready' } );
				return;
			}
			if ( typeof d.id !== 'number' ) {
				return;
			}
			if ( d.kzRag === 'pageLookup' ) {
				handlePageLookup( d );
				return;
			}
			if ( d.kzRag !== 'request' ) {
				return;
			}
			if ( !isValid( d ) ) {
				post( { kzRag: 'response', id: d.id, error: 'request refused' } );
				return;
			}

			const headers = { Accept: 'application/json' };
			const options = { method: d.method, credentials: 'same-origin', headers: headers };
			if ( d.method === 'POST' ) {
				headers[ 'X-Csrf-Token' ] = mw.user.tokens.get( 'csrfToken' );
				if ( d.body !== null ) {
					headers[ 'Content-Type' ] = 'application/json';
					options.body = d.body;
				}
			}

			relay( d.id, base + d.path + ( d.query ? '?' + d.query : '' ), options );
		} );

		// The frame may already be running and waiting; its own "hello" covers
		// the case where it loads after this.
		post( { kzRag: 'ready' } );
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( 'iframe.kzchatbot-rag-iframe' ),
			initFrame
		);
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
