class BatchProcessor {
	constructor() {
		this.init();
	}

	init() {
		this.queriesBody = document.querySelector( '#queries-table-body' );
		this.processButton = document.querySelector( '#batch-process' );
		this.cancelButton = document.querySelector( '#batch-cancel' );
		this.progressIndicator = document.querySelector( '#batch-progress' );
		this.downloadButton = document.querySelector( '#batch-download' );
		this.outputWrapper = document.querySelector( '#batch-output-wrapper' );
		this.resultsTableBody = document.querySelector( '#results-table-body' );
		this.queryCountElement = document.querySelector( '#query-count' );
		this.addQueryButton = document.querySelector( '#add-query' );
		this.results = [];
		this.currentRequest = null;
		this.isCancelled = false;

		// Add clear all button after the add query button
		this.clearAllButton = document.createElement( 'button' );
		this.clearAllButton.id = 'clear-all';
		this.clearAllButton.className = 'mw-ui-button mw-ui-destructive';
		this.clearAllButton.textContent = mw.msg( 'kzchatbot-testing-batch-clear-all' );
		this.addQueryButton.parentNode.insertBefore( this.clearAllButton, this.addQueryButton.nextSibling );

		this.isProcessing = false;
		this.spinnerId = 'kz-chatbot-batch-spinner';

		// Initially disable process button if no queries
		this.updateProcessButtonState();

		this.bindEvents();
	}

	updateProcessButtonState() {
		const hasQueries = Array.from( this.queriesBody.children )
			.some( ( row ) => row.querySelector( '.query-cell' ).textContent.trim() );
		this.processButton.disabled = this.isProcessing || !hasQueries;
	}

	setProcessingState( isProcessing ) {
		this.isProcessing = isProcessing;

		// Update query cells editability
		const queryCells = this.queriesBody.querySelectorAll( '.query-cell' );
		queryCells.forEach( ( cell ) => {
			cell.contentEditable = isProcessing ? 'false' : 'true';
			if ( isProcessing ) {
				cell.classList.add( 'processing' );
			} else {
				cell.classList.remove( 'processing' );
			}
		} );

		// Disable/enable buttons
		this.addQueryButton.disabled = isProcessing;
		this.clearAllButton.disabled = isProcessing;

		// Update delete buttons
		const deleteButtons = this.queriesBody.querySelectorAll( '.query-delete' );
		deleteButtons.forEach( ( button ) => {
			button.disabled = isProcessing;
		} );

		// Reset cancel button state
		this.cancelButton.disabled = false;
		this.cancelButton.style.display = isProcessing ? 'inline-block' : 'none';

		// Handle spinner and button state
		const $processButton = $( this.processButton );
		if ( isProcessing ) {
			// Insert spinner inside the button
			const $spinner = $.createSpinner( this.spinnerId );
			$processButton.prepend( $spinner );
		} else {
			$processButton.find( '.mw-spinner' ).remove();
		}

		// Update process button state based on both processing state and query presence
		this.updateProcessButtonState();
	}

	bindEvents() {
		this.processButton.addEventListener( 'click', () => this.processQueries() );
		this.cancelButton.addEventListener( 'click', () => this.cancelProcessing() );
		this.downloadButton.addEventListener( 'click', () => this.downloadResults() );
		this.addQueryButton.addEventListener( 'click', () => this.addQueryRow() );
		this.clearAllButton.addEventListener( 'click', () => this.clearAllRows() );

		// Paste event handler - bind to the table body instead of document
		this.queriesBody.addEventListener( 'paste', ( e ) => this.handlePaste( e ) );

		// Listen for changes in query cells
		this.queriesBody.addEventListener( 'input', () => {
			this.updateProcessButtonState();
		} );

		// Delete button handler
		this.queriesBody.addEventListener( 'click', ( e ) => {
			if ( e.target.matches( '.query-delete' ) ) {
				e.preventDefault();
				this.deleteQueryRow( e.target.closest( 'tr' ) );
			}
		} );
	}

	handlePaste( e ) {
		// Don't allow paste while processing
		if ( this.isProcessing ) {
			e.preventDefault();
			return;
		}

		// Only handle paste if target is our query cell
		if ( !e.target.matches( '.query-cell' ) ) {
			return;
		}

		// Prevent default paste behavior
		e.preventDefault();

		// Get text from clipboard
		const text = ( e.clipboardData || window.clipboardData ).getData( 'text' );

		// Parse CSV-like format
		const queries = this.parseCSVLikeQueries( text );

		// Replace content of current cell with first query
		const currentCell = e.target;
		currentCell.textContent = queries[ 0 ] || '';

		// Add remaining queries as new rows
		for ( let i = 1; i < queries.length; i++ ) {
			this.addQueryRow( queries[ i ] );
		}

		// Update process button state
		this.updateProcessButtonState();
	}

