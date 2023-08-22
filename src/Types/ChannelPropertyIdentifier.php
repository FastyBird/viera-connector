<?php declare(strict_types = 1);

/**
 * ChannelPropertyIdentifier.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 * @since          1.0.0
 *
 * @date           28.06.23
 */

namespace FastyBird\Connector\Viera\Types;

use Consistence;
use function strval;

/**
 * Device property identifier types
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class ChannelPropertyIdentifier extends Consistence\Enum\Enum
{

	/**
	 * Define device states
	 */
	public const STATE = 'state';

	public const VOLUME = 'volume';

	public const MUTE = 'mute';

	public const INPUT_SOURCE = 'input_source';

	public const APPLICATION = 'application';

	public const HDMI = 'hdmi';

	public const KEY_TV = 'key_tv';

	public const KEY_HOME = 'key_home';

	public const KEY_CHANNEL_UP = 'key_channel_up';

	public const KEY_CHANNEL_DOWN = 'key_channel_down';

	public const KEY_VOLUME_UP = 'key_volume_up';

	public const KEY_VOLUME_DOWN = 'key_volume_down';

	public const KEY_ARROW_UP = 'key_arrow_up';

	public const KEY_ARROW_DOWN = 'key_arrow_down';

	public const KEY_ARROW_LEFT = 'key_arrow_left';

	public const KEY_ARROW_RIGHT = 'key_arrow_right';

	public const KEY_0 = 'key_0';

	public const KEY_1 = 'key_1';

	public const KEY_2 = 'key_2';

	public const KEY_3 = 'key_3';

	public const KEY_4 = 'key_4';

	public const KEY_5 = 'key_5';

	public const KEY_6 = 'key_6';

	public const KEY_7 = 'key_7';

	public const KEY_8 = 'key_8';

	public const KEY_9 = 'key_9';

	public const KEY_RED = 'key_red';

	public const KEY_GREEN = 'key_green';

	public const KEY_YELLOW = 'key_yellow';

	public const KEY_BLUE = 'key_blue';

	public const KEY_OK = 'key_ok';

	public const KEY_BACK = 'key_back';

	public function getValue(): string
	{
		return strval(parent::getValue());
	}

	public function __toString(): string
	{
		return strval(self::getValue());
	}

}
