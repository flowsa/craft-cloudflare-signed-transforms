<?php

namespace richardfrankza\cfst;

use Craft;
use craft\base\Component;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\errors\ImageTransformException;
use craft\models\ImageTransform;
use Illuminate\Support\Collection;
use yii\base\NotSupportedException;

class ImageTransformer extends Component implements ImageTransformerInterface
{
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'gif', 'png', 'avif', 'webp'];
    protected Asset $asset;

    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        $this->asset = $asset;
        $this->assertTransformable();

        $params = $this->buildTransformParams($imageTransform);
        return $this->buildSignedUrl($params);
    }

    protected function assertTransformable(): void
    {
        $mimeType = $this->asset->getMimeType();

        // PDFs are supported by the worker
        if ($mimeType === 'application/pdf') {
            return;
        }

        if ($mimeType === 'image/gif' && !Craft::$app->getConfig()->getGeneral()->transformGifs) {
            throw new NotSupportedException('GIF files shouldn\'t be transformed.');
        }

        if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
            throw new NotSupportedException('SVG files shouldn\'t be transformed.');
        }
    }

    protected function buildSignedUrl(Collection $params): string
    {
        $settings = CloudflareSignedTransforms::getInstance()->getSettings();

        if (!$settings->workerUrl || !$settings->signatureSecret) {
            throw new ImageTransformException('Worker URL and Signature Secret must be configured.');
        }

        // Get the asset's original public URL (without transforms)
        // We need to get the URL from the filesystem directly to avoid infinite loop
        $volume = $this->asset->getVolume();
        $fs = $volume->getFs();

        if (!$fs->hasUrls) {
            throw new ImageTransformException('Asset filesystem does not have public URLs.');
        }

        $assetUrl = rtrim($fs->getRootUrl(), '/') . '/' . $this->asset->getPath();

        if (!$assetUrl) {
            throw new ImageTransformException('Asset does not have a public URL.');
        }

        // Calculate expiration timestamp if configured
        $expires = null;
        if ($settings->defaultExpiration > 0) {
            $expires = time() + $settings->defaultExpiration;
        }

        // Serialize transforms alphabetically for signature
        $transformString = $this->serializeTransforms($params);

        // Generate HMAC-SHA256 signature
        $signature = $this->generateSignature(
            $settings->signatureSecret,
            $assetUrl,
            $expires,
            $transformString
        );

        // Build query parameters
        $queryParams = [
            'url' => $assetUrl,
            'signature' => $signature,
        ];

        if ($expires !== null) {
            $queryParams['expires'] = (string)$expires;
        }

        // Add all transform parameters
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $queryParams[$key] = (string)$value;
            }
        }

        // Build final URL
        $workerUrl = rtrim($settings->workerUrl, '/');
        $queryString = http_build_query($queryParams);

        return "{$workerUrl}/thumbs?{$queryString}";
    }

    /**
     * Generate HMAC-SHA256 signature
     */
    protected function generateSignature(string $secret, string $url, ?int $expires, string $transforms): string
    {
        // Build signature data: url|expires|transforms
        $data = $url;

        if ($expires !== null) {
            $data .= "|{$expires}";
        }

        if (!empty($transforms)) {
            $data .= "|{$transforms}";
        }

        return hash_hmac('sha256', $data, $secret);
    }

    /**
     * Serialize transforms to canonical string (alphabetically sorted)
     */
    protected function serializeTransforms(Collection $params): string
    {
        $parts = [];

        // Sort keys alphabetically
        $sorted = $params->sortKeys();

        foreach ($sorted as $key => $value) {
            if ($value !== null) {
                $parts[] = "{$key}={$value}";
            }
        }

        return implode('&', $parts);
    }

    /**
     * Purge cached transforms for an asset from Cloudflare's cache
     *
     * When an asset is updated or replaced, this purges the original URL from Cloudflare's cache.
     * Since transforms are generated on-demand, purging the original also invalidates all variants.
     *
     * @param Asset $asset The asset whose transforms should be invalidated
     * @return void
     */
    public function invalidateAssetTransforms(Asset $asset): void
    {
        $settings = CloudflareSignedTransforms::getInstance()->getSettings();

        if (!$settings->enableCachePurge) {
            return;
        }

        // Get the original asset URL (not a transform)
        $volume = $asset->getVolume();
        $fs = $volume->getFs();

        if (!$fs->hasUrls) {
            return;
        }

        $assetUrl = rtrim($fs->getRootUrl(), '/') . '/' . $asset->getPath();

        // Queue a job to purge this URL from Cloudflare's cache
        $job = new jobs\PurgeImageCache(['files' => [$assetUrl]]);
        Craft::$app->getQueue()->push($job);

        Craft::info("Queued cache purge for: {$assetUrl}", __METHOD__);
    }

    /**
     * Purge cache for a specific asset
     *
     * Purges both the original asset URL and all worker-transformed versions
     * by using Cloudflare's prefix-based purging.
     *
     * @param Asset $asset The asset to purge
     * @return int Number of URLs queued for purging (always 1 for single asset)
     */
    public static function purgeAssetCache(Asset $asset): int
    {
        $settings = CloudflareSignedTransforms::getInstance()->getSettings();

        if (!$settings->enableCachePurge) {
            Craft::warning('Cache purge is not enabled', __METHOD__);
            return 0;
        }

        $volume = $asset->getVolume();
        $fs = $volume->getFs();

        if (!$fs->hasUrls) {
            Craft::warning('Asset volume does not have URLs', __METHOD__);
            return 0;
        }

        // Get the asset URL
        $assetUrl = rtrim($fs->getRootUrl(), '/') . '/' . $asset->getPath();

        // Purge by prefix to catch all worker transforms for this asset
        // The worker URL pattern is: thumbs.mediaserver.co.za/thumbs?url={assetUrl}&...
        $workerUrl = rtrim($settings->workerUrl, '/');
        $encodedAssetUrl = urlencode($assetUrl);
        $workerPrefix = "{$workerUrl}/thumbs?url={$encodedAssetUrl}";

        // Queue purge job with prefix
        $job = new jobs\PurgeImageCache([
            'files' => [$assetUrl],
            'prefix' => $workerPrefix
        ]);
        Craft::$app->getQueue()->push($job);

        Craft::info("Queued asset for cache purging. Original: {$assetUrl}, Worker prefix: {$workerPrefix}", __METHOD__);

        return 1;
    }

    /**
     * Purge all cached images for a specific volume
     *
     * This collects all asset URLs from a volume and queues them for cache purging.
     * Use with caution - this can queue many jobs for large volumes.
     *
     * @param string $volumeHandle The handle of the volume to purge
     * @return int Number of assets queued for purging
     */
    public static function purgeVolumeCache(string $volumeHandle): int
    {
        $settings = CloudflareSignedTransforms::getInstance()->getSettings();

        if (!$settings->enableCachePurge) {
            Craft::warning('Cache purge is not enabled', __METHOD__);
            return 0;
        }

        // Get all assets from the volume
        $assets = Asset::find()
            ->volume($volumeHandle)
            ->all();

        if (empty($assets)) {
            Craft::warning("No assets found in volume: {$volumeHandle}", __METHOD__);
            return 0;
        }

        // Collect all asset URLs
        $urls = [];
        foreach ($assets as $asset) {
            $volume = $asset->getVolume();
            $fs = $volume->getFs();

            if ($fs->hasUrls) {
                $urls[] = rtrim($fs->getRootUrl(), '/') . '/' . $asset->getPath();
            }
        }

        if (empty($urls)) {
            Craft::warning('No URLs to purge', __METHOD__);
            return 0;
        }

        // Queue purge job (Cloudflare allows up to 30 URLs per request, so batch them)
        $batches = array_chunk($urls, 30);
        foreach ($batches as $batch) {
            $job = new jobs\PurgeImageCache(['files' => $batch]);
            Craft::$app->getQueue()->push($job);
        }

        $count = count($urls);
        Craft::info("Queued {$count} URLs for cache purging from volume: {$volumeHandle}", __METHOD__);

        return $count;
    }

    /**
     * Purge entire Cloudflare cache (nuclear option)
     *
     * WARNING: This purges EVERYTHING in your Cloudflare zone, not just images!
     * Only use this if you need to clear absolutely everything.
     *
     * @return bool Success status
     */
    public static function purgeEverything(): bool
    {
        $settings = CloudflareSignedTransforms::getInstance()->getSettings();

        if (!$settings->enableCachePurge) {
            Craft::warning('Cache purge is not enabled', __METHOD__);
            return false;
        }

        $zoneId = \craft\helpers\App::parseEnv($settings->zoneId);
        $apiKey = \craft\helpers\App::parseEnv($settings->apiKey);

        if (!$zoneId || !$apiKey) {
            Craft::error('Zone ID or API Key not configured', __METHOD__);
            return false;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post(
                "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache",
                [
                    \GuzzleHttp\RequestOptions::HEADERS => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type' => 'application/json',
                    ],
                    \GuzzleHttp\RequestOptions::JSON => [
                        'purge_everything' => true
                    ]
                ]
            );

            $success = $response->getStatusCode() === 200;

            if ($success) {
                Craft::info('Successfully purged entire Cloudflare cache', __METHOD__);
            } else {
                Craft::warning('Cloudflare purge returned status: ' . $response->getStatusCode(), __METHOD__);
            }

            return $success;
        } catch (\Exception $e) {
            Craft::error('Failed to purge Cloudflare cache: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function buildTransformParams(ImageTransform $imageTransform): Collection
    {
        $params = [
            'width' => $imageTransform->width,
            'height' => $imageTransform->height,
            'quality' => $imageTransform->quality ?: Craft::$app->getConfig()->general->defaultImageQuality,
            'format' => $this->getFormatValue($imageTransform),
            'fit' => $this->getFitValue($imageTransform),
            'background' => $this->getBackgroundValue($imageTransform),
            'gravity' => $this->getGravityValue($imageTransform),
        ];

        // Filter out null values
        return Collection::make(array_filter($params, fn($value) => $value !== null));
    }

    protected function getGravityValue(ImageTransform $imageTransform): ?string
    {
        $value = $this->getGravity($imageTransform);

        if(!$value) {
            return null;
        }

        $value = array_values($value);

        return "$value[0]x$value[1]";
    }

    protected function getGravity(ImageTransform $imageTransform): ?array
    {
        if ($this->asset->getHasFocalPoint()) {
            return $this->asset->getFocalPoint();
        }

        if ($imageTransform->position === 'center-center') {
            return null;
        }

        $parts = explode('-', $imageTransform->position);
        $yPosition = $parts[0] ?? null;
        $xPosition = $parts[1] ?? null;

        try {
            $x = match ($xPosition) {
                'left' => 0,
                'center' => 0.5,
                'right' => 1,
            };
            $y = match ($yPosition) {
                'top' => 0,
                'center' => 0.5,
                'bottom' => 1,
            };
        } catch (\UnhandledMatchError $e) {
            throw new ImageTransformException('Invalid `position` value.');
        }

        return [$x, $y];
    }

    protected function getBackgroundValue(ImageTransform $imageTransform): ?string
    {
        return $imageTransform->mode === 'letterbox'
            ? $imageTransform->fill ?? '#FFFFFF'
            : null;
    }

    protected function getFitValue(ImageTransform $imageTransform): string
    {
        return match ($imageTransform->mode) {
            'fit' => $imageTransform->upscale ? 'contain' : 'scale-down',
            'stretch' => 'squeeze',
            'crop' => 'cover',
            'letterbox' => 'pad',
            default => 'scale-down',
        };
    }

    protected function getFormatValue(ImageTransform $imageTransform): string
    {
        if ($imageTransform->format === 'jpg' && $imageTransform->interlace === 'none') {
            return 'baseline-jpeg';
        }

        return match ($imageTransform->format) {
            'jpg' => 'jpeg',
            default => $imageTransform->format ?? 'auto',
        };
    }
}