	parseCSVLikeQueries( text ) {
		const queries = [];
		let currentQuery = '';
		let inQuotes = false;

		// Split into array of lines while preserving line breaks
		const lines = text.split( /\r?\n/ );

		for ( let i = 0; i < lines.length; i++ ) {
			const line = lines[ i ];

			// Check if this line starts with quotes
			if ( !inQuotes && line.trim().startsWith( '"' ) ) {
				inQuotes = true;
				currentQuery = line.slice( line.indexOf( '"' ) + 1 );
				// Check if this line ends with quotes
			} else if ( inQuotes && line.trim().endsWith( '"' ) ) {
				inQuotes = false;
				// Add the line (without ending quote) and handle escaped quotes
				currentQuery += '\n' + line.slice( 0, line.lastIndexOf( '"' ) );
				// Clean up and add to queries
				queries.push( this.cleanQuery( currentQuery ) );
				currentQuery = '';
				// Inside a quoted string
			} else if ( inQuotes ) {
				currentQuery += '\n' + line;
				// Regular unquoted line
			} else {
				queries.push( this.cleanQuery( line ) );
			}
		}

		// Handle any remaining content
		if ( currentQuery ) {
			queries.push( this.cleanQuery( currentQuery ) );
		}

		return queries.filter( ( q ) => q );
	}

	cleanQuery( query ) {
		return query
			// Replace multiple spaces/tabs with a single space
			.replace( /\s+/g, ' ' )
			// Handle escaped quotes
			.replace( /""/g, '"' )
			// Remove any remaining quotes at start/end
			.trim();
	}

	clearAllRows() {
		OO.ui.confirm( mw.msg( 'kzchatbot-testing-batch-clear-all-confirm' ) ).done( ( confirmed ) => {
			if ( confirmed ) {
				while ( this.queriesBody.firstChild ) {
					this.queriesBody.firstChild.remove();
				}
				this.addQueryRow(); // Add one empty row
				this.updateProcessButtonState();
			}
		} );
	}

	addQueryRow( queryText = '' ) {
		const row = document.createElement( 'tr' );
		row.className = 'query-row';

		const number = this.queriesBody.children.length + 1;

		row.innerHTML = `
			<td class="query-number">${ number }</td>
			<td><div class="query-cell" contenteditable="true" placeholder="${ mw.msg( 'kzchatbot-testing-batch-placeholder' ) }">${ this.escapeHtml( queryText ) }</div></td>
			<td class="query-actions">
				<button type="button" class="query-delete mw-ui-button mw-ui-destructive" title="${ mw.msg( 'kzchatbot-testing-batch-delete-query' ) }">×</button>
			</td>
		`;

		this.queriesBody.appendChild( row );
		this.renumberRows();
	}

	deleteQueryRow( row ) {
		if ( this.queriesBody.children.length > 1 ) {
			row.remove();
			this.renumberRows();
		} else {
			// If it's the last row, just clear it instead of removing
			const queryCell = row.querySelector( '.query-cell' );
			if ( queryCell ) {
				queryCell.textContent = '';
			}
		}
		this.updateProcessButtonState();
	}

	renumberRows() {
		Array.from( this.queriesBody.children ).forEach( ( row, index ) => {
			row.querySelector( '.query-number' ).textContent = index + 1;
		} );
	}

	processQueries() {
		const queries = Array.from( this.queriesBody.children )
			.map( ( row ) => row.querySelector( '.query-cell' ).textContent.trim() )
			.filter( ( q ) => q );

		// Clear previous results
		this.results = [];
		this.resultsTableBody.innerHTML = '';

		this.progressIndicator.textContent = mw.msg( 'kzchatbot-testing-batch-progress-status', 0, queries.length );
		this.isCancelled = false;

		// Set processing state
		this.setProcessingState( true );

		// Update other UI elements
		this.downloadButton.style.display = 'none';
		this.outputWrapper.style.display = 'block';
		this.queryCountElement.textContent = queries.length;

		// Process queries sequentially
		return queries.reduce( ( promise, query, i ) => promise.then( () => {
			if ( this.isCancelled ) {
				this.progressIndicator.textContent = mw.msg(
					'kzchatbot-testing-batch-progress-cancelled',
					i,
					queries.length
				);
				return Promise.reject( new Error( 'cancelled' ) );
			}

			return this.processQuery( query )
				.then( ( result ) => {
					// Only update results if not cancelled
					if ( !this.isCancelled ) {
						this.results.push( Object.assign( {
							query,
							index: i + 1
						}, result ) );
						this.updateProgress( i + 1, queries.length );
						this.updateTableRow( this.results[ this.results.length - 1 ] );
					}
				} )
				.catch( ( error ) => {
					// Only update results if not cancelled and error isn't from abort
					if ( !this.isCancelled && error.message !== 'aborted' ) {
						this.results.push( {
							query,
							error: error.message,
							index: i + 1
						} );
						this.updateTableRow( this.results[ this.results.length - 1 ] );
						this.progressIndicator.textContent = mw.msg(
							'kzchatbot-testing-batch-progress-error',
							i + 1,
							queries.length
						);
					}
					return Promise.reject( error );
				} );
		} ), Promise.resolve() )
			.then( () => {
				// Reset processing state
				this.setProcessingState( false );
				if ( this.results.length > 0 ) {
					this.downloadButton.style.display = 'block';
				}
			} )
			.catch( () => {
				// Reset processing state
				this.setProcessingState( false );
				if ( this.results.length > 0 ) {
					this.downloadButton.style.display = 'block';
				}
			} );
	}

