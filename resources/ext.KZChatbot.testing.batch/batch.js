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

		// Get references to checkboxes
		this.rephraseCheckbox = document.querySelector( '#rephrase-checkbox' );
		this.includeDebugDataCheckbox = document.querySelector( '#include-debug-data-checkbox' );
		this.sendCompletePagesCheckbox = document.querySelector( '#send-complete-pages-checkbox' );

		// Create OOUI numeric input widgets with proper labels and help text
		this.maxDocsPerPageWidget = new OO.ui.NumberInputWidget( {
			value: 1,
			min: 1,
			step: 1
		} );
		const maxDocsPerPageField = new OO.ui.FieldLayout( this.maxDocsPerPageWidget, {
			label: mw.msg( 'kzchatbot-testing-batch-max-docs-per-page-label' ),
			help: mw.msg( 'kzchatbot-testing-batch-max-docs-per-page-help' ),
			align: 'top'
		} );
		document.querySelector( '#max-docs-per-page-widget' ).appendChild( maxDocsPerPageField.$element[ 0 ] );

		this.retrievalSizeWidget = new OO.ui.NumberInputWidget( {
			value: 50,
			min: 1,
			step: 1
		} );
		const retrievalSizeField = new OO.ui.FieldLayout( this.retrievalSizeWidget, {
			label: mw.msg( 'kzchatbot-testing-batch-retrieval-size-label' ),
			help: mw.msg( 'kzchatbot-testing-batch-retrieval-size-help' ),
			align: 'top'
		} );
		document.querySelector( '#retrieval-size-widget' ).appendChild( retrievalSizeField.$element[ 0 ] );

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

		// Initialize toggle states
		this.isRephrase = this.rephraseCheckbox.checked;
		this.includeDebugData = this.includeDebugDataCheckbox.checked;
		this.sendCompletePages = this.sendCompletePagesCheckbox.checked;
		this.updateResultsTableHeaders();

		this.isProcessing = false;
		this.spinnerId = 'kz-chatbot-batch-spinner';

		// Initially disable process button if no queries
		this.updateProcessButtonState();

		// Initialize the title lookup widget for the initial row
		const initialContextWidget = this.queriesBody.querySelector( '.context-page-widget' );
		if ( initialContextWidget ) {
			this.createTitleLookupWidget( initialContextWidget );
		}

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

		// Debug button handler
		this.resultsTableBody.addEventListener( 'click', ( e ) => {
			if ( e.target.matches( '.debug-btn' ) ) {
				e.preventDefault();
				const debugIndex = parseInt( e.target.dataset.debugIndex );
				this.showDebugModal( this.results[ debugIndex ] );
			}
		} );

		// Manual retry button handler
		this.resultsTableBody.addEventListener( 'click', ( e ) => {
			if ( e.target.matches( '.retry-btn' ) ) {
				e.preventDefault();
				const retryIndex = parseInt( e.target.dataset.retryIndex );
				this.retryFailedQuery( retryIndex );
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

		// Parse CSV-like format supporting 1-2 columns
		const rows = this.parseCSVLikeRows( text );

		// Replace content of current cell with first row
		const currentCell = e.target;
		const currentRow = currentCell.closest( 'tr' );
		const firstRow = rows[ 0 ] || {};
		currentCell.textContent = firstRow.query || '';

		// Set context page for current row if provided
		if ( firstRow.contextPage ) {
			const contextWidgetContainer = currentRow.querySelector( '.context-page-widget' );
			if ( contextWidgetContainer && contextWidgetContainer.titleWidget ) {
				// Use setTimeout to ensure widget is fully initialized
				setTimeout( () => {
					contextWidgetContainer.titleWidget.setValue( firstRow.contextPage );
				}, 0 );
			}
		}

		// Add remaining rows as new query rows
		for ( let i = 1; i < rows.length; i++ ) {
			this.addQueryRow( rows[ i ].query || '', rows[ i ].contextPage || '' );
		}

		// Update process button state
		this.updateProcessButtonState();
	}

	parseCSVLikeRows( text ) {
		const rows = [];
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
				// Clean up and add to rows
				rows.push( this.parseRowColumns( currentQuery ) );
				currentQuery = '';
				// Inside a quoted string
			} else if ( inQuotes ) {
				currentQuery += '\n' + line;
				// Regular unquoted line
			} else {
				rows.push( this.parseRowColumns( line ) );
			}
		}

		// Handle any remaining content
		if ( currentQuery ) {
			rows.push( this.parseRowColumns( currentQuery ) );
		}

		return rows.filter( ( row ) => row.query || row.contextPage );
	}

	parseRowColumns( line ) {
		if ( !line || !line.trim() ) {
			return { query: '', contextPage: '' };
		}

		// Don't clean the line yet, we need to preserve separators
		const trimmedLine = line.trim();

		// Split by tab first (most reliable), then by multiple spaces (4+)
		let parts;
		if ( trimmedLine.includes( '\t' ) ) {
			parts = trimmedLine.split( '\t' );
		} else if ( /\s{4,}/.test( trimmedLine ) ) {
			// Split on 4 or more consecutive spaces
			parts = trimmedLine.split( /\s{4,}/ );
		} else {
			// If no separator found, treat entire line as query
			parts = [ trimmedLine ];
		}

		const result = {
			query: ( parts[ 0 ] || '' ).trim(),
			contextPage: ( parts[ 1 ] || '' ).trim()
		};

		// Debug logging
		mw.log( 'parseRowColumns:', { line, trimmedLine, parts, result } );

		return result;
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

	createTitleLookupWidget( container, initialValue = '' ) {
		const titleWidget = new mw.widgets.TitleInputWidget( {
			placeholder: mw.msg( 'kzchatbot-testing-batch-context-page-placeholder' ),
			value: initialValue,
			suggestions: true,
			showMissing: false,
			excludeCurrentPage: false
		} );

		container.appendChild( titleWidget.$element[ 0 ] );
		// Store reference to widget on container for easy access
		container.titleWidget = titleWidget;
		return titleWidget;
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

	addQueryRow( queryText = '', contextPageTitle = '' ) {
		const row = document.createElement( 'tr' );
		row.className = 'query-row';

		const number = this.queriesBody.children.length + 1;

		row.innerHTML = `
			<td class="query-number">${ number }</td>
			<td><!--suppress HtmlUnknownAttribute (this ignores the placeholder attribute here)-->
				<div class="query-cell" contenteditable="true" placeholder="${ mw.msg( 'kzchatbot-testing-batch-placeholder' ) }">${ this.escapeHtml( queryText ) }</div></td>
			<td><div class="context-page-widget"></div></td>
			<td class="query-actions">
				<button type="button" class="query-delete mw-ui-button mw-ui-destructive" title="${ mw.msg( 'kzchatbot-testing-batch-delete-query' ) }">×</button>
			</td>
		`;

		this.queriesBody.appendChild( row );

		// Create the title lookup widget
		const contextWidgetContainer = row.querySelector( '.context-page-widget' );
		this.createTitleLookupWidget( contextWidgetContainer, contextPageTitle );

		// Set initial value if provided (with delay to ensure widget is ready)
		if ( contextPageTitle ) {
			setTimeout( () => {
				if ( contextWidgetContainer.titleWidget ) {
					contextWidgetContainer.titleWidget.setValue( contextPageTitle );
				}
			}, 0 );
		}

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
		if ( this.includeDebugData ) {
			headers.push( mw.msg( 'kzchatbot-testing-batch-header-debug' ) );
		}
		headers.forEach( ( h ) => {
			const th = document.createElement( 'th' );
			th.textContent = h;
			resultsTable.appendChild( th );
		} );
	}

	processQueries() {
		const queries = Array.from( this.queriesBody.children )
			.map( ( row ) => {
				const queryText = row.querySelector( '.query-cell' ).textContent.trim();
				const contextWidgetContainer = row.querySelector( '.context-page-widget' );
				const contextPageTitle = contextWidgetContainer && contextWidgetContainer.titleWidget ?
					contextWidgetContainer.titleWidget.getValue().trim() : '';
				return {
					query: queryText,
					contextPageTitle: contextPageTitle
				};
			} )
			.filter( ( q ) => q.query );

		// Set toggle states for this batch only
		this.isRephrase = this.rephraseCheckbox.checked;
		this.includeDebugData = this.includeDebugDataCheckbox.checked;
		this.sendCompletePages = this.sendCompletePagesCheckbox.checked;
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
		return queries.reduce( ( promise, queryObj, i ) => promise.then( () => {
			if ( this.isCancelled ) {
				this.progressIndicator.textContent = mw.msg(
					'kzchatbot-testing-batch-progress-cancelled',
					i,
					queries.length
				);
				return; // Continue to next query instead of throwing
			}

			this.isRephrase = this.rephraseCheckbox.checked;
			this.includeDebugData = this.includeDebugDataCheckbox.checked;
			this.sendCompletePages = this.sendCompletePagesCheckbox.checked;
			
			// Wrap the query processing to handle retries with progress updates
			return this.processQueryWithProgressRetry( queryObj.query, this.isRephrase, this.includeDebugData, this.sendCompletePages, queryObj.contextPageTitle, i + 1, queries.length )
				.then( ( result ) => {
					// Only update results if not cancelled
					if ( !this.isCancelled ) {
						this.results.push( Object.assign( {
							query: queryObj.query,
							contextPageTitle: queryObj.contextPageTitle,
							index: i + 1,
							rephrase: this.isRephrase
						}, result ) );
						this.updateProgress( i + 1, queries.length );
						this.updateTableRow( this.results[ this.results.length - 1 ] );
					}
				} )
				.catch( ( error ) => {
					// Only update results if not cancelled and error isn't from abort
					if ( !this.isCancelled && error.message !== 'aborted' && error.message !== 'cancelled' ) {
						this.results.push( {
							query: queryObj.query,
							contextPageTitle: queryObj.contextPageTitle,
							error: error.message,
							index: i + 1,
							rephrase: this.isRephrase
						} );
						this.updateTableRow( this.results[ this.results.length - 1 ] );
					}
					// Continue to next query instead of stopping entire batch
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

	processQuery( query, rephrase, includeDebugData, sendCompletePages, contextPageTitle = '' ) {
		return this.processQueryWithRetry( query, rephrase, includeDebugData, sendCompletePages, contextPageTitle, 2 );
	}

	processQueryWithRetry( query, rephrase, includeDebugData, sendCompletePages, contextPageTitle = '', maxRetries = 2 ) {
		const attemptQuery = ( attempt = 0 ) => {
			const api = new mw.Api();
			this.currentRequest = api;
			const params = {
				action: 'kzchatbotsearch',
				query
			};
			if ( rephrase ) {
				params.rephrase = true;
			}
			if ( includeDebugData !== undefined ) {
				// eslint-disable-next-line camelcase
				params.include_debug_data = includeDebugData;
			}
			if ( sendCompletePages !== undefined ) {
				// eslint-disable-next-line camelcase
				params.send_complete_pages_to_llm = sendCompletePages;
			}
			if ( contextPageTitle ) {
				// Get page ID from title
				const titleObj = mw.Title.newFromText( contextPageTitle );
				if ( titleObj ) {
					// eslint-disable-next-line camelcase
					params.context_page_title = contextPageTitle;
				}
			}
			// Add retrieval size parameter
			const retrievalSize = parseInt( this.retrievalSizeWidget.getValue() );
			if ( retrievalSize && retrievalSize > 0 ) {
				// eslint-disable-next-line camelcase
				params.retrieval_size = retrievalSize;
			}
			// Add max documents per page parameter
			const maxDocsPerPage = parseInt( this.maxDocsPerPageWidget.getValue() );
			if ( maxDocsPerPage && maxDocsPerPage > 0 ) {
				// eslint-disable-next-line camelcase
				params.max_documents_from_same_page = maxDocsPerPage;
			}

			// Use postWithToken() which automatically handles badtoken errors
			return api.postWithToken( 'csrf', params ).then( ( response ) => {
				this.currentRequest = null;
				if ( response.error ) {
					throw new Error( response.error.info || mw.msg( 'kzchatbot-testing-batch-unknown-error' ) );
				}
				return response.kzchatbotsearch;
			} ).catch( ( error ) => {
				this.currentRequest = null;
				const errorMessage = error.error ? error.error.info : error.message || mw.msg( 'kzchatbot-testing-batch-network-error' );
				const shouldRetry = this.shouldRetryError( errorMessage ) && attempt < maxRetries;

				if ( shouldRetry ) {
					// Wait 1 second before retrying
					return new Promise( ( resolve ) => {
						setTimeout( () => {
							resolve( attemptQuery( attempt + 1 ) );
						}, 1000 );
					} );
				} else {
					throw new Error( errorMessage );
				}
			} );
		};

		return attemptQuery();
	}

	shouldRetryError( errorMessage ) {
		// Retry for network errors, timeouts, and search failures
		// Note: badtoken errors are handled automatically by postWithToken()
		const retryableErrors = [
			'Network error',
			'Search operation failed',
			'timeout',
			'connection',
			'unreachable'
		];
		return retryableErrors.some( ( retryableError ) =>
			errorMessage.toLowerCase().includes( retryableError.toLowerCase() )
		);
	}

	cancelProcessing() {
		this.isCancelled = true;
		this.cancelButton.disabled = true;

		// Abort current request if it exists
		if ( this.currentRequest ) {
			this.currentRequest.abort();
		}

		// Update progress indicator immediately
		const currentProgress = this.progressIndicator.textContent;
		if ( currentProgress.includes( 'of' ) ) {
			const matches = currentProgress.match( /(\d+) of (\d+)/ );
			if ( matches ) {
				this.progressIndicator.textContent = mw.msg(
					'kzchatbot-testing-batch-progress-cancelled',
					matches[1],
					matches[2]
				);
			}
		}

		// Reset processing state immediately when cancelled
		this.setProcessingState( false );
		
		// Show download button if we have results
		if ( this.results.length > 0 ) {
			this.downloadButton.style.display = 'block';
		}
	}

	processQueryWithProgressRetry( query, rephrase, includeDebugData, sendCompletePages, contextPageTitle = '', queryIndex, totalQueries, maxRetries = 2 ) {
		const attemptQuery = ( attempt = 0 ) => {
			if ( this.isCancelled ) {
				return Promise.reject( new Error( 'cancelled' ) );
			}

			// Update progress indicator with retry info
			if ( attempt > 0 ) {
				this.progressIndicator.textContent = mw.msg(
					'kzchatbot-testing-batch-progress-retry',
					queryIndex,
					totalQueries,
					attempt + 1,
					maxRetries + 1
				);
			} else {
				this.updateProgress( queryIndex, totalQueries );
			}

			const api = new mw.Api();
			this.currentRequest = api;
			const params = {
				action: 'kzchatbotsearch',
				query
			};
			if ( rephrase ) {
				params.rephrase = true;
			}
			if ( includeDebugData !== undefined ) {
				// eslint-disable-next-line camelcase
				params.include_debug_data = includeDebugData;
			}
			if ( sendCompletePages !== undefined ) {
				// eslint-disable-next-line camelcase
				params.send_complete_pages_to_llm = sendCompletePages;
			}
			if ( contextPageTitle ) {
				const titleObj = mw.Title.newFromText( contextPageTitle );
				if ( titleObj ) {
					// eslint-disable-next-line camelcase
					params.context_page_title = contextPageTitle;
				}
			}
			// Add retrieval size parameter
			const retrievalSize = parseInt( this.retrievalSizeWidget.getValue() );
			if ( retrievalSize && retrievalSize > 0 ) {
				// eslint-disable-next-line camelcase
				params.retrieval_size = retrievalSize;
			}
			// Add max documents per page parameter
			const maxDocsPerPage = parseInt( this.maxDocsPerPageWidget.getValue() );
			if ( maxDocsPerPage && maxDocsPerPage > 0 ) {
				// eslint-disable-next-line camelcase
				params.max_documents_from_same_page = maxDocsPerPage;
			}

			// Use postWithToken() which automatically handles badtoken errors
			return api.postWithToken( 'csrf', params ).then( ( response ) => {
				this.currentRequest = null;
				if ( response.error ) {
					throw new Error( response.error.info || mw.msg( 'kzchatbot-testing-batch-unknown-error' ) );
				}
				return response.kzchatbotsearch;
			} ).catch( ( error ) => {
				this.currentRequest = null;
				const errorMessage = error.error ? error.error.info : error.message || mw.msg( 'kzchatbot-testing-batch-network-error' );
				const shouldRetry = this.shouldRetryError( errorMessage ) && attempt < maxRetries;

				if ( shouldRetry ) {
					// Wait 1 second before retrying
					return new Promise( ( resolve ) => {
						setTimeout( () => {
							resolve( attemptQuery( attempt + 1 ) );
						}, 1000 );
					} );
				} else {
					throw new Error( errorMessage );
				}
			} );
		};

		return attemptQuery();
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
		
		// Add retry button for errors
		let responseCell = this.escapeHtml( result.error || result.gpt_result );
		if ( result.error ) {
			const retryButton = `<button class="retry-btn mw-ui-button mw-ui-progressive mw-ui-quiet" data-retry-index="${ result.index - 1 }" title="${ mw.msg( 'kzchatbot-testing-batch-retry-button' ) }">🔄</button>`;
			responseCell += ` ${ retryButton }`;
		}
		html += `<td>${ responseCell }</td>`;
		
		if ( result.rephrase ) {
			html += `<td>${ this.escapeHtml( result.response_time || '' ) }</td>`;
			html += `<td>${ this.escapeHtml( result.rephrase_time || '' ) }</td>`;
		}
		html += `<td>${ docsHtml }</td>`;
		html += `<td>${ filteredDocsHtml }</td>`;
		if ( result.rephrase ? this.isRephrase && this.includeDebugData : this.includeDebugData ) {
			const debugData = result.debug_data || result.debugData || {};
			const hasDebugData = Object.keys( debugData ).length > 0;
			const debugIcon = hasDebugData ?
				`<button class="debug-btn" data-debug-index="${ result.index - 1 }" title="${ mw.msg( 'kzchatbot-testing-batch-debug-button-title' ) }">ℹ️</button>` :
				'<span class="no-debug">—</span>';
			html += `<td class="debug-column">${ debugIcon }</td>`;
		}
		row.innerHTML = html;

		this.resultsTableBody.appendChild( row );
	}

	retryFailedQuery( index ) {
		const result = this.results[ index ];
		if ( !result || !result.error ) {
			return;
		}

		// Find the corresponding table row
		const tableRows = this.resultsTableBody.children;
		const targetRow = Array.from( tableRows ).find( row => {
			const firstCell = row.querySelector( 'td:first-child' );
			return firstCell && parseInt( firstCell.textContent ) === result.index;
		} );

		if ( !targetRow ) {
			return;
		}

		// Update the response cell to show "Retrying..."
		const responseCell = targetRow.children[ result.rephrase ? 3 : 2 ];
		const retryButton = responseCell.querySelector( '.retry-btn' );
		if ( retryButton ) {
			retryButton.disabled = true;
			retryButton.textContent = mw.msg( 'kzchatbot-testing-batch-retrying' );
		}

		// Perform the retry
		this.processQuery( result.query, result.rephrase, this.includeDebugData, this.sendCompletePages, result.contextPageTitle )
			.then( ( newResult ) => {
				// Update the result in the array
				this.results[ index ] = Object.assign( {
					query: result.query,
					contextPageTitle: result.contextPageTitle,
					index: result.index,
					rephrase: result.rephrase
				}, newResult );

				// Update the table row
				this.updateResultAtIndex( index, this.results[ index ] );
			} )
			.catch( ( error ) => {
				// Update with new error
				this.results[ index ] = {
					query: result.query,
					contextPageTitle: result.contextPageTitle,
					error: error.message,
					index: result.index,
					rephrase: result.rephrase
				};

				// Update the table row
				this.updateResultAtIndex( index, this.results[ index ] );
			} );
	}

	updateResultAtIndex( index, newResult ) {
		// Find the corresponding table row
		const tableRows = this.resultsTableBody.children;
		const targetRow = Array.from( tableRows ).find( row => {
			const firstCell = row.querySelector( 'td:first-child' );
			return firstCell && parseInt( firstCell.textContent ) === newResult.index;
		} );

		if ( !targetRow ) {
			return;
		}

		// Remove old row and insert updated row
		const newRow = document.createElement( 'tr' );
		if ( newResult.error ) {
			newRow.classList.add( 'error-row' );
		}

		// Get documents and filtered docs using shared methods
		const docs = newResult.docs || [];
		const filteredDocs = this.getFilteredDocuments( newResult );

		// Generate HTML for document lists
		const docsHtml = this.formatDocumentList( docs, true );
		const filteredDocsHtml = this.formatDocumentList( filteredDocs, true );

		let html = `<td>${ newResult.index }</td>`;
		if ( newResult.rephrase ) {
			html += `<td>${ this.escapeHtml( newResult.original_question || '' ) }</td>`;
			html += `<td>${ this.escapeHtml( newResult.rephrased_question || '' ) }</td>`;
		} else {
			html += `<td>${ this.escapeHtml( newResult.query ) }</td>`;
		}
		
		// Add retry button for errors
		let responseCell = this.escapeHtml( newResult.error || newResult.gpt_result );
		if ( newResult.error ) {
			const retryButton = `<button class="retry-btn mw-ui-button mw-ui-progressive mw-ui-quiet" data-retry-index="${ newResult.index - 1 }" title="${ mw.msg( 'kzchatbot-testing-batch-retry-button' ) }">🔄</button>`;
			responseCell += ` ${ retryButton }`;
		}
		html += `<td>${ responseCell }</td>`;
		
		if ( newResult.rephrase ) {
			html += `<td>${ this.escapeHtml( newResult.response_time || '' ) }</td>`;
			html += `<td>${ this.escapeHtml( newResult.rephrase_time || '' ) }</td>`;
		}
		html += `<td>${ docsHtml }</td>`;
		html += `<td>${ filteredDocsHtml }</td>`;
		if ( newResult.rephrase ? this.isRephrase && this.includeDebugData : this.includeDebugData ) {
			const debugData = newResult.debug_data || newResult.debugData || {};
			const hasDebugData = Object.keys( debugData ).length > 0;
			const debugIcon = hasDebugData ?
				`<button class="debug-btn" data-debug-index="${ newResult.index - 1 }" title="${ mw.msg( 'kzchatbot-testing-batch-debug-button-title' ) }">ℹ️</button>` :
				'<span class="no-debug">—</span>';
			html += `<td class="debug-column">${ debugIcon }</td>`;
		}
		newRow.innerHTML = html;

		// Replace the old row
		targetRow.parentNode.replaceChild( newRow, targetRow );
	}

	showDebugModal( result ) {
		const debugData = result.debug_data || result.debugData || {};

		// Convert JSON to HTML with line breaks
		const jsonString = JSON.stringify( debugData, null, 2 );
		const htmlString = this.escapeHtml( jsonString ).replace( /\\n/g, '<br>' );

		// Create modal dialog using OOUI
		const debugContent = new OO.ui.Element( {
			content: [
				$( '<pre>' ).addClass( 'debug-data-content' ).html( htmlString )
			]
		} );

		const messageDialog = new OO.ui.MessageDialog();
		const windowManager = new OO.ui.WindowManager();
		$( document.body ).append( windowManager.$element );
		windowManager.addWindows( [ messageDialog ] );

		const windowInstance = windowManager.openWindow( messageDialog, {
			title: mw.msg( 'kzchatbot-testing-batch-debug-dialog-title', result.index ),
			message: debugContent.$element,
			size: 'larger',
			actions: [
				{
					label: mw.msg( 'kzchatbot-testing-batch-debug-copy-button' ),
					action: 'copy'
				},
				{
					label: mw.msg( 'kzchatbot-testing-batch-debug-close-button' ),
					action: 'close',
					flags: [ 'primary', 'safe' ]
				}
			]
		} );

		// Add click-outside-to-close behavior
		windowInstance.opened.then( () => {
			const $overlay = windowManager.$element.find( '.oo-ui-windowManager-modal' );
			$overlay.on( 'click', ( e ) => {
				if ( e.target === $overlay[ 0 ] ) {
					windowManager.closeWindow( messageDialog );
				}
			} );
		} );

		windowInstance.closed.then( ( data ) => {
			if ( data && data.action === 'copy' ) {
				// Copy debug data to clipboard
				navigator.clipboard.writeText( JSON.stringify( debugData, null, 2 ) ).catch( () => {
					// Fallback for older browsers
					const textArea = document.createElement( 'textarea' );
					textArea.value = JSON.stringify( debugData, null, 2 );
					document.body.appendChild( textArea );
					textArea.select();
					document.execCommand( 'copy' );
					document.body.removeChild( textArea );
				} );
			}
			// Clean up
			windowManager.destroy();
		} );
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
				( doc ) => !docUrls.includes( doc.url )
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
		const headers = [ mw.msg( 'kzchatbot-testing-batch-header-query' ) ];
		if ( this.isRephrase ) {
			headers.push(
				mw.msg( 'kzchatbot-testing-batch-header-original-question' ),
				mw.msg( 'kzchatbot-testing-batch-header-rephrased-question' ),
				mw.msg( 'kzchatbot-testing-batch-header-response-time' ),
				mw.msg( 'kzchatbot-testing-batch-header-rephrase-time' )
			);
		}
		headers.push(
			mw.msg( 'kzchatbot-testing-batch-header-response' ),
			mw.msg( 'kzchatbot-testing-batch-header-documents' ),
			mw.msg( 'kzchatbot-testing-batch-header-filtered-documents' )
		);
		if ( this.includeDebugData ) {
			headers.push( mw.msg( 'kzchatbot-testing-batch-header-debug' ) );
		}
		headers.push( mw.msg( 'kzchatbot-testing-batch-header-error' ) );
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
				row.push( result.query, result.original_question || '', result.rephrased_question || '', result.response_time || '', result.rephrase_time || '', result.error || result.gpt_result, docsText, filteredDocsText );
			} else {
				row.push( result.query, result.error || result.gpt_result, docsText, filteredDocsText );
			}

			if ( this.includeDebugData ) {
				const debugData = result.debug_data || result.debugData || {};
				const debugText = Object.keys( debugData ).length > 0 ? JSON.stringify( debugData, null, 2 ) : '';
				row.push( debugText );
			}

			row.push( result.error || '' );
			rows.push( row.map( ( cell ) => this.escapeCSV( cell ) ) );
		}

		return rows.join( '\n' );
	}

	escapeCSV( str ) {
		if ( str === null || str === undefined ) {
			return '';
		}
		str = str.toString();
		if ( str.includes( '"' ) || str.includes( ',' ) ||
			str.includes( '\n' ) || str.includes( '\r' ) ) {
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
