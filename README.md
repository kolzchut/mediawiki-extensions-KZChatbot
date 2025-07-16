# Kol-Zchut Chatbot (KZChatbot)

## Overview

KZChatbot is a MediaWiki extension that provides an end-user interface for interacting with the Kol-Zchut chatbot.
It serves as middleware between the chatbot frontend and the RAG API backend.

Information on the RAG backend (MIT license) can be found here 
- [In this Hebrew LinkedIn announcement](https://www.linkedin.com/feed/update/urn:li:activity:7295403255134666753/)
- [Backend source code](https://github.com/NNLP-IL/Webiks-Hebrew-RAGbot)
- [Backend demo/Admin UI source code](https://github.com/NNLP-IL/Webiks-Hebrew-RAGbot-Demo)
- [Usage guide (in Hebrew)](https://drive.google.com/file/d/10nbRI_qA74z_sgB92tlvWh2ulTCwHBin/view)

⚠️ **Security Note**: There is no built-in authentication between the chatbot and the RAG API.
The RAG API should be protected through other means, such as IP whitelisting or internal network restrictions.

## Installation

### Basic Installation
1. Download the extension
2. Add `wfLoadExtension( 'KZChatbot' );` to `LocalSettings.php` or your custom PHP config file
3. Run `php update.php` in MediaWiki's `maintenance` directory
4. Add admins to the `chatbot-admin` group or add [permissions](#Permissions) to other groups

### Developer Installation
1. Download submodules (`kolzchut/react-app-KZChatbot.git` will be downloaded into `resources/ext.KZChatbot.react`)
2. Run `npm run build` to rebuild the React code
3. After any changes to React code, increment the version number in `resources/ext.KZChatbot.launcher/kzChatbotLauncher.js`

### Mock RAG Server for Local Testing
A simple mock RAG backend is provided for local development in `tests/mock_rag_server.php`.
#### How to Use
1. Open a terminal in the `extensions/KZChatbot/tests/` directory.
2. Start the PHP built-in server:
   ```bash
   php -S localhost:5000 mock_rag_server.php
   ```
3. In your LocalSettings.php, set the API URL to point to the mock server:
   ```php
   $wgKZChatbotLlmApiUrl = 'http://localhost:5000';
   ```
4. The extension will now use the mock server for /search and /rating endpoints, returning random answers and conversation IDs.


## Configuration

### LocalSettings Settings
These settings can be configured in `LocalSettings.php`.

⚠️ `$wgKZChatbotLlmApiUrl` is required for the extension to function.

| Setting                            | Default           | Description                                                                                                                             |
|------------------------------------|-------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `$wgKZChatbotLlmApiUrl`            | null              | LLM API URL                                                                                                                             |
| `$wgKZChatbotUserLimitBypassToken` | false             | Secret token that allows bypassing the active users limit when provided as a URL parameter. Set to false to disable the bypass feature. |
| `$wgKZChatbotAutoOpenParam`        | "autoOpenChatbot" | URL parameter that triggers the chatbot to open automatically. Set to an empty string to disable this feature.                          |


### Required Settings
- `$wgKZChatbotLlmApiUrl`: Base URL for the LLM API used by the chatbot

### Database Settings
These settings can be configured through `Special:KZChatbotSettings`:

#### User Access Settings
| Setting                 | Values  | Default | Description                                                |
|-------------------------|---------|---------|------------------------------------------------------------|
| New Users Chatbot Rate  | 0-100   | 0       | Percentage of new users who will be shown the chatbot      |
| Active Users Limit      | Integer | 0       | Maximum number of concurrent active users. 0 for no limit. |
| Active Users Limit Days | Integer | 365     | Days without activity before a user becomes inactive       |
| Cookie Expiry Days      | Integer | 365     | Client-side cookie expiration period                       |

#### Usage Limits
| Setting                   | Values  | Default | Description                                      |
|---------------------------|---------|---------|--------------------------------------------------|
| Questions Daily Limit     | Integer | -       | Maximum questions per user per day               |
| Question Character Limit  | Integer | -       | Maximum characters per question                  |
| Feedback Character Limit  | Integer | -       | Maximum characters in feedback text              |
| UUID Per-IP Request Limit | Integer | -       | (NOT IMPLEMENTED) New UUID requests limit per IP |

#### Interface Settings
| Setting              | Values | Default | Description                 |
|----------------------|--------|---------|-----------------------------|
| Usage Help URL       | URL    | -       | Link to usage documentation |
| Terms of Service URL | URL    | -       | Link to terms of service    |

### RAG Settings
These settings are stored in the RAG database and configured through `Special:KZChatbotRagTesting`:

| Setting         | Values                             | Description                          |
|-----------------|------------------------------------|--------------------------------------|
| Model           | gpt-4o, gpt-4o-mini, gpt-3.5-turbo | LLM model selection                  |
| Number of Pages | Integer                            | Number of articles for RAG algorithm |
| Temperature     | 0.1-0.9                            | LLM creativity level ([more info])   |
| System Prompt   | String                             | Base LLM prompt                      |
| User Prompt     | String                             | Additional user-specific prompt      |
| Banned Fields   | -                                  | (Currently unused)                   |

[more info]: https://platform.openai.com/docs/api-reference/chat/create#chat-create-temperature

## Access Control

### User Selection Process
The chatbot uses a controlled rollout system to manage user access:

1. First Visit:
	- New users arrive without a cookie
	- System performs random selection based on configured rate
	- Selected users receive a persistent UUID cookie and database entry
	- Non-selected users receive a 1-day rejection cookie

2. Subsequent Visits:
	- Users with rejection cookie: chatbot remains hidden
	- Users with valid UUID: chatbot loads normally
	- Users without any cookie: go through selection process again

### Active Users Limit Bypass
You can enable a bypass mechanism for the active users limit:

1. Configure in `LocalSettings.php`:
   ```php
   $wgKZChatbotLimitBypassToken = 'your_secret_token_here';
   ```
2. Use the bypass URL:
   ```
   https://example.com/wiki/any_page?kzchatbot_access=your_secret_token_here
   ```

To disable bypassing, set `$wgKZChatbotLimitBypassToken = false`

⚠️ **Security Note**: Treat the bypass token as sensitive information.

### Auto-Open Chatbot
You can trigger the chatbot to open automatically by adding the `autoOpenChatbot` parameter (the name can be configured) to the URL:
```
https://example.com/wiki/any_page?autoOpenChatbot
```


## Administrative Interface

### Special Pages
| Page                           | Description                                   |
|--------------------------------|-----------------------------------------------|
| `Special:KZChatbotSettings`    | General configuration settings                |
| `Special:KZChatbotBannedWords` | Banned words management                       |
| `Special:KZChatbotSlugs`       | Interface text configuration                  |
| `Special:KZChatbotRagSettings` | RAG backend configuration                     |
| `Special:KZChatbotRagTesting`  | Question testing and LLM parameter adjustment |

### Permissions
The `chatbot-admin` group has these default permissions:

| Permission                    | Description                 |
|-------------------------------|-----------------------------|
| `kzchatbot-edit-settings`     | Manage general settings     |
| `kzchatbot-edit-rag-settings` | Manage RAG backend settings |
| `kzchatbot-testing`           | Access testing page and API |
| `kzchatbot-no-limits`         | Bypass rate limits          |

The `chatbot-settings-viewer` group has these default permissions:

| Permission                | Description                 |
|---------------------------|-----------------------------|
| `kzchatbot-view-settings` | View the general settings   |
| `kzchatbot-view-rag-settings` | View the RAG settings   |

To grant permissions to other groups, add to `LocalSettings.php`:
```php
$wgGroupPermissions['sysop']['kzchatbot-edit-settings'] = true;
```

## Future Development
- Prevent users from sending unlimited rating requests: right now it's possible to switch indefinitely between 
  thumbs up and thumbs down, and each is sent and recorded by the RAG server. We need to decide on a limit, and save
  requests temporarily in Redis or similar to handle it
- Consider caching in memory the count of active users to avoid querying the database on every request
- What is kzchatbot_users.kzcbu_ranking_eligible_answer_id?
- Implement UUID request limit functionality

