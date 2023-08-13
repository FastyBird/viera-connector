<?php declare(strict_types = 1);

/**
 * Event.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 * @since          1.0.0
 *
 * @date           03.07.23
 */

namespace FastyBird\Connector\Viera\Entities\API;

use Orisai\ObjectMapper;

/**
 * Event data entity
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Entities
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Event implements Entity
{

	public function __construct(
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\BoolValue(),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('screen_state')]
		private readonly bool|null $screenState,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('input_mode')]
		private readonly string|null $inputMode,
	)
	{
	}

	public function getScreenState(): bool|null
	{
		return $this->screenState;
	}

	public function getInputMode(): string|null
	{
		return $this->inputMode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function toArray(): array
	{
		return [
			'screen_state' => $this->getScreenState(),
			'input_mode' => $this->getInputMode(),
		];
	}

}
