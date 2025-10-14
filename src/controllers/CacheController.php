<?php

namespace richardfrankza\cfst\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use richardfrankza\cfst\ImageTransformer;
use yii\web\Response;

/**
 * Cache Controller
 *
 * Handles manual cache purging from the control panel
 */
class CacheController extends Controller
{
    /**
     * Display cache purge utility page
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        return $this->renderTemplate('cloudflare-signed-transforms/cache-purge');
    }

    /**
     * Purge cache for a specific volume
     *
     * @return Response|null
     */
    public function actionPurgeVolume(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $volumeHandle = $this->request->getBodyParam('volumeHandle');

        Craft::info('actionPurgeVolume called with volume: ' . $volumeHandle, __METHOD__);

        if (!$volumeHandle) {
            Craft::$app->getSession()->setFlash('error', 'Volume handle is required');
            return $this->redirect('cloudflare-signed-transforms/cache/index');
        }

        try {
            $count = ImageTransformer::purgeVolumeCache($volumeHandle);

            Craft::info('purgeVolumeCache returned count: ' . $count, __METHOD__);

            if ($count === 0) {
                Craft::$app->getSession()->setFlash('error', 'No assets found in volume or cache purge is not enabled');
            } else {
                Craft::$app->getSession()->setFlash('notice', "Successfully queued {$count} image(s) for cache purging");
            }
        } catch (\Exception $e) {
            Craft::error('Failed to purge volume cache: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setFlash('error', 'Failed to purge cache: ' . $e->getMessage());
        }

        return $this->redirect(UrlHelper::cpUrl('cloudflare-signed-transforms/cache'));
    }

    /**
     * Purge entire Cloudflare cache (everything in the zone)
     *
     * @return Response|null
     */
    public function actionPurgeEverything(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        Craft::info('actionPurgeEverything called', __METHOD__);

        try {
            $success = ImageTransformer::purgeEverything();

            Craft::info('purgeEverything returned: ' . ($success ? 'true' : 'false'), __METHOD__);

            if (!$success) {
                Craft::$app->getSession()->setFlash('error', 'Failed to purge cache. Check that cache purging is enabled and configured.');
            } else {
                Craft::$app->getSession()->setFlash('notice', 'Successfully purged entire Cloudflare cache');
            }
        } catch (\Exception $e) {
            Craft::error('Failed to purge everything: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setFlash('error', 'Failed to purge cache: ' . $e->getMessage());
        }

        return $this->redirect(UrlHelper::cpUrl('cloudflare-signed-transforms/cache'));
    }

    /**
     * Purge cache for a specific asset
     *
     * @return Response|null
     */
    public function actionPurgeAsset(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('saveAssets');

        $assetId = $this->request->getBodyParam('assetId');

        if (!$assetId) {
            Craft::$app->getSession()->setFlash('error', 'Asset ID is required');
            return $this->redirectToPostedUrl();
        }

        $asset = Craft::$app->getAssets()->getAssetById($assetId);

        if (!$asset) {
            Craft::$app->getSession()->setFlash('error', 'Asset not found');
            return $this->redirectToPostedUrl();
        }

        try {
            $count = ImageTransformer::purgeAssetCache($asset);

            if ($count === 0) {
                Craft::$app->getSession()->setFlash('notice', 'No cached transforms found for this asset');
            } else {
                Craft::$app->getSession()->setFlash('notice', "Successfully purged {$count} cached transform(s)");
            }
        } catch (\Exception $e) {
            Craft::error('Failed to purge asset cache: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setFlash('error', 'Failed to purge cache: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }
}
