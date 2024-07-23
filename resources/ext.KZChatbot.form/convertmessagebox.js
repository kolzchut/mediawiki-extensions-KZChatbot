/*!
 * JavaScript for Special:Preferences: Check for successbox to replace with notifications.
 */
console.log('Hi, Mom!');
( function () {
	$( function () {
		var convertmessagebox = require( 'mediawiki.notification.convertmessagebox' );
		convertmessagebox();
	} );
}() );
