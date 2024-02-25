<?php declare(strict_types = 1);

/**
 * Event.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 * @since          1.0.0
 *
 * @date           03.07.23
 */

namespace FastyBird\Connector\Viera\API\Messages\Response;

use FastyBird\Connector\Viera\API;
use Orisai\ObjectMapper;

/**
 * Event data message
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     API
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
readonly class Event implements API\Messages\Message
{

	public function __construct(
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\BoolValue(),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('screen_state')]
		private bool|null $screenState,
		#[ObjectMapper\Rules\AnyOf([
			new ObjectMapper\Rules\StringValue(notEmpty: true),
			new ObjectMapper\Rules\NullValue(),
		])]
		#[ObjectMapper\Modifiers\FieldName('input_mode')]
		private string|null $inputMode,
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
