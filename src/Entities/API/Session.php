<?php declare(strict_types = 1);

/**
 * Session.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           27.06.23
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
class Session implements Entities\API\Entity
{

	public function __construct(
		private readonly string $key,
		private readonly string $iv,
		private readonly string $hmacKey,
		private string|null $id = null,
		private int|null $seqNum = null,
	)
	{
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function getIv(): string
	{
		return $this->iv;
	}

	public function getHmacKey(): string
	{
		return $this->hmacKey;
	}

	public function getId(): string|null
	{
		return $this->id;
	}

	public function setId(string|null $id): void
	{
		$this->id = $id;
	}

	public function getSeqNum(): int|null
	{
		return $this->seqNum;
	}

	public function setSeqNum(int $seqNum): void
	{
		$this->seqNum = $seqNum;
	}

	public function incrementSeqNum(): void
	{
		if ($this->seqNum === null) {
			$this->seqNum = 1;
		} else {
			$this->seqNum += 1;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'key' => $this->getKey(),
			'iv' => $this->getIv(),
			'hmac_key' => $this->getHmacKey(),
			'id' => $this->getId(),
			'seq_nu,' => $this->getSeqNum(),
		];
	}

}
