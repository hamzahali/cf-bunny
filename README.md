# Stream Manager - CF Bunny

A WordPress plugin that seamlessly integrates Cloudflare Stream (Live) with Bunny Stream (VOD) featuring a universal smart player that automatically switches between live and on-demand content.

## Overview

**Stream Manager** (v1.4.1) is a WordPress plugin designed to manage live streaming and video-on-demand workflows. It creates a unified viewing experience by automatically switching between Cloudflare Stream for live content and Bunny Stream for recorded videos.

## Features

- **Live Streaming**: Create and manage Cloudflare Stream live inputs
- **VOD Management**: Upload and manage recorded videos via Bunny Stream
- **Universal Smart Player**: Auto-detects content state and switches between live and VOD streams
- **Webhook Integration**: Automatic transfer of live recordings to Bunny Stream VOD
- **Transfer Logs**: Track all live-to-VOD transfers with detailed logging
- **Embed Support**: Easy copy/preview of universal embeds
- **Automated Cleanup**: Configurable delay for deleting Cloudflare recordings post-transfer

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- Active Cloudflare Stream account
- Active Bunny Stream account

## Installation

### From GitHub

1. Download the latest release ZIP from GitHub
2. Extract the ZIP file
3. Rename the extracted folder to `stream-manager` or `cf-bunny`
4. Upload the renamed folder to `/wp-content/plugins/`
5. Activate the plugin through the 'Plugins' menu in WordPress
6. Navigate to **Stream Manager** → **Settings** to configure your API credentials

### Manual Installation

1. Clone or download this repository
2. Upload the plugin files to `/wp-content/plugins/stream-manager/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **Stream Manager** → **Settings** to configure your API credentials

## Configuration

### Required Settings

1. **Cloudflare Credentials**
   - Customer subdomain
   - API token with Stream permissions

2. **Bunny Stream Credentials**
   - Library ID
   - API key

3. **Player Settings**
   - Player type (iframe/custom)
   - Bypass secret (optional)
   - CF delete delay (minutes)

## Usage

### Creating Live Videos

1. Go to **Stream Manager** → **Add Live Video**
2. Configure your live stream settings
3. Get your RTMP credentials
4. Use the universal embed code on your site

### Adding Recorded Videos

1. Go to **Stream Manager** → **Add Recorded Video**
2. Upload your video file to Bunny Stream
3. Configure playback settings
4. Embed on your WordPress site

### Viewing Transfer Logs

Monitor all live-to-VOD transfers in **Stream Manager** → **Transfer Logs**

## Technical Details

### Universal Player Logic

The smart player automatically:
- Displays Cloudflare Stream iframe when content is **live**
- Switches to Bunny Stream iframe when content becomes **VOD**
- Polls for status changes every 20 seconds
- Handles state transitions seamlessly

### Webhook Automation

The plugin includes a REST API webhook endpoint that:
- Receives notifications from Cloudflare when recordings are ready
- Automatically initiates transfer to Bunny Stream
- Logs all transfer activities
- Schedules cleanup of source recordings

## Database

The plugin creates a `stream_transfer_logs` table to track:
- Transfer timestamps
- Post associations
- Cloudflare UIDs
- Transfer status
- Embed URLs
- VOD URLs

## Custom Post Type

Streams are managed via the `stream_class` custom post type with full WordPress integration.

## Version

Current version: **1.4.1**

## License

Streaming Plugin Project

## Support

For issues and feature requests, please contact the plugin maintainers.
