-- Additive review audit metadata. Apply once before deploying the review-first flow.
ALTER TABLE document_requests
 ADD COLUMN IF NOT EXISTS requirements_reviewed_at DATETIME NULL,
 ADD COLUMN IF NOT EXISTS requirements_reviewed_by INT NULL,
 ADD COLUMN IF NOT EXISTS requirements_review_note TEXT NULL;
-- Existing requests are deliberately not rewritten or marked reviewed.
