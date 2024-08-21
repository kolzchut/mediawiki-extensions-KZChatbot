// Check cookie
const scriptVersion = 8,
	cookieName = 'kzchatbot-uuid',
	cookie = mw.cookie.get( cookieName ),
	uuid = ( cookie !== null ) ? cookie : '',
	// Build config endpoint. MW sometimes messes up http/s, so we match the current protocol
	serverName = mw.config.get( 'wgServer' ).replace( /^(https?:)?\/\//, location.protocol + '//' ),
	scriptPath = serverName + mw.config.get( 'wgScriptPath' ),
	restPath = scriptPath + '/rest.php',
	extensionCodePath = scriptPath + '/extensions/KZChatbot/resources/ext.KZChatbot.bot',
	getConfigPath = '/kzchatbot/v0/config',
	endpoint = restPath + getConfigPath;

// Callout to config endpoint
$.get( endpoint + '?uuid=' + uuid, ( data ) => {

	// (Re-)save cookie
	mw.cookie.set( cookieName, data.uuid, { expires: new Date( data.cookieExpiry ) } );

	// Is chatbot shown to this user?
	if ( data.chatbotIsShown == '1' ) {
		// Build config data for React app
		const config = data,
			savedSettings = mw.config.get( 'KZChatbotSettings' );
		config.slugs = mw.config.get( 'KZChatbotSlugs' );
		config.restPath = restPath;

		for ( const key of Object.keys( savedSettings ) ) {
			config[ key ] = savedSettings[ key ];
		}

		window.KZChatbotConfig = config;
		document.body.insertAdjacentHTML( 'beforeend', '<div id="kzchatbot" class="kzchatbot"></div>' );
		// Launch React app
		$.ajaxSetup( {
			cache: true
		} );
		$.getScript( extensionCodePath + '/index.js?' + scriptVersion );
	}
} );
