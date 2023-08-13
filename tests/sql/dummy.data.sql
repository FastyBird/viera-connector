INSERT
IGNORE INTO `fb_devices_module_connectors` (`connector_id`, `connector_identifier`, `connector_name`, `connector_comment`, `connector_enabled`, `connector_type`, `created_at`, `updated_at`) VALUES
(_binary 0x93e760e1f0114a33a70dc9629706ccf8, 'viera', 'Viera', null, true, 'viera', '2023-08-12 11:00:00', '2023-08-12 11:00:00');

INSERT
IGNORE INTO `fb_devices_module_connectors_controls` (`control_id`, `connector_id`, `control_name`, `created_at`, `updated_at`) VALUES
(_binary 0xe7c9e5834af14b86b647f179207e6456, _binary 0x93e760e1f0114a33a70dc9629706ccf8, 'discover', '2023-08-12 11:00:00', '2023-08-12 11:00:00');
