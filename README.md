# Kol-Zchut Chatbot (KZChatbot)

## Purpose

This extension provides an end-user interface for interacting with the Kol-Zchut chatbot.
It also serves as middleware between the chatbot and the RAG API.

It provides configuration options for the usage of the chatbot and the RAG backend.

Warning: there is no authentication between the chatbot and the RAG API. The RAG API should be protected by other means,
such as IP whitelisting or a internal network.

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
`$wgKZChatbotLlmApiUrl`: (required) the base URL for the LLM API used by the chatbot.

## Special pages
| Special Page                   | Description                                                      |
|--------------------------------|------------------------------------------------------------------|
| `Special:KZChatbotSettings`    | Admins can configure the general settings for the chatbot.       |
| `Special:KZChatbotBannedWords` | Admins can configure the banned words for the chatbot.           |
| `Special:KZChatbotSlugs`       | Admins can configure the interface texts for the chatbot.        |
| `Special:KZChatbotRagSettings` | Admins can configure the RAG backend's settings for the chatbot. |
| `Special:KZChatbotRagTesting`  | Admins can ask questions directly and fiddle with LLM parameters |

## Permissions
| Permission                | Description                                                                           |
|---------------------------|---------------------------------------------------------------------------------------|
| `manage-kolzchut-chatbot` | allows users to manage the general settings the chatbot, except for the RAG settings. |
| `kzchatbot-rag-admin`     | allows users to manage the RAG backend's settings for the chatbot.                    |
| `kzchatbot-testing`       | allows access to the testing special page and API                                     |
| `kzchatbot-no-limits`	 | allows users to bypass the rate limits of the chatbot                                 |

Example:
```php
$wgGroupPermissions['sysop']['manage-kolzchut-chatbot'] = true;
```
