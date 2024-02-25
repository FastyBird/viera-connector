<?php declare(strict_types = 1);

/**
 * RequestPinCode.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           22.06.23
 */

namespace FastyBird\Connector\Viera\API\Messages\Response;

use FastyBird\Connector\Viera\API;
use Orisai\ObjectMapper;

/**
 * Request pin code message
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
readonly class RequestPinCode implements API\Messages\Message
{

	public function __construct(
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('challenge_key')]
		private string|null $challengeKey = null,
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
