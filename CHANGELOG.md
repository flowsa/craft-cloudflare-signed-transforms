# Release Notes for Cloudflare Signed Transforms

## 2.0.0 - 2024-10-14

### Major Changes

This is a complete rewrite forked from [craft-cloudflare-image-transforms](https://github.com/lenvanessen/craft-cloudflare-image-transforms) with significant architectural changes.

**Breaking Changes:**
- Renamed package from `lenvanessen/cloudflare-image-transforms` to `flowsa/craft-cloudflare-signed-transforms`
- Changed namespace from `lenvanessen\cit` to `flowsa\cfst`
- Removed `zoneId` and `apiKey` configuration (no longer needed)
- Removed cache purge functionality (not applicable to worker-based transforms)
- Changed URL format from `/cdn-cgi/image/...` to `/thumbs?url=...&signature=...`

**New Features:**
- ✨ HMAC-SHA256 signed URLs for security
- ✨ PDF support - automatic thumbnail generation from first page
- ✨ Works with any public HTTPS URL (not limited to Cloudflare-managed domains)
- ✨ Optional expiration timestamps for time-limited access
- ✨ Uses custom Cloudflare Worker instead of native CDN endpoint

**Configuration Changes:**
- Added `workerUrl` - Base URL of your Cloudflare Worker
- Added `signatureSecret` - HMAC secret key for signing URLs
- Added `defaultExpiration` - Optional default expiration time in seconds

**Architecture:**
- Completely rewritten `ImageTransformer` class with signed URL generation
- Updated `Settings` model with new configuration properties
- New settings template with updated UI and documentation
- Transform parameters now included in signature for tamper prevention

### Migration from Original Plugin

1. Deploy the [Cloudflare Worker](https://github.com/flowsa/cloudflare-pdf-thumbnaiker)
2. Set `SIGNATURE_SECRET` in your worker
3. Update plugin configuration with worker URL and secret
4. Remove old `zoneId` and `apiKey` settings

### Technical Details

- Schema version bumped to 2.0.0
- Removed jobs and exceptions related to cache purging
- Added signature generation and validation logic
- Added support for PDF MIME type detection
- Updated gravity/position mapping for better accuracy

---

## Previous Versions (Original Plugin)

### 1.0.3
- Better batching with Craft's crop strategy, using "cover" for Cloudflare

### 1.0.2
- Fixes issue with transforms using a focal point

### 1.0.1
- Add default image quality
- Cleanup

### 1.0.0
- Initial release (original plugin by Len van Essen)
