INSERT
IGNORE INTO `fb_devices_module_connectors` (`connector_id`, `connector_identifier`, `connector_name`, `connector_comment`, `connector_enabled`, `connector_type`, `created_at`, `updated_at`) VALUES
(_binary 0x0fb0b34f78044127b65c0561dff092e4, 'viera', 'Viera', null, true, 'viera', '2023-08-12 11:00:00', '2023-08-12 11:00:00');

INSERT
IGNORE INTO `fb_devices_module_connectors_controls` (`control_id`, `connector_id`, `control_name`, `created_at`, `updated_at`) VALUES
(_binary 0xe7c9e5834af14b86b647f179207e6456, _binary 0x0fb0b34f78044127b65c0561dff092e4, 'discover', '2023-08-12 11:00:00', '2023-08-12 11:00:00');
