-- ============================================================
--  database/document_requests_paymongo.sql
--  Document Request Module — real GCash via PayMongo (sandbox).
--
--  Adds gateway provenance + the PayMongo source/payment ids to
--  mock_payment_transactions:
--    gateway                'mock' (default) | 'paymongo'
--    paymongo_intent_id     holds the src_… Payment Source id (the
--                           hosted checkout flow — Payment Intents
--                           don't return a checkout_url in sandbox).
--                           Used by the webhook receiver and the
--                           status poll to find the transaction.
--    paymongo_payment_id    the pay_… id, written when a chargeable
--                           source is captured (Payment created);
--                           lets the payment.paid webhook match the
--                           transaction.
--
--  payment_url already stores the hosted checkout URL, so no extra
--  column is needed for it. Existing mock rows keep gateway='mock'.
--
--  Re-runnable (ADD COLUMN IF NOT EXISTS / ADD KEY IF NOT EXISTS).
--  Targets MariaDB 10.4+ (XAMPP).
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `mock_payment_transactions`
  ADD COLUMN IF NOT EXISTS `gateway` varchar(20) NOT NULL DEFAULT 'mock' AFTER `due_on`,
  ADD COLUMN IF NOT EXISTS `paymongo_intent_id` varchar(40) DEFAULT NULL AFTER `gateway`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_id` varchar(40) DEFAULT NULL AFTER `paymongo_intent_id`;

ALTER TABLE `mock_payment_transactions`
  ADD UNIQUE KEY IF NOT EXISTS `uq_txn_paymongo_intent` (`paymongo_intent_id`),
  ADD KEY IF NOT EXISTS `idx_txn_gateway` (`gateway`),
  ADD KEY IF NOT EXISTS `idx_txn_paymongo_payment` (`paymongo_payment_id`);
