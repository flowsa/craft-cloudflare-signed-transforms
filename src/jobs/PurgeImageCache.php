<?php

namespace richardfrankza\cfst\jobs;

use Craft;
use craft\helpers\App;
use craft\queue\BaseJob;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use richardfrankza\cfst\CloudflareSignedTransforms;

/**
 * Purge Image Cache job
 *
 * Purges specific URLs from Cloudflare's cache when assets are updated/replaced
 */
class PurgeImageCache extends BaseJob
{
    /**
     * @var array List of URLs to purge from cache
     */
    public array $files = [];

    /**
     * @var array List of cache tags to purge
     */
    public array $tags = [];

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $settings = CloudflareSignedTransforms::getInstance()->getSettings();

        // Parse environment variables
        $zoneId = App::parseEnv($settings->zoneId);
        $apiKey = App::parseEnv($settings->apiKey);

        if (!$zoneId || !$apiKey) {
            Craft::warning('Cannot purge cache: Zone ID or API Key not configured', __METHOD__);
            return;
        }

        $client = new Client();

        // Build the purge request payload
        $payload = [];
        if (!empty($this->files)) {
            $payload['files'] = $this->files;
        }
        if (!empty($this->tags)) {
            $payload['tags'] = $this->tags;
        }

        if (empty($payload)) {
            Craft::warning('No files or tags specified for cache purge', __METHOD__);
            return;
        }

        // Log what we're about to purge
        Craft::info('Purging from Cloudflare: ' . json_encode($payload), __METHOD__);
        Craft::info("Using Zone ID: {$zoneId}", __METHOD__);

        try {
            $response = $client->post(
                "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache",
                [
                    RequestOptions::HEADERS => [
                        'Authorization' => "Bearer $apiKey",
                        'Content-Type' => 'application/json',
                    ],
                    RequestOptions::JSON => $payload
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = json_decode((string)$response->getBody(), true);

            // Log the full response
            Craft::info("Cloudflare API Response (Status {$statusCode}): " . json_encode($body), __METHOD__);

            if ($statusCode === 200 && isset($body['success']) && $body['success']) {
                Craft::info('Successfully purged ' . count($this->files) . ' URL(s) from Cloudflare cache', __METHOD__);
            } else {
                $errors = isset($body['errors']) ? json_encode($body['errors']) : 'Unknown error';
                Craft::warning("Cloudflare cache purge failed. Status: {$statusCode}, Errors: {$errors}", __METHOD__);
            }
        } catch (\Exception $e) {
            Craft::error('Failed to purge Cloudflare cache: ' . $e->getMessage(), __METHOD__);
            Craft::error('Exception details: ' . get_class($e) . ' - ' . $e->getTraceAsString(), __METHOD__);
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('cloudflare-signed-transforms', 'Purging {count} URL(s) from Cloudflare cache', [
            'count' => count($this->files ?? [])
        ]);
    }
}
