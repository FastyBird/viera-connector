<?php declare(strict_types = 1);

/**
 * AuthorizePinCode.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           25.06.23
 */

namespace FastyBird\Connector\Viera\API\Messages\Response;

use FastyBird\Connector\Viera\API;
use Orisai\ObjectMapper;

/**
 * Authorize pin code message
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
readonly class AuthorizePinCode implements API\Messages\Message
{

	public function __construct(
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('app_id')]
		private string|null $appId = null,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('encryption_key')]
		private string|null $encryptionKey = null,
	)
	{
	}

	public function getAppId(): string|null
	{
		return $this->appId;
	}

	public function getEncryptionKey(): string|null
	{
		return $this->encryptionKey;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'app_id' => $this->getAppId(),
			'encryption_key' => $this->getEncryptionKey(),
		];
	}

}
