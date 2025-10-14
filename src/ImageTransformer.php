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
            throw new NotSupportedException('GIF files shouldn't be transformed.');
        }

        if ($mimeType === 'image/svg+xml' && !Craft::$app->getConfig()->getGeneral()->transformSvgs) {
            throw new NotSupportedException('SVG files shouldn't be transformed.');
        }
    }

    protected function buildSignedUrl(Collection $params): string
    {
        $settings = CloudflareSignedTransforms::getInstance()->getSettings();

        if (!$settings->workerUrl || !$settings->signatureSecret) {
            throw new ImageTransformException('Worker URL and Signature Secret must be configured.');
        }

        // Get the asset's public URL
        $assetUrl = $this->asset->getUrl();
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
     * Cache invalidation - not implemented for worker-based transforms
     */
    public function invalidateAssetTransforms(Asset $asset): void
    {
        // Worker uses Cloudflare Cache API which doesn't support individual purging
        // Future enhancement: add /purge endpoint to worker
    }

    public function buildTransformParams(ImageTransform $imageTransform): Collection
    {
        return Collection::make([
            'width' => $imageTransform->width,
            'height' => $imageTransform->height,
            'quality' => $imageTransform->quality ?: Craft::$app->getConfig()->general->defaultImageQuality,
            'format' => $this->getFormatValue($imageTransform),
            'fit' => $this->getFitValue($imageTransform),
            'background' => $this->getBackgroundValue($imageTransform),
            'gravity' => $this->getGravityValue($imageTransform),
        ])->whereNotNull();
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
