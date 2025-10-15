<?php

namespace flowsa\cfst\models;

use Craft;
use craft\base\Model;

/**
 * Cloudflare Signed Transforms settings
 */
class Settings extends Model
{
	public ?string $workerUrl = null;
	public ?string $signatureSecret = null;
	public string|int $defaultExpiration = 0; // 0 = no expiration, or env var string

	// Cache purging settings
	public bool $enableCachePurge = false;
	public ?string $zoneId = null;
	public ?string $apiKey = null;

	public function rules(): array
	{
		return [
			[['workerUrl', 'signatureSecret'], 'required'],
			['workerUrl', 'validateWorkerUrl'],
			['signatureSecret', 'string'],
			['defaultExpiration', 'validateDefaultExpiration'],
			['enableCachePurge', 'boolean'],
			[['zoneId', 'apiKey'], 'string'],
		];
	}

	/**
	 * Validates the worker URL by parsing environment variables first
	 */
	public function validateWorkerUrl($attribute): void
	{
		$url = \craft\helpers\App::parseEnv($this->$attribute);

		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			$this->addError($attribute, 'Worker URL must be a valid URL.');
		}
	}

	/**
	 * Validates the default expiration by parsing environment variables first
	 */
	public function validateDefaultExpiration($attribute): void
	{
		$value = \craft\helpers\App::parseEnv($this->$attribute);

		if (!is_numeric($value) || $value < 0) {
			$this->addError($attribute, 'Default Expiration must be a number greater than or equal to 0.');
		}
	}

	public function attributeLabels(): array
	{
		return [
			'workerUrl' => 'Worker URL',
			'signatureSecret' => 'Signature Secret',
			'defaultExpiration' => 'Default Expiration',
			'enableCachePurge' => 'Enable Cache Purge',
			'zoneId' => 'Cloudflare Zone ID',
			'apiKey' => 'Cloudflare API Key',
		];
	}

	public function attributeHints(): array
	{
		return [
			'workerUrl' => 'The URL of your Cloudflare Worker. Deploy the worker from: https://github.com/flowsa/cfworker-image-resizer',
			'signatureSecret' => 'HMAC secret shared between plugin and worker. Must match the SIGNATURE_SECRET in your Cloudflare Worker.',
			'defaultExpiration' => 'Optional: Default time (in seconds) before signed URLs expire. Set to 0 for no expiration (recommended for public websites).',
			'enableCachePurge' => 'Automatically purge images from Cloudflare cache when assets are updated/replaced.',
			'zoneId' => 'Find in: Cloudflare Dashboard → Your Domain → Overview (right sidebar)',
			'apiKey' => 'Create an API Token at: Cloudflare Dashboard → My Profile → API Tokens. Required permission: "Zone.Cache Purge"',
		];
	}
}
