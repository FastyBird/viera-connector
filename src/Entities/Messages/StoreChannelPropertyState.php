<?php declare(strict_types = 1);

/**
 * StoreChannelPropertyState.php
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

use FastyBird\Library\Bootstrap\ObjectMapper as BootstrapObjectMapper;
use Orisai\ObjectMapper;
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
final class StoreChannelPropertyState extends Device
{

	public function __construct(
		Uuid\UuidInterface $connector,
		Uuid\UuidInterface $device,
		#[ObjectMapper\Rules\AnyOf([
			new BootstrapObjectMapper\Rules\UuidValue(),
			new ObjectMapper\Rules\StringValue(notEmpty: true),
		])]
		private readonly Uuid\UuidInterface|string $channel,
		#[ObjectMapper\Rules\AnyOf([
			new BootstrapObjectMapper\Rules\UuidValue(),
			new ObjectMapper\Rules\StringValue(notEmpty: true),
		])]
		private readonly Uuid\UuidInterface|string $property,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\BoolValue(),
			new ObjectMapper\Rules\FloatValue(),
			new ObjectMapper\Rules\IntValue(),
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(castEmptyString: true),
		])]
		private readonly bool|float|int|string|null $value,
	)
	{
		parent::__construct($connector, $device);
	}

	public function getChannel(): Uuid\UuidInterface|string
	{
		return $this->channel;
	}

	public function getProperty(): Uuid\UuidInterface|string
	{
		return $this->property;
	}

	public function getValue(): bool|float|int|string|null
	{
		return $this->value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return array_merge(parent::toArray(), [
			'channel' => $this->getChannel() instanceof Uuid\UuidInterface ? $this->getChannel()->toString() : $this->getChannel(),
			'property' => $this->getProperty() instanceof Uuid\UuidInterface ? $this->getProperty()->toString() : $this->getProperty(),
			'value' => $this->getValue(),
		]);
	}

}
