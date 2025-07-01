# WordPress to Threads

A WordPress plugin that automatically posts your blog posts to Threads with smart character limit handling and URL shortening.

## Features

- **Automatic Posting**: Posts to Threads when you publish a new blog post
- **Character Limit Handling**: Automatically truncates long posts to fit Threads' 500 character limit
- **URL Shortening**: Uses Bitly to shorten URLs for long posts
- **Manual Posting**: Post existing blog posts to Threads with one click
- **OAuth Integration**: Secure authorization flow compliant with Meta's API requirements
- **Duplicate Prevention**: Tracks which posts have been shared to prevent duplicates
- **GDPR Compliance**: Includes data deletion endpoints for user privacy

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- A Meta for Developers account
- A Threads account
- A Bitly account (optional, for URL shortening)

## Installation

1. Download the plugin files
2. Upload the `WordpressToThreads` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings → WordPress to Threads to configure

## Setup Instructions

### Step 1: Create a Threads App

1. Go to [Meta for Developers](https://developers.facebook.com/)
2. Click "My Apps" and create a new app
3. Select "Other" as the app type
4. Add the Threads product to your app
5. Note your **App ID** and **App Secret**

### Step 2: Configure OAuth Redirect URLs

In your Meta app settings, add these OAuth redirect URIs:
- `https://yourdomain.com/?threads_oauth_action=redirect`
- `https://yourdomain.com/?threads_oauth_action=deauthorize`
- `https://yourdomain.com/?threads_oauth_action=data_deletion`

Replace `yourdomain.com` with your actual domain.


### Step 3: Get Bitly Access Token (Optional)

1. Go to [Bitly](https://bitly.com/) and create an account
2. Go to Settings → Developer Settings
3. Generate a new access token
4. Copy the token for use in the plugin

### Step 4: Configure the Plugin

1. In WordPress, go to **Settings → WordPress to Threads**
2. Enter your **Threads App ID** and **Threads App Secret**
3. Enter your **Bitly Access Token** (optional)
4. Click **Save Changes**
5. Click **Authorize with Threads**
6. Complete the OAuth authorization on Threads
7. You'll be redirected back with authorization complete

![Correct Credentials Location](./images/Correct%20Credentials.png)

## Usage

### Automatic Posting

Once configured, the plugin will automatically post to Threads whenever you publish a new blog post. The plugin will:

1. Take your post title and content
2. Check if it fits within Threads' 500 character limit
3. If too long, truncate the content and add a shortened URL
4. Post to your Threads account
5. Mark the post as "posted" to prevent duplicates

### Manual Posting

To post existing blog posts:

1. Go to **Settings → WordPress to Threads**
2. Scroll down to **Manual Post to Threads**
3. You'll see a table of your recent posts with their status
4. Click **Post to Threads** next to any unposted content
5. Watch for success/error messages

### Post Status Indicators

- ✅ **Posted**: The post has been shared to Threads
- 🟠 **Not posted**: The post hasn't been shared yet

## Character Limit Handling

Threads has a 500 character limit. The plugin handles this intelligently:

1. **Short posts**: Posted as-is with title and content
2. **Long posts**: Content is truncated and a Bitly link is added
3. **Very long titles**: Title is truncated if needed to fit the URL

Example of a truncated post:
```
My Amazing Blog Post Title

This is the beginning of my blog post content that gets truncated when it's too long...

https://bit.ly/abc123
```

## Settings

### Enable Auto Posting
Enable or disable automatic posting when blog posts are published.

### Threads App ID
Your App ID from Meta for Developers.

### Threads App Secret
Your App Secret from Meta for Developers (stored securely).

### Threads User ID
Your numeric Threads user ID (auto-populated during OAuth).

### OAuth Authorization
Shows authorization status and provides authorize/deauthorize buttons.

### Bitly Access Token
Your Bitly API token for URL shortening (optional).

## API Endpoints

The plugin creates these endpoints for OAuth compliance:

- `/?threads_oauth_action=redirect` - OAuth callback
- `/?threads_oauth_action=deauthorize` - Remove authorization
- `/?threads_oauth_action=data_deletion` - GDPR data deletion

## Troubleshooting

### Authorization Issues

**Problem**: "Failed to authorize" error
**Solution**: 
- Check your App ID and App Secret are correct
- Verify redirect URLs are configured in Meta app settings
- Ensure your domain matches exactly (including www/non-www)

### Posting Issues

**Problem**: Posts not appearing on Threads
**Solution**:
- Check error logs in WordPress (Tools → Site Health → Info → WordPress Constants → WP_DEBUG_LOG)
- Verify your authorization is still valid
- Try re-authorizing the plugin

### Character Limit Issues

**Problem**: Posts are getting cut off unexpectedly
**Solution**:
- The plugin accounts for URLs in the character count
- Very long titles may be truncated to fit the Bitly link
- Consider shorter, more concise post titles

## Support

For issues and feature requests, please check:
- WordPress error logs
- Plugin settings configuration
- OAuth authorization status

## Privacy & Data

The plugin stores:
- Your Threads access token (encrypted)
- Your Threads user ID
- Post metadata indicating what's been shared
- Bitly access token (if provided)

All data can be removed using the deauthorization feature or GDPR endpoints.

## Changelog

### Version 1.0.0
- Initial release
- Automatic posting to Threads
- Character limit handling
- URL shortening with Bitly
- Manual posting interface
- OAuth integration
- GDPR compliance

## License

MIT License - see LICENSE file for details