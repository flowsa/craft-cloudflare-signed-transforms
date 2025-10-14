<?php

namespace flowsa\cfst;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use flowsa\cfst\models\Settings;
use craft\imagetransforms\ImageTransformer as CraftImageTransformer;
use craft\imagetransforms\FallbackTransformer;
use craft\elements\Asset;
use craft\events\DefineHtmlEvent;

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

    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Set controller namespace
        $this->controllerNamespace = 'flowsa\cfst\controllers';

		Craft::$app->getImages()->supportedImageFormats = ImageTransformer::SUPPORTED_IMAGE_FORMATS;
		$this->overrideDefaultTransformer();

        // Register CP routes
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpRoutes();
        }
    }

    private function _registerCpRoutes(): void
    {
        \yii\base\Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(\craft\events\RegisterUrlRulesEvent $event) {
                $event->rules['cloudflare-signed-transforms/cache'] = 'cloudflare-signed-transforms/cache/index';
            }
        );
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