	processQuery( query ) {
		// Create new API instance for this request
		const api = new mw.Api();
		// Store the current request
		this.currentRequest = api;

		return api.post( {
			action: 'kzchatbotsearch',
			query,
			format: 'json',
			token: mw.user.tokens.get( 'csrfToken' )
		} ).then( ( response ) => {
			// Clear current request reference
			this.currentRequest = null;

			if ( response.error ) {
				throw new Error( response.error.info || mw.msg( 'kzchatbot-testing-batch-unknown-error' ) );
			}
			return response.kzchatbotsearch;
		} ).catch( ( error ) => {
			// Clear current request reference
			this.currentRequest = null;

			throw new Error(
				error.error ? error.error.info : mw.msg( 'kzchatbot-testing-batch-network-error' )
			);
		} );
	}

	cancelProcessing() {
		this.isCancelled = true;
		this.cancelButton.disabled = true;

		// Abort current request if it exists
		if ( this.currentRequest ) {
			this.currentRequest.abort();
		}

		// Reset processing state immediately when cancelled
		this.setProcessingState( false );
	}

	updateProgress( current, total ) {
		this.progressIndicator.textContent = mw.msg( 'kzchatbot-testing-batch-progress-status', current, total );
	}

	updateTableRow( result ) {
		const row = document.createElement( 'tr' );
		if ( result.error ) {
			row.classList.add( 'error-row' );
		}

		// Create the sources cell content with numbered links
		const sourcesHtml = result.docs ?
			'<ol>' +
			result.docs.map( ( doc ) => `<li><a href="${ this.escapeHtml( doc.url ) }" target="_blank">${ this.escapeHtml( doc.title ) }</a></li>`
			).join( '' ) +
			'</ol>' :
			'';

		// Get links identifier value from mw.config
		const linksIdentifier = mw.config.get( 'wgKZChatbotLinksIdentifier' );
		const additionalLinks = result[ linksIdentifier ] || '';

		row.innerHTML = `
			<td>${ result.index }</td>
			<td>${ this.escapeHtml( result.query ) }</td>
			<td>${ this.escapeHtml( result.error || result.gpt_result ) }</td>
			<td>${ sourcesHtml }</td>
			<td>${ this.escapeHtml( additionalLinks ) }</td>
			<td>${ result.error ? '' : this.escapeHtml( result.metadata.gpt_model ) }</td>
			<td>${ result.error ? '' : this.escapeHtml( result.metadata.gpt_time ) }</td>
			<td>${ result.error ? '' : this.escapeHtml( result.metadata.tokens ) }</td>
		`;

		this.resultsTableBody.appendChild( row );
	}

	downloadResults() {
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
	}

	generateCSV() {
		// This is actually set in the mustach template, not in a hook or anything
		const linksIdentifier = mw.config.get( 'wgKZChatbotLinksIdentifier' );

		const headers = [
			mw.msg( 'kzchatbot-testing-batch-header-query' ),
			mw.msg( 'kzchatbot-testing-batch-header-response' ),
			mw.msg( 'kzchatbot-testing-batch-header-documents' ),
			linksIdentifier,
			mw.msg( 'kzchatbot-testing-batch-header-model' ),
			mw.msg( 'kzchatbot-testing-batch-header-time' ),
			mw.msg( 'kzchatbot-testing-batch-header-tokens' ),
			mw.msg( 'kzchatbot-testing-batch-header-error' )
		];

		const rows = [ headers ];

		for ( const result of this.results ) {
			// Format sources as numbered list in plain text
			const sources = result.docs ?
				result.docs.map( ( doc, index ) => `${ index + 1 }. ${ doc.title } (${ doc.url })`
				).join( '\n' ) :
				'';

			const additionalLinks = result[ linksIdentifier ] || '';

			rows.push( [
				result.query,
				result.error || result.gpt_result,
				sources, // Add sources column
				additionalLinks,
				result.error ? '' : result.metadata.gpt_model,
				result.error ? '' : result.metadata.gpt_time,
				result.error ? '' : result.metadata.tokens,
				result.error || ''
			].map( ( cell ) => this.escapeCSV( cell ) ) );
		}

		return rows.join( '\n' );
	}

	escapeCSV( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		str = str.toString();
		if ( str.indexOf( '"' ) !== -1 || str.indexOf( ',' ) !== -1 ||
			str.indexOf( '\n' ) !== -1 || str.indexOf( '\r' ) !== -1 ) {
			return `"${ str.replace( /"/g, '""' ) }"`;
		}
		return str;
	}

	escapeHtml( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}
}

// Initialize when document is ready
$( () => {
	window.batchProcessor = new BatchProcessor();
} );
