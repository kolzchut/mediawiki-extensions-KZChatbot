const initializeChatbot = () => {
	const scriptVersion = 28;
	const cookieName = 'kzchatbot-uuid';
	const cookie = mw.cookie.get( cookieName );
	const uuid = cookie !== null ? cookie : '';

	const setExclusionCookie = () => {
		// Now + 1 day
		const expiryDate = new Date();
		expiryDate.setDate( expiryDate.getDate() + 1 );

		mw.cookie.set( cookieName, 'none', {
			expires: expiryDate,
			sameSite: 'Strict'
		} );
	};

	// Build config endpoint. MW sometimes messes up http/s, so we match the current protocol
	const serverName = mw.config.get( 'wgServer' ).replace( /^(https?:)?\/\//, `${ location.protocol }//` );
	const scriptPath = `${ serverName }${ mw.config.get( 'wgScriptPath' ) }`;
	const restPath = `${ scriptPath }/rest.php`;
	const extensionCodePath = `${ scriptPath }/extensions/KZChatbot/resources/ext.KZChatbot.bot`;
	const getConfigPath = '/kzchatbot/v0/status';
	const autoOpenParamName = String( mw.config.get( 'KZChatbotAutoOpenParam' ) );

	// Get bypass token from URL if present
	const urlParams = new URLSearchParams( window.location.search );
	const bypassToken = urlParams.get( 'kzchatbot_access' );
	const autoOpen = urlParams.has( autoOpenParamName );

	// 2024-12-04 for b/c, we delete the session cookie (identified by the value 'false') for users
	// excluded in an older version of the code, so they get a second chance.
	// This can be removed in a few months.
	if ( uuid === 'false' ) {
		mw.cookie.set( cookieName, null );
	}

	// Don't proceed if the user has been marked as excluded. This saves on repeated calls
	if ( uuid === 'none' && bypassToken === null ) {
		return;
	}

	// Build endpoint with all parameters
	const endpoint = ( () => {
		let base = `${ restPath }${ getConfigPath }?uuid=${ uuid }`;
		if ( bypassToken ) {
			base += `&kzchatbot_access=${ encodeURIComponent( bypassToken ) }`;
		}
		return base;
	} )();

	$.get( endpoint, ( data ) => {
		if ( !data ) {
			return;
		}

		// If the user wasn't selected previously , set a temporary cookie with 'none'
		if ( data.uuid === false ) {
			setExclusionCookie();
			return;
		}

		if ( data.uuid !== uuid ) {
			// (Re-)save cookie with the expiration from the server
			mw.cookie.set( cookieName, data.uuid, {
				expires: new Date( data.cookieExpiry ),
				sameSite: 'Strict'
			} );
		}

		// Is chatbot shown to this user?
		if ( data.chatbotIsShown === true ) {
			// Build config data for React app
			const savedSettings = mw.config.get( 'KZChatbotSettings' );
			window.KZChatbotConfig = Object.assign( {}, data, {
				slugs: mw.config.get( 'KZChatbotSlugs' ),
				referrer: mw.config.get( 'wgArticleId' ),
				restPath,
				autoOpen
			}, savedSettings );
			document.body.insertAdjacentHTML(
				'beforeend',
				'<div id="kzchatbot" class="kzchatbot"></div>'
			);

			// Launch React app
			$.ajaxSetup( { cache: true } );
			$.getScript( `${ extensionCodePath }/index.js?${ scriptVersion }` );
			mw.loader.load( 'ext.KZChatbot.bot.styles' );
		}
	} );
};

// Execute the initialization
initializeChatbot();

