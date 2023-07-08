<?php declare(strict_types = 1);

/**
 * RequestPinCode.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           22.06.23
 */

namespace FastyBird\Connector\Viera\Entities\API;

use FastyBird\Connector\Viera\Entities;

/**
 * Request pin code entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class RequestPinCode implements Entities\API\Entity
{

	public function __construct(
		private readonly string|null $X_ChallengeKey = null,
	)
	{
	}

	public function getXChallengeKey(): string|null
	{
		return $this->X_ChallengeKey;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'challenge_key' => $this->getXChallengeKey(),
		];
	}

}
