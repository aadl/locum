ALTER TABLE locum_bib_items CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_bib_items_subject CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_facet_heap CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_holds_count CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_holds_placed CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;

ALTER TABLE `locum_bib_items` ADD `upc` BIGINT UNSIGNED ZEROFILL NOT NULL AFTER `stdnum` ;

CREATE TABLE IF NOT EXISTS `locum_availability` (
 `bnum` mediumint(8) unsigned NOT NULL,
 `ages` varchar(128) NOT NULL,
 `locations` varchar(128) NOT NULL,
 `available` blob NOT NULL,
 `timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP on update
CURRENT_TIMESTAMP,
 PRIMARY KEY  (`bnum`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `locum_inum_to_bnum` (
 `inum` int(10) unsigned NOT NULL,
 `bnum` int(10) unsigned NOT NULL,
 `timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP on update
CURRENT_TIMESTAMP,
 PRIMARY KEY  (`inum`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;