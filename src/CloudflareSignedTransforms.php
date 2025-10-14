<?php

namespace richardfrankza\cfst;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use richardfrankza\cfst\models\Settings;
use craft\imagetransforms\ImageTransformer as CraftImageTransformer;
use craft\imagetransforms\FallbackTransformer;

/**
 * Cloudflare Signed Transforms plugin
 *
 * @method static CloudflareSignedTransforms getInstance()
 * @method Settings getSettings()
 * @author Richard Frank <richard@flow.co.za>
 * @copyright Richard Frank
 * @license MIT
 */
class CloudflareSignedTransforms extends Plugin
{
    public string $schemaVersion = '2.0.0';
    public bool $hasCpSettings = true;


    public function init(): void
    {
        parent::init();
		Craft::$app->getImages()->supportedImageFormats = ImageTransformer::SUPPORTED_IMAGE_FORMATS;
		$this->overrideDefaultTransformer();
    }

	/**
	 * Injects the Cloudflare Signed transformer as default transformer
	 */
	protected function overrideDefaultTransformer(): void
	{
		Craft::$container->set(
			CraftImageTransformer::class,
			ImageTransformer::class,
		);

		Craft::$container->set(
			FallbackTransformer::class,
			ImageTransformer::class,
		);
	}

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('cloudflare-signed-transforms/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
}
