<?php declare(strict_types = 1);

/**
 * Session.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           27.06.23
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
class Session implements API\Messages\Message
{

	public function __construct(
		#[ObjectMapper\Rules\StringValue(notEmpty: true)]
		private readonly string $key,
		#[ObjectMapper\Rules\StringValue(notEmpty: true)]
		private readonly string $iv,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('hmac_key')]
		private readonly string $hmacKey,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		private string|null $id = null,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\IntValue(unsigned: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('seq_num')]
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
			'seq_num,' => $this->getSeqNum(),
		];
	}

}
