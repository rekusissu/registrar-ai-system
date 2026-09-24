-- Migration: Add previous school fields to students table
-- Date: 2026-09-23
-- Description: Adds columns for previous school information to support the Personal Info Viewing feature

ALTER TABLE `students`
  ADD COLUMN `previous_school` varchar(150) DEFAULT NULL COMMENT 'Name of previous school' AFTER `birth_country`,
  ADD COLUMN `school_year_graduated` varchar(20) DEFAULT NULL COMMENT 'School year graduated from previous school' AFTER `previous_school`,
  ADD COLUMN `last_year_level_completed` varchar(30) DEFAULT NULL COMMENT 'Last year level completed in previous school' AFTER `school_year_graduated`;
