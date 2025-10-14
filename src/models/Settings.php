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

	public function rules(): array
	{
		return [
			[['workerUrl', 'signatureSecret'], 'required'],
			['workerUrl', 'url'],
			['defaultExpiration', 'integer', 'min' => 0],
		];
	}

	public function attributeLabels(): array
	{
		return [
			'workerUrl' => 'Worker URL',
			'signatureSecret' => 'Signature Secret',
			'defaultExpiration' => 'Default Expiration (seconds)',
		];
	}
}
