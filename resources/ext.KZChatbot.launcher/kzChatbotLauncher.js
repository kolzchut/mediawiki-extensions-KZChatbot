mw.loader.using( [ 'mediawiki.cookie' ], function () {

	// Check cookie
	const cookieName = 'kzchatbot-uuid';
	const cookie = mw.cookie.get( cookieName );
	const uuid = ( cookie !== null ) ? cookie : '';

	// Build config endpoint
	const serverName = mw.config.get( 'wgServer' ).replace( /^(https?:)?\/\//, '' );
	const restPath = serverName + mw.config.get( 'wgScriptPath' ) + '/rest.php';
	const getConfigPath = '/kzchatbot/v0/config';
	const endpoint = restPath + getConfigPath;

	// Callout to config endpoint
	$.get( endpoint + '?uuid=' + uuid, function ( data ) {

		// (Re-)save cookie
		mw.cookie.set( cookieName, data.uuid, { expires: new Date( data.cookieExpiry ) } );

		// Is chatbot shown to this user?
		if ( data.chatbotIsShown == "1" ) {

			// Build config data for React app
			const config = data;
			config.slugs = mw.config.get('KZChatbotSlugs');
			config.restPath = restPath;
			window.KZChatbotConfig = config;
			document.body.insertAdjacentHTML('beforeend', '<div id="root"></div>');

			// Launch React app
			mw.loader.using( [ 'ext.KZChatbot.react' ], function () {

				// React is loaded and ready.
				
			} );
		}
	} );
} );
