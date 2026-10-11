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
 * See kolzchut/kz-mediawiki-main#148.
 */
( function () {
	'use strict';

	const METHODS = { GET: true, POST: true };

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

		window.addEventListener( 'message', ( e ) => {
			const d = e.data;
			if ( !frame.contentWindow || e.source !== frame.contentWindow || !d || typeof d !== 'object' ) {
				return;
			}
			if ( d.kzRag === 'hello' ) {
				post( { kzRag: 'ready' } );
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

			fetch( base + d.path + ( d.query ? '?' + d.query : '' ), options )
				.then( ( r ) => r.text().then( ( text ) => {
					post( {
						kzRag: 'response',
						id: d.id,
						status: r.status,
						statusText: r.statusText,
						contentType: r.headers.get( 'Content-Type' ) || '',
						body: text
					} );
				} ) )
				.catch( ( err ) => {
					post( { kzRag: 'response', id: d.id, error: String( err && err.message || err ) } );
				} );
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
