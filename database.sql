CREATE TABLE `whoiser_domains` (
	`id` int(11) NOT NULL AUTO_INCREMENT,
	`name` varchar(127) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	`tld` varchar(63) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	`domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	`is_registered` tinyint(1) NOT NULL,
	`statuses` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
	`registrar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
	`nservers` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
	`create_at` int(11) DEFAULT NULL,
	`change_at` int(11) DEFAULT NULL,
	`expire_at` int(11) DEFAULT NULL,
	`whois_at` int(11) NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `domain` (`domain`),
	UNIQUE KEY `name` (`name`,`tld`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
