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

use Consistence;
use function strval;

/**
 * Viera action keys name types
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     Types
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class ActionKey extends Consistence\Enum\Enum
{

	/**
	 * Define action keys
	 */
	public const THIRTY_SECOND_SKIP = 'NRC_30S_SKIP-ONOFF';

	public const TOGGLE_3D = 'NRC_3D-ONOFF';

	public const APPS = 'NRC_APPS-ONOFF';

	public const ASPECT = 'NRC_ASPECT-ONOFF';

	public const BLUE = 'NRC_BLUE-ONOFF';

	public const CANCEL = 'NRC_CANCEL-ONOFF';

	public const CC = 'NRC_CC-ONOFF';

	public const CHAT_MODE = 'NRC_CHAT_MODE-ONOFF';

	public const CH_DOWN = 'NRC_CH_DOWN-ONOFF';

	public const INPUT = 'NRC_CHG_INPUT-ONOFF';

	public const NETWORK = 'NRC_CHG_NETWORK-ONOFF';

	public const CH_UP = 'NRC_CH_UP-ONOFF';

	public const NUM_0 = 'NRC_D0-ONOFF';

	public const NUM_1 = 'NRC_D1-ONOFF';

	public const NUM_2 = 'NRC_D2-ONOFF';

	public const NUM_3 = 'NRC_D3-ONOFF';

	public const NUM_4 = 'NRC_D4-ONOFF';

	public const NUM_5 = 'NRC_D5-ONOFF';

	public const NUM_6 = 'NRC_D6-ONOFF';

	public const NUM_7 = 'NRC_D7-ONOFF';

	public const NUM_8 = 'NRC_D8-ONOFF';

	public const NUM_9 = 'NRC_D9-ONOFF';

	public const DIGA_CONTROL = 'NRC_DIGA_CTL-ONOFF';

	public const DISPLAY = 'NRC_DISP_MODE-ONOFF';

	public const DOWN = 'NRC_DOWN-ONOFF';

	public const ENTER = 'NRC_ENTER-ONOFF';

	public const EPG = 'NRC_EPG-ONOFF';

	public const EZ_SYNC = 'NRC_EZ_SYNC-ONOFF';

	public const FAVORITE = 'NRC_FAVORITE-ONOFF';

	public const FAST_FORWARD = 'NRC_FF-ONOFF';

	public const GAME = 'NRC_GAME-ONOFF';

	public const GREEN = 'NRC_GREEN-ONOFF';

	public const GUIDE = 'NRC_GUIDE-ONOFF';

	public const HOLD = 'NRC_HOLD-ONOFF';

	public const HOME = 'NRC_HOME-ONOFF';

	public const INDEX = 'NRC_INDEX-ONOFF';

	public const INFO = 'NRC_INFO-ONOFF';

	public const CONNECT = 'NRC_INTERNET-ONOFF';

	public const LEFT = 'NRC_LEFT-ONOFF';

	public const MENU = 'NRC_MENU-ONOFF';

	public const MPX = 'NRC_MPX-ONOFF';

	public const MUTE = 'NRC_MUTE-ONOFF';

	public const NET_BS = 'NRC_NET_BS-ONOFF';

	public const NET_CS = 'NRC_NET_CS-ONOFF';

	public const NET_TD = 'NRC_NET_TD-ONOFF';

	public const OFF_TIMER = 'NRC_OFFTIMER-ONOFF';

	public const PAUSE = 'NRC_PAUSE-ONOFF';

	public const PICTAI = 'NRC_PICTAI-ONOFF';

	public const PLAY = 'NRC_PLAY-ONOFF';

	public const P_NR = 'NRC_P_NR-ONOFF';

	public const POWER = 'NRC_POWER-ONOFF';

	public const PROGRAM = 'NRC_PROG-ONOFF';

	public const RECORD = 'NRC_REC-ONOFF';

	public const RED = 'NRC_RED-ONOFF';

	public const RETURN = 'NRC_RETURN-ONOFF';

	public const REWIND = 'NRC_REW-ONOFF';

	public const RIGHT = 'NRC_RIGHT-ONOFF';

	public const R_SCREEN = 'NRC_R_SCREEN-ONOFF';

	public const LAST_VIEW = 'NRC_R_TUNE-ONOFF';

	public const SAP = 'NRC_SAP-ONOFF';

	public const TOGGLE_SD_CARD = 'NRC_SD_CARD-ONOFF';

	public const SKIP_NEXT = 'NRC_SKIP_NEXT-ONOFF';

	public const SKIP_PREV = 'NRC_SKIP_PREV-ONOFF';

	public const SPLIT = 'NRC_SPLIT-ONOFF';

	public const STOP = 'NRC_STOP-ONOFF';

	public const SUBTITLES = 'NRC_STTL-ONOFF';

	public const OPTION = 'NRC_SUBMENU-ONOFF';

	public const SURROUND = 'NRC_SURROUND-ONOFF';

	public const SWAP = 'NRC_SWAP-ONOFF';

	public const TEXT = 'NRC_TEXT-ONOFF';

	public const TV = 'NRC_TV-ONOFF';

	public const UP = 'NRC_UP-ONOFF';

	public const LINK = 'NRC_VIERA_LINK-ONOFF';

	public const VOLUME_DOWN = 'NRC_VOLDOWN-ONOFF';

	public const VOLUME_UP = 'NRC_VOLUP-ONOFF';

	public const VTOOLS = 'NRC_VTOOLS-ONOFF';

	public const YELLOW = 'NRC_YELLOW-ONOFF';

	public const AD_CHANGE = 'NRC_AD_CHANGE-ONOFF';

	public function getValue(): string
	{
		return strval(parent::getValue());
	}

	public function __toString(): string
	{
		return strval(self::getValue());
	}

}
