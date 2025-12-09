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

| Setting                                | Default           | Description                                                                                                                             |
|----------------------------------------|-------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| `$wgKZChatbotLlmApiUrl`                | null              | LLM API URL                                                                                                                             |
| `$wgKZChatbotUserLimitBypassToken`     | false             | Secret token that allows bypassing the active users limit when provided as a URL parameter. Set to false to disable the bypass feature. |
| `$wgKZChatbotAutoOpenParam`            | "autoOpenChatbot" | URL parameter that triggers the chatbot to open automatically. Set to an empty string to disable this feature.                          |
| `$wgKZChatbotSendPageId`               | true              | Whether to send page_id as additional context to the RAG backend. Set to false to disable sending page context.                         |
| `$wgKZChatbotReplaceAnswerWhenNoLinks` | false             | If true and no links returned, the rag's answer is replaced by a stock one.                                                             |

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
These settings are stored in the RAG database and configured through `Special:KZChatbotRagSettings`:

| Setting         | Values                             | Description                          |
|-----------------|------------------------------------|--------------------------------------|
| Model           | Dynamic (fetched from backend)     | LLM model selection                  |
| Number of Pages | Dynamic (fetched from backend)     | Number of articles for RAG algorithm |
| Temperature     | Dynamic (fetched from backend)     | LLM creativity level ([more info]) - hidden for models that don't support it |
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
| `Special:KZChatbotRagTesting`  | Batch testing interface with retry logic and error handling |

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

## Testing Interface

The `Special:KZChatbotRagTesting` page provides a robust batch testing interface for evaluating chatbot responses across multiple queries.

### Features

#### Batch Processing
- Process multiple queries sequentially with full error recovery
- Add queries individually or paste from spreadsheet applications (TSV format)
- Support for context page specification to provide focused responses
- Real-time progress tracking with detailed status updates

#### Error Handling & Resilience
- **Automatic Retry Logic**: Failed queries are automatically retried up to 2 times
- **Intelligent Error Classification**: Only retries network errors, timeouts, and search failures
- **Continue on Error**: Single query failures don't stop the entire batch
- **Manual Retry**: Click refresh icons (🔄) next to failed queries to retry individually
- **Immediate Cancellation**: Stop processing instantly while preserving completed results

#### Configuration Options
- **Rephrase Questions**: Enable question rephrasing before processing
- **Debug Data**: Include detailed processing information in results
- **Complete Pages**: Send full page content instead of excerpts to the LLM
- **Context Pages**: Specify relevant wiki pages for context-aware responses

#### Results & Export
- **Live Results Table**: See results as they complete with full response details
- **CSV Export**: Download all results including debug data and document links
- **Document Tracking**: View both included and filtered-out source documents
- **Debug Information**: Detailed processing data for troubleshooting

### Technical Details

#### Timeout Configuration
- API requests timeout after 60 seconds (increased from 30s for complex queries)
- Automatic retry with 1-second delay between attempts
- Configurable in `ApiKZChatbotSearch.php` if different timeouts are needed

#### Error Recovery
- **Retryable Errors**: Network timeouts, connection failures, search operation failures
- **Non-Retryable Errors**: Authentication errors, malformed requests, permission denials
- **Progress Preservation**: Completed results are saved even if processing is cancelled

### Usage Tips
- Use spreadsheet applications to prepare large query sets, then paste directly
- Include context page titles for domain-specific testing
- Enable debug data when investigating response quality issues
- Use manual retry for queries that failed due to temporary issues

## Future Development
- Prevent users from sending unlimited rating requests: right now it's possible to switch indefinitely between 
  thumbs up and thumbs down, and each is sent and recorded by the RAG server. We need to decide on a limit, and save
  requests temporarily in Redis or similar to handle it
- Consider caching in memory the count of active users to avoid querying the database on every request
- What is kzchatbot_users.kzcbu_ranking_eligible_answer_id?
- Implement UUID request limit functionality

