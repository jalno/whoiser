--
--	Commit: 040ccc65aefe15effa3eec908a20e5fe2f24bad9
--
CREATE TABLE `whoiser_proxies` (
	`id` int(11) NOT NULL AUTO_INCREMENT,
	`ip` varchar(15) NOT NULL,
	`port` smallint(5) unsigned NOT NULL,
	`type` varchar(7) NOT NULL,
	`country_code` varchar(3) DEFAULT NULL,
	`success_count` mediumint(8) unsigned NOT NULL DEFAULT 0,
	`fail_count` mediumint(8) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY (`id`),
	KEY `ip` (`ip`,`port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
--	Commit: 41283eea618c2d26aa9c72a7d017b2a001ee3448
--
ALTER TABLE `whoiser_domains`
	CHANGE `name` `name` VARCHAR(127) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	CHANGE `tld` `tld` VARCHAR(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	CHANGE `domain` `domain` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	CHANGE `statuses` `statuses` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
	CHANGE `registrar` `registrar` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
	CHANGE `nservers` `nservers` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL; 

