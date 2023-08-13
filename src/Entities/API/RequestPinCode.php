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

use Orisai\ObjectMapper;

/**
 * Request pin code entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class RequestPinCode implements Entity
{

	public function __construct(
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('challenge_key')]
		private readonly string|null $challengeKey = null,
	)
	{
	}

	public function getChallengeKey(): string|null
	{
		return $this->challengeKey;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'challenge_key' => $this->getChallengeKey(),
		];
	}

}
