<?php declare(strict_types = 1);

/**
 * AuthorizePinCode.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           25.06.23
 */

namespace FastyBird\Connector\Viera\Entities\API;

use FastyBird\Connector\Viera\Entities;

/**
 * Authorize pin code entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class AuthorizePinCode implements Entities\API\Entity
{

	public function __construct(
		private readonly string|null $appId = null,
		private readonly string|null $encryptionKey = null,
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
