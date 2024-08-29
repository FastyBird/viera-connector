<?php declare(strict_types = 1);

/**
 * LocalDevice.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     ValueObjects
 * @since          1.0.0
 *
 * @date           29.08.24
 */

namespace FastyBird\Connector\Viera\ValueObjects;

use Orisai\ObjectMapper;
use function strtolower;

/**
 * Local device info
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     ValueObjects
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
final readonly class LocalDevice implements ObjectMapper\MappedObject
{

	public function __construct(
		#[ObjectMapper\Rules\StringValue(notEmpty: true)]
		private string $id,
		#[ObjectMapper\Rules\StringValue(notEmpty: true)]
		private string $host,
		#[ObjectMapper\Rules\IntValue(castNumericString: true)]
		private int $port,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getHost(): string
	{
		return $this->host;
	}

	public function getPort(): int
	{
		return $this->port;
	}

	/**
	 * @return array<string, string|int>
	 */
	public function __serialize(): array
	{
		return [
			'id' => $this->getId(),
			'host' => strtolower($this->getHost()),
			'port' => $this->getPort(),
		];
	}

}
