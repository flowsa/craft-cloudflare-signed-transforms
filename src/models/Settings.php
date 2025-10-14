<?php

namespace richardfrankza\cfst\models;

use Craft;
use craft\base\Model;

/**
 * Cloudflare Signed Transforms settings
 */
class Settings extends Model
{
	public ?string $workerUrl = null;
	public ?string $signatureSecret = null;
	public int $defaultExpiration = 0; // 0 = no expiration

	// Cache purging settings
	public bool $enableCachePurge = false;
	public ?string $zoneId = null;
	public ?string $apiKey = null;

	public function rules(): array
	{
		return [
			[['workerUrl', 'signatureSecret'], 'required'],
			['workerUrl', 'url'],
			['defaultExpiration', 'integer', 'min' => 0],
			['enableCachePurge', 'boolean'],
			[['zoneId', 'apiKey'], 'string'],
		];
	}

	public function attributeLabels(): array
	{
		return [
			'workerUrl' => 'Worker URL',
			'signatureSecret' => 'Signature Secret',
			'defaultExpiration' => 'Default Expiration (seconds)',
			'enableCachePurge' => 'Enable Cache Purge',
			'zoneId' => 'Cloudflare Zone ID',
			'apiKey' => 'Cloudflare API Key',
		];
	}
}
