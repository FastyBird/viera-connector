<?php declare(strict_types = 1);

/**
 * ChannelPropertyState.php
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

use DateTimeInterface;
use FastyBird\Library\Metadata\Types as MetadataTypes;
use Ramsey\Uuid;
use function array_merge;

/**
 * Channel property state message entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final class ChannelPropertyState extends Device
{

	public function __construct(
		Uuid\UuidInterface $connector,
		string $device,
		private readonly string $channel,
		private readonly string $property,
		// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
		private readonly bool|float|int|string|DateTimeInterface|MetadataTypes\ButtonPayload|MetadataTypes\SwitchPayload|MetadataTypes\CoverPayload|null $state,
	)
	{
		parent::__construct($connector, $device);
	}

	public function getChannel(): string
	{
		return $this->channel;
	}

	public function getProperty(): string
	{
		return $this->property;
	}
	// phpcs:ignore SlevomatCodingStandard.Files.LineLength.LineTooLong
	public function getState(): bool|float|int|string|DateTimeInterface|MetadataTypes\ButtonPayload|MetadataTypes\SwitchPayload|MetadataTypes\CoverPayload|null
	{
		return $this->state;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return array_merge(parent::toArray(), [
			'channel' => $this->getChannel(),
			'property' => $this->getProperty(),
			'state' => $this->getState(),
		]);
	}

}
