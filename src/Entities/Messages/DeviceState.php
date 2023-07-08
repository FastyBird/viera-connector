<?php declare(strict_types = 1);

/**
 * DeviceState.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           29.06.23
 */

namespace FastyBird\Connector\Viera\Entities\Messages;

use FastyBird\Library\Metadata\Types as MetadataTypes;
use Ramsey\Uuid;
use function array_merge;

/**
 * Device state message entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class DeviceState extends Device
{

	public function __construct(
		Uuid\UuidInterface $connector,
		string $identifier,
		private readonly MetadataTypes\ConnectionState $state,
	)
	{
		parent::__construct($connector, $identifier);
	}

	public function getState(): MetadataTypes\ConnectionState
	{
		return $this->state;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return array_merge(parent::toArray(), [
			'state' => $this->getState()->getValue(),
		]);
	}

}
