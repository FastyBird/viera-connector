<?php declare(strict_types = 1);

/**
 * ActionKey.php
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
 * Viera action keys name types
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
enum ActionKey: string
{

	case THIRTY_SECOND_SKIP = 'NRC_30S_SKIP-ONOFF';

	case TOGGLE_3D = 'NRC_3D-ONOFF';

	case APPS = 'NRC_APPS-ONOFF';

	case ASPECT = 'NRC_ASPECT-ONOFF';

	case BLUE = 'NRC_BLUE-ONOFF';

	case CANCEL = 'NRC_CANCEL-ONOFF';

	case CC = 'NRC_CC-ONOFF';

	case CHAT_MODE = 'NRC_CHAT_MODE-ONOFF';

	case CH_DOWN = 'NRC_CH_DOWN-ONOFF';

	case INPUT = 'NRC_CHG_INPUT-ONOFF';

	case NETWORK = 'NRC_CHG_NETWORK-ONOFF';

	case CH_UP = 'NRC_CH_UP-ONOFF';

	case NUM_0 = 'NRC_D0-ONOFF';

	case NUM_1 = 'NRC_D1-ONOFF';

	case NUM_2 = 'NRC_D2-ONOFF';

	case NUM_3 = 'NRC_D3-ONOFF';

	case NUM_4 = 'NRC_D4-ONOFF';

	case NUM_5 = 'NRC_D5-ONOFF';

	case NUM_6 = 'NRC_D6-ONOFF';

	case NUM_7 = 'NRC_D7-ONOFF';

	case NUM_8 = 'NRC_D8-ONOFF';

	case NUM_9 = 'NRC_D9-ONOFF';

	case DIGA_CONTROL = 'NRC_DIGA_CTL-ONOFF';

	case DISPLAY = 'NRC_DISP_MODE-ONOFF';

	case DOWN = 'NRC_DOWN-ONOFF';

	case ENTER = 'NRC_ENTER-ONOFF';

	case EPG = 'NRC_EPG-ONOFF';

	case EZ_SYNC = 'NRC_EZ_SYNC-ONOFF';

	case FAVORITE = 'NRC_FAVORITE-ONOFF';

	case FAST_FORWARD = 'NRC_FF-ONOFF';

	case GAME = 'NRC_GAME-ONOFF';

	case GREEN = 'NRC_GREEN-ONOFF';

	case GUIDE = 'NRC_GUIDE-ONOFF';

	case HOLD = 'NRC_HOLD-ONOFF';

	case HOME = 'NRC_HOME-ONOFF';

	case INDEX = 'NRC_INDEX-ONOFF';

	case INFO = 'NRC_INFO-ONOFF';

	case CONNECT = 'NRC_INTERNET-ONOFF';

	case LEFT = 'NRC_LEFT-ONOFF';

	case MENU = 'NRC_MENU-ONOFF';

	case MPX = 'NRC_MPX-ONOFF';

	case MUTE = 'NRC_MUTE-ONOFF';

	case NET_BS = 'NRC_NET_BS-ONOFF';

	case NET_CS = 'NRC_NET_CS-ONOFF';

	case NET_TD = 'NRC_NET_TD-ONOFF';

	case OFF_TIMER = 'NRC_OFFTIMER-ONOFF';

	case PAUSE = 'NRC_PAUSE-ONOFF';

	case PICTAI = 'NRC_PICTAI-ONOFF';

	case PLAY = 'NRC_PLAY-ONOFF';

	case P_NR = 'NRC_P_NR-ONOFF';

	case POWER = 'NRC_POWER-ONOFF';

	case PROGRAM = 'NRC_PROG-ONOFF';

	case RECORD = 'NRC_REC-ONOFF';

	case RED = 'NRC_RED-ONOFF';

	case RETURN = 'NRC_RETURN-ONOFF';

	case REWIND = 'NRC_REW-ONOFF';

	case RIGHT = 'NRC_RIGHT-ONOFF';

	case R_SCREEN = 'NRC_R_SCREEN-ONOFF';

	case LAST_VIEW = 'NRC_R_TUNE-ONOFF';

	case SAP = 'NRC_SAP-ONOFF';

	case TOGGLE_SD_CARD = 'NRC_SD_CARD-ONOFF';

	case SKIP_NEXT = 'NRC_SKIP_NEXT-ONOFF';

	case SKIP_PREV = 'NRC_SKIP_PREV-ONOFF';

	case SPLIT = 'NRC_SPLIT-ONOFF';

	case STOP = 'NRC_STOP-ONOFF';

	case SUBTITLES = 'NRC_STTL-ONOFF';

	case OPTION = 'NRC_SUBMENU-ONOFF';

	case SURROUND = 'NRC_SURROUND-ONOFF';

	case SWAP = 'NRC_SWAP-ONOFF';

	case TEXT = 'NRC_TEXT-ONOFF';

	case TV = 'NRC_TV-ONOFF';

	case UP = 'NRC_UP-ONOFF';

	case LINK = 'NRC_VIERA_LINK-ONOFF';

	case VOLUME_DOWN = 'NRC_VOLDOWN-ONOFF';

	case VOLUME_UP = 'NRC_VOLUP-ONOFF';

	case VTOOLS = 'NRC_VTOOLS-ONOFF';

	case YELLOW = 'NRC_YELLOW-ONOFF';

	case AD_CHANGE = 'NRC_AD_CHANGE-ONOFF';

}
