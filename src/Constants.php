<?php declare(strict_types = 1);

/**
 * Constants.php
 *
 * @license        More in LICENSE.md
 * @copyright      https://www.fastybird.com
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 * @package        FastyBird:VieraConnector!
 * @subpackage     common
 * @since          1.0.0
 *
 * @date           10.08.23
 */

namespace FastyBird\Connector\Viera;

/**
 * Connector constants
 *
 * @package        FastyBird:VieraConnector!
 * @subpackage     common
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class Constants
{

	/** array<string, Types\ChannelPropertyIdentifier> */
	public const KEYS_PROPERTIES = [
		Types\ActionKey::TV->value => Types\ChannelPropertyIdentifier::KEY_TV,
		Types\ActionKey::HOME->value => Types\ChannelPropertyIdentifier::KEY_HOME,
		Types\ActionKey::CH_UP->value => Types\ChannelPropertyIdentifier::KEY_CHANNEL_UP,
		Types\ActionKey::CH_DOWN->value => Types\ChannelPropertyIdentifier::KEY_CHANNEL_DOWN,
		Types\ActionKey::VOLUME_UP->value => Types\ChannelPropertyIdentifier::KEY_VOLUME_UP,
		Types\ActionKey::VOLUME_DOWN->value => Types\ChannelPropertyIdentifier::KEY_VOLUME_DOWN,
		Types\ActionKey::UP->value => Types\ChannelPropertyIdentifier::KEY_ARROW_UP,
		Types\ActionKey::DOWN->value => Types\ChannelPropertyIdentifier::KEY_ARROW_DOWN,
		Types\ActionKey::LEFT->value => Types\ChannelPropertyIdentifier::KEY_ARROW_LEFT,
		Types\ActionKey::RIGHT->value => Types\ChannelPropertyIdentifier::KEY_ARROW_RIGHT,
		Types\ActionKey::NUM_0->value => Types\ChannelPropertyIdentifier::KEY_0,
		Types\ActionKey::NUM_1->value => Types\ChannelPropertyIdentifier::KEY_1,
		Types\ActionKey::NUM_2->value => Types\ChannelPropertyIdentifier::KEY_2,
		Types\ActionKey::NUM_3->value => Types\ChannelPropertyIdentifier::KEY_3,
		Types\ActionKey::NUM_4->value => Types\ChannelPropertyIdentifier::KEY_4,
		Types\ActionKey::NUM_5->value => Types\ChannelPropertyIdentifier::KEY_5,
		Types\ActionKey::NUM_6->value => Types\ChannelPropertyIdentifier::KEY_6,
		Types\ActionKey::NUM_7->value => Types\ChannelPropertyIdentifier::KEY_7,
		Types\ActionKey::NUM_8->value => Types\ChannelPropertyIdentifier::KEY_8,
		Types\ActionKey::NUM_9->value => Types\ChannelPropertyIdentifier::KEY_9,
		Types\ActionKey::RED->value => Types\ChannelPropertyIdentifier::KEY_RED,
		Types\ActionKey::GREEN->value => Types\ChannelPropertyIdentifier::KEY_GREEN,
		Types\ActionKey::YELLOW->value => Types\ChannelPropertyIdentifier::KEY_YELLOW,
		Types\ActionKey::BLUE->value => Types\ChannelPropertyIdentifier::KEY_BLUE,
		Types\ActionKey::ENTER->value => Types\ChannelPropertyIdentifier::KEY_OK,
		Types\ActionKey::RETURN->value => Types\ChannelPropertyIdentifier::KEY_BACK,
		Types\ActionKey::MENU->value => Types\ChannelPropertyIdentifier::KEY_MENU,
	];

	public const TV_IDENTIFIER = 'TV';

	public const TV_CODE = 500;

	public const MAX_HDMI_CODE = 100;

	public const MIN_APPLICATION_CODE = 1_000;

}
