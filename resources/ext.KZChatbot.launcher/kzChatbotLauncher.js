const initializeChatbot = () => {
	const scriptVersion = 18;
	const cookieName = 'kzchatbot-uuid';
	const cookie = mw.cookie.get( cookieName );
	const uuid = cookie !== null ? cookie : '';

	// Build config endpoint. MW sometimes messes up http/s, so we match the current protocol
	const serverName = mw.config.get( 'wgServer' ).replace( /^(https?:)?\/\//, `${ location.protocol }//` );
	const scriptPath = `${ serverName }${ mw.config.get( 'wgScriptPath' ) }`;
	const restPath = `${ scriptPath }/rest.php`;
	const extensionCodePath = `${ scriptPath }/extensions/KZChatbot/resources/ext.KZChatbot.bot`;
	const getConfigPath = '/kzchatbot/v0/status';

	// Get bypass token from URL if present
	const urlParams = new URLSearchParams( window.location.search );
	const bypassToken = urlParams.get( 'kzchatbot_access' );

	// If the cookie exists and its value is false, the user wasn't selected
	if ( uuid === 'false' && bypassToken === null ) {
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

		// If the user wasn't selected, we save a session cookie with an empty UUID,
		// so we won't have to do this on every page load
		if ( data.uuid === false ) {
			mw.cookie.set( cookieName, 'false', {
				expires: 0
			} );
			return;
		}

		if ( data.uuid !== uuid ) {
			// (Re-)save cookie
			mw.cookie.set( cookieName, data.uuid, {
				expires: new Date( data.cookieExpiry )
			} );
		}

		// Is chatbot shown to this user?
		if ( data.chatbotIsShown === true ) {
			// Build config data for React app
			const savedSettings = mw.config.get( 'KZChatbotSettings' );
			window.KZChatbotConfig = Object.assign( {}, data, {
				slugs: mw.config.get( 'KZChatbotSlugs' ),
				restPath
			}, savedSettings );
			document.body.insertAdjacentHTML(
				'beforeend',
				'<div id="kzchatbot" class="kzchatbot"></div>'
			);

			// Launch React app
			$.ajaxSetup( { cache: true } );
			$.getScript( `${ extensionCodePath }/index.js?${ scriptVersion }` );
		}
	} );
};

// Execute the initialization
initializeChatbot();
