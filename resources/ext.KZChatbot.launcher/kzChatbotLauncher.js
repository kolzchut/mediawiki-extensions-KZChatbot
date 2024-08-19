// Check cookie
const scriptVersion = 3;
const cookieName = 'kzchatbot-uuid';
const cookie = mw.cookie.get( cookieName );
const uuid = ( cookie !== null ) ? cookie : '';

// Build config endpoint. MW sometimes messes up http/s, so we match the current protocol
const serverName = mw.config.get( 'wgServer' ).replace( /^(https?:)?\/\//, location.protocol + '//' );
const scriptPath = serverName + mw.config.get( 'wgScriptPath' );
const restPath = scriptPath + '/rest.php';
const extensionCodePath = scriptPath + '/extensions/KZChatbot/resources/ext.KZChatbot.bot';
const getConfigPath = '/kzchatbot/v0/config';
const endpoint = restPath + getConfigPath;

// Callout to config endpoint
$.get( endpoint + '?uuid=' + uuid, function ( data ) {

	// (Re-)save cookie
	mw.cookie.set( cookieName, data.uuid, { expires: new Date( data.cookieExpiry ) } );

	// Is chatbot shown to this user?
	if ( data.chatbotIsShown == "1" ) {
		// Build config data for React app
		const config = data,
			savedSettings = mw.config.get( 'KZChatbotSettings' );
		config.slugs = mw.config.get('KZChatbotSlugs');
		config.restPath = restPath;

		for (const [key, value] of Object.entries( savedSettings ) ) {
			config[key] = value;
		}

		window.KZChatbotConfig = config;
		document.body.insertAdjacentHTML('beforeend', '<div id="kzchatbot" class="kzchatbot"></div>');
		// Launch React app
		$.ajaxSetup({
			cache: true
		});
		$.getScript( extensionCodePath + '/index.js?' + scriptVersion );
	}
} );
