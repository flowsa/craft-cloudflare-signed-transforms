# Cloudflare Signed Transforms Plugin for Craft CMS

Transform images and PDFs in Craft CMS using Cloudflare Workers with HMAC-signed URLs for security. This plugin is a fork of [craft-cloudflare-image-transforms](https://github.com/lenvanessen/craft-cloudflare-image-transforms) adapted to work with custom Cloudflare Workers instead of Cloudflare's native CDN transformation endpoint.

## Features

- 🖼️ **Image Transforms** - Full support for all Craft CMS image transform parameters
- 📄 **PDF Support** - Automatically generate thumbnails from PDF first pages
- 🌍 **Works with Any URL** - Not limited to Cloudflare-managed domains
- 🔒 **HMAC Security** - Signed URLs prevent unauthorized usage
- ⏰ **Optional Expiration** - Time-limited URLs for sensitive content
- ⚡ **Global CDN** - Cached at Cloudflare's edge network
- 💰 **Cost Effective** - Free tier: 5,000 image transforms + 10 hours browser rendering/month

## Prerequisites

1. **Cloudflare Worker** - Deploy the companion worker from [cloudflare-pdf-thumbnaiker](https://github.com/richardfrankza/cloudflare-pdf-thumbnaiker)
2. **Cloudflare Account** - With Browser Rendering and Images API enabled
3. **Craft CMS** - Version 4.7.0+ or 5.0.0+
4. **PHP** - Version 8.0.2 or higher

## Installation

Install via Composer:

```bash
composer require richardfrankza/craft-cloudflare-signed-transforms
php craft plugin/install cloudflare-signed-transforms
```

## Configuration

### 1. Deploy the Cloudflare Worker

Follow the setup instructions in the [worker repository](https://github.com/richardfrankza/cloudflare-pdf-thumbnaiker):

```bash
# Clone the worker repository
git clone https://github.com/richardfrankza/cloudflare-pdf-thumbnaiker.git
cd cloudflare-pdf-thumbnaiker

# Install dependencies
npm install

# Set the signature secret
npx wrangler secret put SIGNATURE_SECRET
# Enter a strong random key (generate with: node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")

# Deploy to Cloudflare
npm run deploy
```

### 2. Configure the Plugin

In Craft CMS control panel:

1. Go to **Settings → Plugins → Cloudflare Signed Transforms**
2. Enter your **Worker URL** (e.g., `https://pdf-thumbnail-worker.your-domain.workers.dev`)
3. Enter your **Signature Secret** (same secret you set in the worker)
4. Optionally set **Default Expiration** (in seconds, 0 = no expiration)

**Tip:** Use environment variables for sensitive values:

```php
// config/cloudflare-signed-transforms.php
return [
    'workerUrl' => getenv('CLOUDFLARE_WORKER_URL'),
    'signatureSecret' => getenv('CLOUDFLARE_SIGNATURE_SECRET'),
    'defaultExpiration' => 0,
];
```

## Usage

Once configured, the plugin automatically transforms all Craft CMS image transforms:

### Templates

```twig
{# Standard image transform #}
<img src="{{ asset.getUrl({ width: 400, height: 300 }) }}" alt="{{ asset.title }}">

{# Advanced transform with quality and format #}
{% set transform = {
    width: 800,
    height: 600,
    mode: 'crop',
    position: 'center-center',
    quality: 90,
    format: 'webp'
} %}
<img src="{{ asset.getUrl(transform) }}" alt="{{ asset.title }}">

{# PDF thumbnail #}
{% if asset.kind == 'pdf' %}
    <img src="{{ asset.getUrl({ width: 300, height: 400 }) }}" alt="PDF Preview">
{% endif %}
```

### GraphQL

```graphql
query {
  assets {
    url @transform(width: 400, height: 300, mode: "crop", format: "webp")
  }
}
```

## Transform Parameters

The plugin supports all standard Craft CMS transform parameters:

| Parameter | Description | Example Values |
|-----------|-------------|----------------|
| `width` | Target width in pixels | `400`, `800`, `1200` |
| `height` | Target height in pixels | `300`, `600`, `900` |
| `mode` | Resize mode | `crop`, `fit`, `stretch`, `letterbox` |
| `position` | Focal point | `center-center`, `top-left`, `bottom-right` |
| `quality` | JPEG/WebP quality (1-100) | `75`, `85`, `95` |
| `format` | Output format | `jpg`, `png`, `webp`, `avif` |
| `upscale` | Allow enlarging images | `true`, `false` |
| `fill` | Background color (letterbox mode) | `#FFFFFF`, `rgb(255,255,255)` |

### Mode Mapping

Craft CMS modes are mapped to Cloudflare Images API parameters:

- `fit` (no upscale) → `scale-down`
- `fit` (with upscale) → `contain`
- `crop` → `cover`
- `stretch` → `squeeze`
- `letterbox` → `pad`

## PDF Support

The plugin automatically detects PDF assets and generates thumbnails:

```twig
{# PDF thumbnail - renders first page #}
{% if asset.mimeType == 'application/pdf' %}
    <img src="{{ asset.getUrl({ width: 600, height: 800, quality: 90 }) }}" alt="PDF Thumbnail">
{% endif %}
```

PDFs are rendered at high resolution (1600px width by default) then transformed to your requested dimensions.

## Security

### HMAC Signatures

All URLs are signed with HMAC-SHA256:

```
signature = HMAC-SHA256(secret, url + "|" + expires + "|" + transforms)
```

Transform parameters are included in the signature, preventing URL tampering.

### Expiration

Configure default expiration for time-limited URLs:

```php
// config/cloudflare-signed-transforms.php
return [
    'defaultExpiration' => 3600, // 1 hour
];
```

## Comparison with Original Plugin

| Feature | Original Plugin | This Plugin |
|---------|----------------|-------------|
| **Domain Requirement** | Cloudflare-managed only | Any HTTPS URL |
| **URL Format** | `/cdn-cgi/image/...` | `/thumbs?url=...&signature=...` |
| **Security** | Zone-based access | HMAC-signed URLs |
| **PDF Support** | No | Yes |
| **Cache Purging** | Via Cloudflare API | Not implemented (v1) |
| **Configuration** | Zone ID + API Key | Worker URL + Secret |
| **External URLs** | No | Yes |

## Performance

**Images:**
- First request: 100-500ms (fetch + transform)
- Cached requests: <50ms (CDN edge cache)

**PDFs:**
- First request: 2-5 seconds (render + transform)
- Cached requests: <50ms (CDN edge cache)

## Cost Estimates

**Cloudflare Images:** Free tier: 5,000 transforms/month, then $0.50 per 1,000 transforms

**Browser Rendering (PDFs):** Free tier: 10 hours/month (~14,400 PDFs), then ~$0.0000075 per PDF

**Examples:**
- 5,000 images + 5,000 PDFs/month: **Free**
- 50,000 images + 10,000 PDFs/month: **~$22.54/month**
- 100,000 images + 50,000 PDFs/month: **~$47.77/month**

## Troubleshooting

### Images Not Transforming

1. Verify worker URL is correct and accessible
2. Check signature secret matches between plugin and worker
3. Ensure assets have public URLs (not local volumes)
4. Check Craft logs: `storage/logs/web.log`

### Invalid Signature Errors

- Signature secret mismatch between plugin and worker
- Transform parameters modified after URL generation
- Expired timestamp (if expiration is enabled)

### PDF Rendering Issues

- Ensure Browser Rendering API is enabled in your Cloudflare account
- Check worker logs in Cloudflare dashboard
- Verify PDF is publicly accessible via HTTPS

## Credits

This plugin is a fork of [craft-cloudflare-image-transforms](https://github.com/lenvanessen/craft-cloudflare-image-transforms) by Len van Essen. The original plugin was inspired by Pixel & Tonic's implementation for Craft Cloud.

## License

MIT License - see [LICENSE.md](LICENSE.md)

## Support

- [GitHub Issues](https://github.com/richardfrankza/craft-cloudflare-signed-transforms/issues)
- [Worker Repository](https://github.com/richardfrankza/cloudflare-pdf-thumbnaiker)
