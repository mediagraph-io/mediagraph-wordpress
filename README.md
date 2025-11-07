# Mediagraph WordPress File Picker Plugin

A WordPress plugin that integrates Mediagraph's media asset management system directly into the WordPress media library. Users can browse, search, and insert media assets from Mediagraph into their WordPress posts and pages.

## Features

- **OAuth 2.0 with PKCE** - Secure authentication without requiring client secrets
- **One-Click Connection** - No OAuth app setup needed, just click "Connect to Mediagraph"
- **Browse Assets** - Navigate through Collections, Storage Folders, and Lightboxes
- **Search & Filter** - Elasticsearch-powered search with sort and filter options
- **Asset Permissions** - Visual indicators for downloadable vs. restricted assets
- **Metadata Management** - Edit WordPress-local metadata fields
- **Write-back Support** - Push publication metadata back to Mediagraph on publish
- **Platform Extensibility** - Pluggable metadata mappers for different publishing platforms (WordPress, Newspack, Blox)

## Docker Development Setup

### Prerequisites

- Docker Desktop for Mac
- Docker Compose (included with Docker Desktop)

### Quick Start

1. **Start the Docker environment:**

```bash
cd lib/wordpress-plugin
docker-compose up -d
```

This will start:
- WordPress (http://localhost:8080)
- MySQL database
- phpMyAdmin (http://localhost:8081)

2. **Complete WordPress setup:**

Visit http://localhost:8080 and follow the WordPress installation wizard:
- Site Title: `Mediagraph Test Site`
- Username: `admin`
- Password: (choose a strong password)
- Email: your@email.com

3. **Activate the plugin:**

Once WordPress is installed:
- Log into WordPress admin (http://localhost:8080/wp-admin)
- Navigate to Plugins > Installed Plugins
- Find "Mediagraph File Picker" and click "Activate"

### Development Workflow

#### Install Dependencies

```bash
# Install PHP dependencies (if needed)
docker-compose exec wordpress composer install

# Install Node dependencies for React UI
npm install
```

#### Build React Assets

```bash
# Development build with watch mode
npm run dev

# Production build
npm run build
```

#### View Logs

```bash
# All services
docker-compose logs -f

# WordPress only
docker-compose logs -f wordpress

# Database only
docker-compose logs -f db
```

#### Access Services

- **WordPress**: http://localhost:8080
- **WordPress Admin**: http://localhost:8080/wp-admin
- **phpMyAdmin**: http://localhost:8081 (user: `wordpress`, password: `wordpress`)
- **MySQL**: localhost:3306 (user: `wordpress`, password: `wordpress`, database: `wordpress`)

#### Stop Environment

```bash
# Stop containers
docker-compose stop

# Stop and remove containers
docker-compose down

# Stop, remove containers, and delete volumes (fresh start)
docker-compose down -v
```

## Plugin Structure

```
lib/wordpress-plugin/
├── README.md                          # This file
├── docker-compose.yml                 # Docker setup
├── mediagraph-picker.php              # Main plugin file
├── composer.json                      # PHP dependencies
├── package.json                       # Node dependencies
├── webpack.config.js                  # React build configuration
├── includes/                          # PHP backend classes
│   ├── class-mediagraph-api.php       # API client
│   ├── class-mediagraph-oauth.php     # OAuth handler
│   ├── class-mediagraph-metadata-mapper.php  # Base mapper
│   ├── class-mediagraph-wordpress-mapper.php # WordPress mapper
│   └── class-mediagraph-settings.php  # Admin settings
├── admin/                             # Admin interface
│   ├── js/                            # React source files
│   │   ├── src/
│   │   │   ├── MediaPicker.jsx        # Main picker component
│   │   │   ├── ContainerTree.jsx      # Collections/Folders/Lightboxes
│   │   │   ├── AssetGrid.jsx          # Asset thumbnail grid
│   │   │   ├── AssetDetail.jsx        # Asset detail modal
│   │   │   ├── SearchBar.jsx          # Search and filters
│   │   │   └── index.jsx              # Entry point
│   │   └── dist/                      # Compiled React bundles
│   ├── css/
│   │   └── mediagraph-picker.css      # Plugin styles
│   └── settings-page.php              # Settings page template
└── assets/                            # Static assets (icons, images)
```

## Configuration

### Authentication with PKCE

This plugin uses **OAuth 2.0 with PKCE (Proof Key for Code Exchange)**, which means:
- ✅ No OAuth application setup required
- ✅ No client secrets to manage
- ✅ Secure authentication for public clients
- ✅ Works out of the box

### Quick Setup (3 steps)

1. **Activate the Plugin**
   - In WordPress admin, go to **Plugins > Installed Plugins**
   - Find "Mediagraph File Picker" and click **Activate**

2. **Connect to Mediagraph**
   - Go to **Settings > Mediagraph**
   - Click the **"Connect to Mediagraph"** button
   - Log into your Mediagraph account and authorize the connection
   - Select your Organization if you have access to multiple

3. **Start Using**
   - Create or edit a post
   - Click **"Add Media"** and select **"Mediagraph"**
   - Browse and insert assets!

### Advanced Settings

For self-hosted or custom Mediagraph instances:
- **API Base URL**: Change from `https://api.mediagraph.io` to your custom URL
- **Publishing Platform**: Select WordPress, Newspack, or Blox for metadata mapping

### Platform Selection

Choose your publishing platform to configure the correct metadata mapping:
- **WordPress** (default) - Standard WordPress metadata fields
- **Newspack** - Newspack-specific fields and schema (coming soon)
- **Blox CMS** - Blox-specific fields and schema (coming soon)

## Usage

### Inserting Media

1. Create or edit a WordPress post/page
2. Click the "Add Media" button in the editor
3. Select "Mediagraph" from the left sidebar
4. Browse or search for assets:
   - Expand Collections, Storage Folders, or Lightboxes
   - Use the search box for keyword search
   - Toggle visibility to show/hide restricted files
5. Double-click an asset to view details
6. Edit metadata if needed (WordPress-local only)
7. Configure display settings (alignment, link, size)
8. Click "Insert into Post"

### Metadata Write-back

When you publish a post containing Mediagraph assets:
1. The plugin automatically captures article metadata
2. Sends structured data to Mediagraph API including:
   - Publisher information
   - Article details (title, URL, byline, etc.)
   - Published images/videos with usage context
3. View results in WordPress admin notices

## Development Commands

```bash
# Install dependencies
npm install

# Development mode (watch for changes)
npm run dev

# Production build
npm run build

# Lint code
npm run lint

# Format code
npm run format

# Run tests
npm run test

# Clean build artifacts
npm run clean
```

## API Endpoints Used

### Authentication
- `POST /oauth/authorize` - OAuth authorization
- `POST /oauth/token` - Token exchange and refresh
- `GET /api/whoami` - Validate token and get user context

### Asset Management
- `GET /api/asset_groups` - List Collections, Folders, Lightboxes
- `GET /api/assets/search` - Search and filter assets
- `GET /api/assets/:id` - Get asset details
- `GET /api/assets/:id/download` - Generate download URL

### Metadata Write-back
- `POST /api/published_assets` - Send publication metadata

## How PKCE Works

PKCE (Proof Key for Code Exchange) is an OAuth 2.0 extension designed for public clients like mobile apps and browser-based applications:

1. **WordPress generates a secret** (`code_verifier`) when you click "Connect"
2. **Creates a challenge** (`code_challenge`) from that secret using SHA256
3. **Sends challenge to Mediagraph** during authorization
4. **Mediagraph stores the challenge** temporarily
5. **WordPress sends the original secret** when exchanging the authorization code for tokens
6. **Mediagraph verifies** that the secret matches the challenge

This ensures that even if someone intercepts the authorization code, they can't use it without the secret that only your WordPress instance knows.

**Benefits:**
- ✅ No client secrets stored in your plugin files
- ✅ Each WordPress installation has unique authentication
- ✅ More secure than traditional OAuth for public clients
- ✅ Industry-standard approach (RFC 7636)

## Troubleshooting

### Plugin not appearing in WordPress

Check that the plugin is symlinked correctly:
```bash
docker-compose exec wordpress ls -la /var/www/html/wp-content/plugins/
```

### OAuth connection fails

1. Ensure Mediagraph API supports PKCE (check with Mediagraph team)
2. Check redirect URI is accessible (Settings > Mediagraph > Plugin Information)
3. Verify Mediagraph API is accessible from your server
4. Check for any firewall or proxy issues
5. Try disconnecting and reconnecting

### Assets not loading

1. Check browser console for errors
2. Test API connectivity: Settings > Mediagraph > Test Connection
3. Verify you have access to assets in Mediagraph
4. Check Docker logs: `docker-compose logs -f wordpress`

### React build errors

```bash
# Clear node_modules and reinstall
rm -rf node_modules package-lock.json
npm install

# Clear webpack cache
rm -rf admin/js/dist
npm run build
```

### Docker issues

```bash
# Restart containers
docker-compose restart

# Fresh start (deletes all data)
docker-compose down -v
docker-compose up -d

# View resource usage
docker stats
```

## Testing

### Manual Testing

1. Start Docker environment
2. Install test content in WordPress
3. Test each workflow:
   - OAuth authentication
   - Browse Collections/Folders/Lightboxes
   - Search assets
   - Insert media
   - Publish post with write-back

### Unit Tests

```bash
# PHP tests (PHPUnit)
docker-compose exec wordpress vendor/bin/phpunit

# JavaScript tests (Jest)
npm run test
```

## Production Deployment

1. Build production assets:
```bash
npm run build
```

2. Create plugin package:
```bash
cd lib/wordpress-plugin
zip -r mediagraph-picker.zip . -x "*.git*" "node_modules/*" "admin/js/src/*" "docker-compose.yml"
```

3. Install on production WordPress:
   - Upload `mediagraph-picker.zip` via Plugins > Add New > Upload
   - Or deploy to `wp-content/plugins/mediagraph-picker/`
   - Activate the plugin

4. Connect to Mediagraph:
   - Go to Settings > Mediagraph
   - Click "Connect to Mediagraph"
   - Authorize the connection
   - Done! No OAuth app setup required

**Note:** If using a self-hosted Mediagraph instance, update the API Base URL in Advanced Settings before connecting.

### Mediagraph Backend Requirements

For this plugin to work with PKCE, the Mediagraph backend needs:

1. **OAuth Application Setup** (one-time, admin task):
   - Create an OAuth application named "WordPress Plugin (Official)"
   - Client ID: `wordpress-plugin-public-client`
   - Set as public client (no client secret)
   - Enable PKCE support

2. **Doorkeeper Configuration** (in `config/initializers/doorkeeper.rb`):
   ```ruby
   Doorkeeper.configure do
     # Enable PKCE
     grant_flows %w[authorization_code]

     # Allow PKCE for public clients
     force_ssl_in_redirect_uri false # For development only

     # Custom redirect URI validation for WordPress plugin
     # Allow any HTTPS URL or localhost for development
     custom_redirect_uri_validation do |client, redirect_uri|
       if client.uid == 'wordpress-plugin-public-client'
         redirect_uri.start_with?('http://localhost', 'https://')
       else
         # Standard validation for other apps
         client.redirect_uri.split.include?(redirect_uri)
       end
     end
   end
   ```

3. **Create the OAuth Application** (in Rails console):
   ```ruby
   Doorkeeper::Application.create!(
     name: 'WordPress Plugin (Official)',
     uid: 'wordpress-plugin-public-client',
     secret: '',  # No secret for PKCE
     redirect_uri: 'urn:ietf:wg:oauth:2.0:oob',  # Will be validated by custom validator
     scopes: 'read write',
     confidential: false  # Public client
   )
   ```

## Support

For issues and questions:
- Check existing issues: https://github.com/yourusername/mediagraph/issues
- File a bug report: https://github.com/yourusername/mediagraph/issues/new
- Contact: support@mediagraph.io

## License

Copyright (c) 2025 Mediagraph. All rights reserved.
