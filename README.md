# Kol-Zchut Chatbot (KZChatbot)

## Purpose

This extension provides an end-user interface for interacting with the Kol-Zchut chatbot,
via user-entered questions and user feedback on the answers given, from a modal within the
Mediawiki user interface. Although the users' questions and feedback are ultimately
processed by a separate service, this extension provides considerable configuration,
user and usage tracking, and access and usage controls with a management interface in
Mediawiki for administration by permitted wiki admins of the chatbot user experience.

## Installation

1. Download the extension
2. Add `wfLoadExtension( 'KZChatbot' );` to `LocalSettings.php` or your custom PHP config file.
3. Run `php update.php` in MediaWiki's `maintenance` directory to update the database
4. Add the `manage-kolzchut-chatbot` right to the desired group(s) in `$wgGroupPermissions`.

### Developer installation
1. Download submodules (`kolzchut/react-app-KZChatbot.git` will be downloaded into `resources/ext.KZChatbot.react`)
2. To rebuild the React code, run `npm run build`
3. If there was any change in the React code, increase by one the version number in
   `resources/ext.KZChatbot.launcher/kzChatbotLauncher.js`.

## Configuration

Optionally add `$wgKZChatbot...` to `LocalSettings.php` @TODO

```php
$wgKZChatbot... = [
	// @TODO
];
```

## Access Permissions

Access to the management interface is governed by the `manage-kolzchut-chatbot` permission.

```php
$wgGroupPermissions['sysop']['manage-kolzchut-chatbot'] = true;
```
