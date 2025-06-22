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
		this.addQueryButton.parentNode.insertBefore(
			this.clearAllButton, this.addQueryButton.nextSibling
		);

		// Add OOUI toggle for rephrase
		this.rephraseToggle = new OO.ui.ToggleSwitchWidget( {
			value: false
		} );
		this.rephraseToggleLabel = new OO.ui.LabelWidget( {
			label: mw.msg( 'kzchatbot-testing-batch-rephrase-toggle-label' )
		} );
		const $toggleContainer = $( '#rephrase-toggle-container' );
		$toggleContainer.append( this.rephraseToggle.$element );
		$toggleContainer.append( this.rephraseToggleLabel.$element );
		this.isRephrase = false;
		this.rephraseToggle.on( 'change', ( value ) => {
			this.isRephrase = value;
			// Do not update table headers immediately; only update on processQueries()
		} );
		this.updateResultsTableHeaders();

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
			<td><!--suppress HtmlUnknownAttribute (this ignores the placeholder attribute here)-->
				<div class="query-cell" contenteditable="true" placeholder="${ mw.msg( 'kzchatbot-testing-batch-placeholder' ) }">${ this.escapeHtml( queryText ) }</div></td>
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
			row.querySelector( '.query-number' ).textContent = String( index + 1 );
		} );
	}

	updateResultsTableHeaders() {
		const resultsTable = document.querySelector( '.batch-results-table thead tr' );
		if ( !resultsTable ) {
			return;
		}
		// Remove all headers
		while ( resultsTable.firstChild ) {
			resultsTable.removeChild( resultsTable.firstChild );
		}
		const headers = [
			mw.msg( 'kzchatbot-testing-batch-header-number' )
		];
		if ( this.isRephrase ) {
			headers.push(
				mw.msg( 'kzchatbot-testing-batch-header-original-question' ),
				mw.msg( 'kzchatbot-testing-batch-header-rephrased-question' )
			);
		} else {
			headers.push( mw.msg( 'kzchatbot-testing-batch-header-query' ) );
		}
		headers.push(
			mw.msg( 'kzchatbot-testing-batch-header-response' )
		);
		if ( this.isRephrase ) {
			headers.push(
				mw.msg( 'kzchatbot-testing-batch-header-response-time' ),
				mw.msg( 'kzchatbot-testing-batch-header-rephrase-time' )
			);
		}
		headers.push(
			mw.msg( 'kzchatbot-testing-batch-header-documents' ),
			mw.msg( 'kzchatbot-testing-batch-header-filtered-documents' )
		);
		headers.forEach( ( h ) => {
			const th = document.createElement( 'th' );
			th.textContent = h;
			resultsTable.appendChild( th );
		} );
	}

	processQueries() {
		const queries = Array.from( this.queriesBody.children )
			.map( ( row ) => row.querySelector( '.query-cell' ).textContent.trim() )
			.filter( ( q ) => q );

		// Set rephrase state for this batch only
		this.isRephrase = this.rephraseToggle.getValue();
		this.updateResultsTableHeaders();

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

			this.isRephrase = this.rephraseToggle.getValue();
			return this.processQuery( query, this.isRephrase )
				.then( ( result ) => {
					// Only update results if not cancelled
					if ( !this.isCancelled ) {
						this.results.push( Object.assign( {
							query,
							index: i + 1,
							rephrase: this.isRephrase
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
							index: i + 1,
							rephrase: this.isRephrase
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

	processQuery( query, rephrase ) {
		const api = new mw.Api();
		this.currentRequest = api;
		const params = {
			action: 'kzchatbotsearch',
			query,
			format: 'json',
			token: mw.user.tokens.get( 'csrfToken' )
		};
		if ( rephrase ) {
			params.rephrase = true;
		}
		return api.post( params ).then( ( response ) => {
			this.currentRequest = null;
			if ( response.error ) {
				throw new Error( response.error.info || mw.msg( 'kzchatbot-testing-batch-unknown-error' ) );
			}
			return response.kzchatbotsearch;
		} ).catch( ( error ) => {
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

		// Get documents and filtered docs using shared methods
		const docs = result.docs || [];
		const filteredDocs = this.getFilteredDocuments( result );

		// Generate HTML for document lists
		const docsHtml = this.formatDocumentList( docs, true );
		const filteredDocsHtml = this.formatDocumentList( filteredDocs, true );

		let html = `<td>${ result.index }</td>`;
		if ( result.rephrase ) {
			html += `<td>${ this.escapeHtml( result.original_question || '' ) }</td>`;
			html += `<td>${ this.escapeHtml( result.rephrased_question || '' ) }</td>`;
		} else {
			html += `<td>${ this.escapeHtml( result.query ) }</td>`;
		}
		html += `<td>${ this.escapeHtml( result.error || result.gpt_result ) }</td>`;
		if ( result.rephrase ) {
			html += `<td>${ this.escapeHtml( result.response_time || '' ) }</td>`;
			html += `<td>${ this.escapeHtml( result.rephrase_time || '' ) }</td>`;
		}
		html += `<td>${ docsHtml }</td>`;
		html += `<td>${ filteredDocsHtml }</td>`;
		row.innerHTML = html;

		this.resultsTableBody.appendChild( row );
	}

	/**
	 * Format a list of documents either as HTML or plain text
	 *
	 * @param {Array} docs Array of document objects with title and url properties
	 * @param {boolean} asHtml Whether to return HTML (true) or plain text (false)
	 * @return {string} Formatted document list
	 */
	formatDocumentList( docs, asHtml ) {
		if ( !docs || docs.length === 0 ) {
			return '';
		}

		if ( asHtml ) {
			return '<ol>' +
				docs.map( ( doc ) => `<li><a href="${ this.escapeHtml( doc.url ) }" target="_blank">${ this.escapeHtml( doc.title ) }</a></li>`
				).join( '' ) +
				'</ol>';
		} else {
			// Format as plain text for CSV
			return docs.map( ( doc, index ) => `${ index + 1 }. ${ doc.title } (${ doc.url })`
			).join( '\n' );
		}
	}

	/**
	 * Get documents that were filtered out by the backend
	 *
	 * @param {Object} result Result object from API
	 * @return {Array} Array of filtered document objects
	 */
	getFilteredDocuments( result ) {
		let filteredDocs = [];
		if ( result.docs && result.docs_before_filter ) {
			// Find documents in docs_before_filter that aren't in docs
			const docUrls = result.docs.map( ( doc ) => doc.url );
			filteredDocs = result.docs_before_filter.filter(
				( doc ) => docUrls.indexOf( doc.url ) === -1
			);
		}
		return filteredDocs;
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
		const headers = [
			mw.msg( 'kzchatbot-testing-batch-header-query' ),
			mw.msg( 'kzchatbot-testing-batch-header-response' ),
			mw.msg( 'kzchatbot-testing-batch-header-documents' ),
			mw.msg( 'kzchatbot-testing-batch-header-filtered-documents' ),
			mw.msg( 'kzchatbot-testing-batch-header-error' )
		];
		if ( this.isRephrase ) {
			headers.splice( 1, 0,
				mw.msg( 'kzchatbot-testing-batch-header-original-question' ),
				mw.msg( 'kzchatbot-testing-batch-header-rephrased-question' ),
				mw.msg( 'kzchatbot-testing-batch-header-response-time' ),
				mw.msg( 'kzchatbot-testing-batch-header-rephrase-time' )
			);
		}
		const rows = [ headers ];

		for ( const result of this.results ) {
			// Get documents and filtered docs using the same methods as updateTableRow
			const docs = result.docs || [];
			const filteredDocs = this.getFilteredDocuments( result );

			// Format document lists as plain text
			const docsText = this.formatDocumentList( docs, false );
			const filteredDocsText = this.formatDocumentList( filteredDocs, false );

			const row = [];
			if ( result.rephrase ) {
				row.push( result.query, result.original_question || '', result.rephrased_question || '', result.error || result.gpt_result, result.response_time || '', result.rephrase_time || '', docsText, filteredDocsText, result.error || '' );
			} else {
				row.push( result.query, result.error || result.gpt_result, docsText, filteredDocsText, result.error || '' );
			}
			rows.push( row.map( ( cell ) => this.escapeCSV( cell ) ) );
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
