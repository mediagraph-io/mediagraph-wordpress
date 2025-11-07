# Mediagraph WordPress Plugin - Installation Guide

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- A Mediagraph account with access to at least one organization

## Installation Steps

### 1. Download the Plugin

Download the latest version: `mediagraph-picker-v1.0.0.zip`

### 2. Install via WordPress Admin (Recommended)

1. Log into your WordPress admin panel
2. Navigate to **Plugins > Add New**
3. Click the **Upload Plugin** button at the top
4. Click **Choose File** and select `mediagraph-picker-v1.0.0.zip`
5. Click **Install Now**
6. Click **Activate Plugin**

### 3. Alternative: Manual Installation via FTP

1. Unzip `mediagraph-picker-v1.0.0.zip` on your computer
2. Connect to your WordPress site via FTP
3. Upload the `mediagraph-picker` folder to `/wp-content/plugins/`
4. Go to **Plugins** in WordPress admin
5. Find "Mediagraph File Picker" and click **Activate**

## Configuration

### Connect to Mediagraph

1. In WordPress admin, go to **Settings > Mediagraph**
2. Click the **"Connect to Mediagraph"** button
3. You'll be redirected to Mediagraph to authorize the connection
4. Log in with your Mediagraph credentials if not already logged in
5. Select your Organization (if you have access to multiple)
6. Click **Authorize**
7. You'll be redirected back to WordPress with a success message

### Switch Organizations (Optional)

If you have access to multiple Mediagraph organizations:

1. Go to **Settings > Mediagraph**
2. In the **Current Organization** section, click **Switch Organization**
3. Select a different organization from the dropdown
4. Your assets from the new organization will now be available

### Advanced Settings (Optional)

For self-hosted or custom Mediagraph instances:

1. Go to **Settings > Mediagraph**
2. Scroll to **Advanced Settings**
3. Update the **API Base URL** (default: `https://mediagraph.io`)
4. Click **Save Changes**
5. Reconnect to Mediagraph

## Usage

### Insert Media from Mediagraph

1. Create or edit a WordPress post or page
2. Click the **Add Media** button in the editor
3. Select **"Mediagraph"** from the left sidebar
4. Browse your assets:
   - Use the sidebar to navigate Collections, Storage Folders, and Lightboxes
   - Search using the search box
   - Sort by Date Uploaded, Date Taken, or Filename
   - Toggle "Show all files" to see restricted assets
5. Click an asset to select it (double-click to view details)
6. In the asset detail view:
   - Edit caption, alt text, and description (WordPress-local)
   - Choose alignment, link, and size settings
7. Click **Insert into Post**
8. The asset will be inserted at your cursor position

### View Where Assets Have Been Published

After publishing posts with Mediagraph assets:

1. Log into your Mediagraph account at https://app.mediagraph.io
2. Open any asset that has been used in a WordPress post
3. Scroll down to the **Published In** panel
4. You'll see a list of all articles where this asset has been used, including:
   - Article title and URL
   - Publisher and publication information
   - Publication date
   - Byline and abstract
   - Usage context (caption, credit, etc.)

## Troubleshooting

### Plugin Not Appearing After Upload

- Check that the ZIP file is not corrupted
- Try manual installation via FTP
- Check PHP error logs for any issues

### "Connect to Mediagraph" Button Not Working

- Ensure your WordPress site is accessible from the internet (OAuth requires redirect)
- Check for JavaScript errors in browser console
- Verify PHP version is 7.4 or higher

### Assets Not Loading

1. Go to **Settings > Mediagraph**
2. Click **Test Connection**
3. If test fails:
   - Check your internet connection
   - Verify your Mediagraph account has assets
   - Try disconnecting and reconnecting
   - Check browser console for errors

### Published Metadata Not Appearing in Mediagraph

- Ensure you clicked **Publish** (not just Save Draft)
- Check WordPress admin notices for any errors
- Verify your Mediagraph organization has the `published_assets` feature enabled
- Contact Mediagraph support if the issue persists

### Need to Disconnect

1. Go to **Settings > Mediagraph**
2. Click **Disconnect from Mediagraph**
3. Your connection will be removed (you can reconnect anytime)

## Support

For issues or questions:

- Check our Knowledge Base: https://docs.mediagraph.io
- Contact Support: support@mediagraph.io
- Open a Support Ticket in your Mediagraph account

## Updates

The plugin will notify you when updates are available. To update:

1. Go to **Plugins** in WordPress admin
2. Find "Mediagraph File Picker"
3. Click **Update Now**

Note: Updates are currently manual. Download the latest version from our knowledge base and reinstall following the steps above.

## Uninstallation

To remove the plugin:

1. Go to **Plugins** in WordPress admin
2. Find "Mediagraph File Picker"
3. Click **Deactivate**
4. Click **Delete**
5. Confirm deletion

Note: This will remove the OAuth connection but will not delete any media files you've inserted into posts.

## Version

Current Version: 1.0.0
Last Updated: January 2025

---

**© 2025 Mediagraph. All rights reserved.**
