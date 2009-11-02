USE scas;

ALTER TABLE locum_bib_items CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_bib_items_subject CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_facet_heap CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_holds_count CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;
ALTER TABLE locum_holds_placed CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci;

ALTER TABLE `locum_bib_items` ADD `upc` BIGINT UNSIGNED ZEROFILL NOT NULL AFTER `stdnum` ;

ALTER TABLE `insurge_index` CHANGE `bnum` `bnum` INT( 12 ) NOT NULL
ALTER TABLE `locum_bib_items` CHANGE `bnum` `bnum` INT( 12 ) NOT NULL
ALTER TABLE `locum_bib_items_subject` CHANGE `bnum` `bnum` INT( 12 ) NOT NULL
ALTER TABLE `locum_facet_heap` CHANGE `bnum` `bnum` INT( 12 ) NOT NULL

CREATE TABLE IF NOT EXISTS `locum_availability` (
  `bnum` int(12) unsigned NOT NULL,
  `ages` varchar(128) NOT NULL,
  `locations` varchar(128) NOT NULL,
  `available` blob NOT NULL,
  `timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
  PRIMARY KEY  (`bnum`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `locum_syndetics_links` (
`isbn` CHAR( 32 ) NOT NULL ,
`links` CHAR( 254 ) NOT NULL ,
`updated` TIMESTAMP NOT NULL ,
PRIMARY KEY ( `isbn` )
) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_general_ci COMMENT = 'Caches Syndetics content availability';