-- ============================================================
--  MIGRATION: Add rfid_card_id to student_ids table
--  Links student_ids back to the RFID card that triggered creation
-- ============================================================

-- Step 1: Add nullable FK column (INT(11) to match rfid_cards.id)
ALTER TABLE `student_ids`
    ADD COLUMN `rfid_card_id` INT(11) NULL DEFAULT NULL AFTER `qr_code_path`;

-- Step 2: Add index for fast lookups
ALTER TABLE `student_ids`
    ADD INDEX `idx_student_ids_rfid_card_id` (`rfid_card_id`);

-- Step 3: Add foreign key constraint (allow NULL)
ALTER TABLE `student_ids`
    ADD CONSTRAINT `fk_student_ids_rfid_card`
    FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards`(`id`)
    ON DELETE SET NULL;
