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

/**
 * Device property identifier types
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
enum ChannelPropertyIdentifier: string
{

	case STATE = 'state';

	case VOLUME = 'volume';

	case MUTE = 'mute';

	case REMOTE = 'remote';

	case INPUT_SOURCE = 'input_source';

	case APPLICATION = 'application';

	case HDMI = 'hdmi';

	case KEY_TV = 'key_tv';

	case KEY_HOME = 'key_home';

	case KEY_CHANNEL_UP = 'key_channel_up';

	case KEY_CHANNEL_DOWN = 'key_channel_down';

	case KEY_VOLUME_UP = 'key_volume_up';

	case KEY_VOLUME_DOWN = 'key_volume_down';

	case KEY_ARROW_UP = 'key_arrow_up';

	case KEY_ARROW_DOWN = 'key_arrow_down';

	case KEY_ARROW_LEFT = 'key_arrow_left';

	case KEY_ARROW_RIGHT = 'key_arrow_right';

	case KEY_0 = 'key_0';

	case KEY_1 = 'key_1';

	case KEY_2 = 'key_2';

	case KEY_3 = 'key_3';

	case KEY_4 = 'key_4';

	case KEY_5 = 'key_5';

	case KEY_6 = 'key_6';

	case KEY_7 = 'key_7';

	case KEY_8 = 'key_8';

	case KEY_9 = 'key_9';

	case KEY_RED = 'key_red';

	case KEY_GREEN = 'key_green';

	case KEY_YELLOW = 'key_yellow';

	case KEY_BLUE = 'key_blue';

	case KEY_OK = 'key_ok';

	case KEY_BACK = 'key_back';

	case KEY_MENU = 'key_menu';

}
