-- phpMyAdmin SQL Dump
-- version 2.9.1.1-Debian-7
-- http://www.phpmyadmin.net
-- 
-- Host: localhost
-- Generation Time: Sep 24, 2008 at 11:54 AM
-- Server version: 5.0.32
-- PHP Version: 5.2.0-8+etch11
-- 
-- Database: `scas`
-- 

-- --------------------------------------------------------

CREATE DATABASE IF NOT EXISTS `scas`;
USE scas;

-- 
-- Table structure for table `locum_bib_items`
-- 

CREATE TABLE IF NOT EXISTS `locum_bib_items` (
  `bnum` mediumint(13) NOT NULL,
  `author` char(254) default NULL,
  `addl_author` text,
  `title` varchar(512) NOT NULL,
  `title_medium` char(64) default NULL,
  `edition` char(64) default NULL,
  `series` char(254) default NULL,
  `callnum` char(48) default NULL,
  `pub_info` char(254) default NULL,
  `pub_year` smallint(4) default NULL,
  `stdnum` char(32) default NULL,
  `lccn` char(32) default NULL,
  `descr` text,
  `notes` text,
  `subjects` text,
  `lang` char(12) default NULL,
  `loc_code` char(7) NOT NULL,
  `mat_code` char(7) NOT NULL,
  `cover_img` char(254) default NULL,
  `modified` datetime NOT NULL,
  `bib_created` date NOT NULL,
  `bib_lastupdate` date NOT NULL,
  `bib_prevupdate` date NOT NULL,
  `bib_revs` int(4) NOT NULL,
  `active` enum('0','1') NOT NULL default '1',
  PRIMARY KEY  (`bnum`),
  KEY `modified` (`modified`),
  KEY `mat_code` (`mat_code`),
  KEY `pub_year` (`pub_year`),
  KEY `active` (`active`),
  KEY `bib_lastupdate` (`bib_lastupdate`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Item table for Bib records';

-- --------------------------------------------------------

-- 
-- Table structure for table `locum_bib_items_subject`
-- 

CREATE TABLE IF NOT EXISTS `locum_bib_items_subject` (
  `bnum` int(13) NOT NULL,
  `subjects` char(254) NOT NULL,
  KEY `bnum` (`bnum`),
  KEY `subjects` (`subjects`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Table for bibliographic subject headings';

-- --------------------------------------------------------

-- 
-- Table structure for table `locum_holds_count`
-- 

CREATE TABLE IF NOT EXISTS `locum_holds_count` (
  `bnum` int(12) NOT NULL,
  `hold_count_week` int(6) NOT NULL default '0',
  `hold_count_month` int(6) NOT NULL default '0',
  `hold_count_year` int(6) NOT NULL default '0',
  `hold_count_total` int(6) NOT NULL default '0',
  PRIMARY KEY  (`bnum`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

-- 
-- Table structure for table `locum_holds_placed`
-- 

CREATE TABLE IF NOT EXISTS `locum_holds_placed` (
  `bnum` int(12) NOT NULL,
  `hold_date` date NOT NULL,
  KEY `bnum` (`bnum`,`hold_date`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
