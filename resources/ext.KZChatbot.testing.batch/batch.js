( function () {
	'use strict';

	function BatchProcessor() {
		this.init();
	}

	BatchProcessor.prototype.init = function () {
		this.textArea = document.querySelector( '#batch-input' );
		this.outputArea = document.querySelector( '#batch-output' );
		this.processButton = document.querySelector( '#batch-process' );
		this.cancelButton = document.querySelector( '#batch-cancel' );
		this.progressIndicator = document.querySelector( '#batch-progress' );
		this.downloadButton = document.querySelector( '#batch-download' );
		this.results = [];
		this.isCancelled = false;

		this.bindEvents();
	};

	BatchProcessor.prototype.bindEvents = function () {
		this.processButton.addEventListener( 'click', this.processQueries.bind( this ) );
		this.cancelButton.addEventListener( 'click', this.cancelProcessing.bind( this ) );
		this.downloadButton.addEventListener( 'click', this.downloadResults.bind( this ) );
	};

	BatchProcessor.prototype.processQueries = function () {
		const queries = this.textArea.value.split( '\n' ).filter( ( q ) => q.trim() );
		this.results = [];
		this.progressIndicator.textContent = mw.msg( 'kzchatbot-testing-batch-progress-status', 0, queries.length );
		this.isCancelled = false;

		// Update UI for processing state
		this.processButton.disabled = true;
		this.cancelButton.style.display = 'inline-block';
		this.downloadButton.style.display = 'none';

		// Process queries sequentially using Promise chain
		return queries.reduce( ( promise, query, i ) => promise.then( () => {
			if ( this.isCancelled ) {
				this.progressIndicator.textContent = mw.msg( 'kzchatbot-testing-batch-progress-cancelled', i, queries.length );
				return Promise.reject( new Error( 'cancelled' ) );
			}

			return this.processQuery( query )
				.then( ( result ) => {
					this.results.push( Object.assign( {
						query: query
					}, result ) );
					this.updateProgress( i + 1, queries.length );
					this.updateOutput();
				} )
				.catch( ( error ) => {
					this.results.push( {
						query: query,
						error: error.message
					} );
					this.updateOutput();
					this.progressIndicator.textContent = mw.msg( 'kzchatbot-testing-batch-progress-error', i + 1, queries.length );
					return Promise.reject( error );
				} );
		} ), Promise.resolve() )
			.then( () => {
				// Reset UI after successful completion
				this.processButton.disabled = false;
				this.cancelButton.style.display = 'none';
				if ( this.results.length > 0 ) {
					this.downloadButton.style.display = 'block';
				}
			} )
			.catch( ( error ) => {
				if ( error.message !== 'cancelled' ) {
					mw.log.error( error );
				}
				// Reset UI after error/cancellation
				this.processButton.disabled = false;
				this.cancelButton.style.display = 'none';
				if ( this.results.length > 0 ) {
					this.downloadButton.style.display = 'block';
				}
			} );
	};

	BatchProcessor.prototype.cancelProcessing = function () {
		this.isCancelled = true;
		this.cancelButton.disabled = true;
	};

	BatchProcessor.prototype.processQuery = function ( query ) {
		return new Promise( ( resolve, reject ) => {
			new mw.Api().post( {
				action: 'kzchatbotsearch',
				query: query,
				format: 'json',
				token: mw.user.tokens.get( 'csrfToken' )
			} ).then( ( response ) => {
				if ( response.error ) {
					reject( new Error( response.error.info || mw.msg( 'kzchatbot-testing-batch-unknown-error' ) ) );
				} else {
					resolve( response.kzchatbotsearch );
				}
			} ).catch( ( error ) => {
				reject( new Error( error.error && error.error.info ? error.error.info :
					mw.msg( 'kzchatbot-testing-batch-network-error' ) ) );
			} );
		} );
	};

	BatchProcessor.prototype.updateProgress = function ( current, total ) {
		this.progressIndicator.textContent = mw.msg( 'kzchatbot-testing-batch-progress-status', current, total );
	};

	BatchProcessor.prototype.updateOutput = function () {
		const output = [];

		for ( const result of this.results ) {
			if ( result.error ) {
				output.push( mw.msg( 'kzchatbot-testing-batch-result-error',
					result.query,
					result.error
				) );
			} else {
				output.push( mw.msg( 'kzchatbot-testing-batch-result-success',
					result.query,
					result.gpt_result,
					result.metadata.gpt_model,
					result.metadata.gpt_time,
					result.metadata.tokens
				) );
			}
		}

		this.outputArea.value = output.join( '\n' + mw.msg( 'kzchatbot-testing-batch-result-separator' ) + '\n' );
	};

	BatchProcessor.prototype.downloadResults = function () {
		const csv = this.generateCSV();
		const blob = new Blob( [ '\ufeff' + csv ], { type: 'text/csv;charset=utf-8;' } );
		const link = document.createElement( 'a' );
		const filename = mw.msg( 'kzchatbot-testing-batch-download-filename' );

		link.href = URL.createObjectURL( blob );
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		URL.revokeObjectURL( link.href );
	};

	BatchProcessor.prototype.generateCSV = function () {
		const headers = [
			mw.msg( 'kzchatbot-testing-batch-csv-header-query' ),
			mw.msg( 'kzchatbot-testing-batch-csv-header-response' ),
			mw.msg( 'kzchatbot-testing-batch-csv-header-model' ),
			mw.msg( 'kzchatbot-testing-batch-csv-header-time' ),
			mw.msg( 'kzchatbot-testing-batch-csv-header-tokens' ),
			mw.msg( 'kzchatbot-testing-batch-csv-header-error' )
		];

		const rows = [ headers ];

		for ( const result of this.results ) {
			rows.push( [
				result.query,
				result.error || result.gpt_result,
				result.error ? '' : result.metadata.gpt_model,
				result.error ? '' : result.metadata.gpt_time,
				result.error ? '' : result.metadata.tokens,
				result.error || ''
			].map( ( cell ) => this.escapeCSV( cell ) ) );
		}

		return rows.join( '\n' );
	};

	BatchProcessor.prototype.escapeCSV = function ( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		str = str.toString();
		if ( str.indexOf( '"' ) !== -1 || str.indexOf( ',' ) !== -1 ||
			str.indexOf( '\n' ) !== -1 || str.indexOf( '\r' ) !== -1 ) {
			return '"' + str.replace( /"/g, '""' ) + '"';
		}
		return str;
	};

	// Initialize when document is ready
	$( () => {
		window.batchProcessor = new BatchProcessor();
	} );

}() );
