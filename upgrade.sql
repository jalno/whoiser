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
