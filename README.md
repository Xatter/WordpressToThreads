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
4. The **Setup Wizard** will launch automatically on first activation — follow the step-by-step guide to connect your accounts

To re-run the wizard later, go to **Settings → Threads & X** and click **Run Setup Wizard**.

## Setup Instructions

The setup wizard walks you through everything with screenshots, but here's a summary of what's involved:

[▶ Watch the Meta App setup walkthrough video](https://youtu.be/-pRZlsxzGQo)

### Step 1: Create a Meta App

1. Go to [Meta for Developers](https://developers.facebook.com/)
2. Click **"My Apps"** → **"Create App"**
3. Name your app and click **Next**
4. On the **Use cases** step, check **"Access the Threads API"** and click **Next**
5. (Optional) Associate with a business
6. Click **Next** until you reach the Overview, then click **"Create App"**

### Step 2: Add Permissions and Configure Redirect URIs

1. Click **Use Cases** → **Customize**
2. For **threads_content_publish**, click **+ Add**
3. Click **Settings** in the left sidebar
4. Add these redirect URIs to the corresponding fields:
   - **Redirect Callback URLs:** `https://yourdomain.com/?threads_oauth_action=redirect`
   - **Uninstall Callback URL:** `https://yourdomain.com/?threads_oauth_action=deauthorize`
   - **Delete Callback URL:** `https://yourdomain.com/?threads_oauth_action=data_deletion`

> **Important:** For the Redirect Callback URL, you must press **Enter** after pasting so it converts into a tag with an **×** to remove it. If it stays as plain text, Meta won't save it.

Replace `yourdomain.com` with your actual domain.

### Step 3: Add Yourself as a Test User

Before you can authorize, your Threads/Instagram account must be added as a test user on your Meta App and the invite must be accepted:

1. In your Meta App, click **App Roles** in the left sidebar, then click **Roles**
2. Scroll to **Test Users** and click **Add Instagram Test Users**
3. Search for and add your Threads/Instagram account, then click **Submit**
4. Open the **Threads** app, go to **Settings → Account → Website permissions** (or check your notifications) and **accept the pending invite**

> **Important:** You must accept the invite before attempting authorization. If you skip this step, the OAuth flow will fail with a permission error.

### Step 4: Enter Credentials and Authorize

1. Copy your **Threads App ID** and **Threads App Secret** from the same Settings page
2. Enter them in the plugin (via the wizard or **Settings → Threads & X**)
3. Click **Authorize with Threads** and complete the OAuth flow

### Step 5: Optional — Connect X and Bitly

- **X (Twitter):** Enter your API Key and Secret from the [X Developer Portal](https://developer.twitter.com/en/portal/projects-and-apps), then authorize
- **Bitly:** Enter your access token from Bitly's Developer Settings for URL shortening

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