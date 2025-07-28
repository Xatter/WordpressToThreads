# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress plugin called "Threads Auto Poster" that automatically posts blog posts to Meta's Threads platform. The plugin handles character limits, URL shortening, OAuth authentication, and provides both automatic and manual posting capabilities.

## Architecture

### Core Components

**Main Plugin File** (`threads-auto-poster.php`):
- Single-file WordPress plugin architecture using singleton pattern
- Main class: `ThreadsAutoPoster` handles all functionality
- WordPress hooks integration for post publishing, admin interface, and AJAX handlers

**Key Features**:
- OAuth 2.0 flow with Meta's Threads API
- Character limit handling (500 chars) with intelligent truncation
- Bitly integration for URL shortening
- GDPR compliance with data deletion endpoints
- Duplicate post prevention using post meta

### API Integration Points

**Threads API Endpoints**:
- `https://graph.threads.net/v1.0/{user_id}/threads` - Create post container
- `https://graph.threads.net/v1.0/{user_id}/threads_publish` - Publish container
- `https://graph.threads.net/oauth/access_token` - Token exchange
- `https://graph.threads.net/v1.0/me` - User data retrieval

**Bitly API**:
- `https://api-ssl.bitly.com/v4/shorten` - URL shortening

### Data Flow

1. **Automatic Posting**: `publish_post` hook → `auto_post_to_threads()` → `post_to_threads()` → API calls
2. **Manual Posting**: Admin interface → AJAX → `handle_manual_post()` → `post_to_threads()`
3. **OAuth Flow**: Meta redirect → `handle_oauth_redirect()` → token exchange → user data fetch

### WordPress Integration

**Options Stored**:
- `threads_app_id`, `threads_app_secret` - Meta app credentials
- `threads_access_token`, `threads_user_id` - OAuth tokens
- `bitly_access_token` - Bitly API token
- `threads_auto_post_enabled` - Feature toggle

**Post Meta Used**:
- `_threads_posted` - Tracks if post was shared (prevents duplicates)
- `_threads_post_id` - Stores Threads post ID for reference

**Admin Interface**: Settings page at Settings → Threads Auto Poster with OAuth flow and manual posting table

## Development Notes

### File Structure
- `threads-auto-poster.php` - Main plugin file (650+ lines)
- `admin.js` - jQuery for manual posting interface
- `README.md` - Comprehensive setup and usage documentation

### Key Methods
- `post_to_threads()` - Core posting logic
- `prepare_post_content()` - Character limit handling and truncation
- `create_threads_container()` / `publish_threads_container()` - Threads API workflow
- OAuth methods: `get_authorize_url()`, `handle_oauth_redirect()`, `exchange_code_for_token()`

### Security Considerations
- Nonce verification for AJAX requests
- Capability checks (`manage_options`)
- Input sanitization with `sanitize_text_field()`
- Signed request validation for data deletion
- Secure token storage in WordPress options

### Error Handling
- Extensive error logging throughout API calls
- WordPress error handling for HTTP requests
- User-friendly error messages in admin interface
- Graceful degradation when Bitly token unavailable

## Deployment Notes

- You can check the production logs with `docker service logs extroverteddeveloper`

## Development Environment

- This project uses Docker for php