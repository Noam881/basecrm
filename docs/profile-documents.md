# Profile documents

Documents is a shared Core capability on the existing contact and company profiles. It adds no navigation entry or business module. General client attachments belong here; proposal versions, signed contracts and encrypted Vault attachments retain their existing dedicated services.

The additive `crm_documents` table references exactly one existing contact or company. Every list, upload and download checks that profile's current permissions. Uploads require edit access; readable profiles allow private attachment downloads. Reassignment and account deactivation affect subsequent requests. The service rechecks the actor and parent under the existing identity lock before storing an upload.

Uploads preserve the original UTF-8 basename and detected MIME type, use a random installation-local private path and verify stored bytes against a SHA-256 digest before committing metadata. Paths and digests remain server-side. Supported formats are PDF, Word, Excel, CSV, text, JPEG, PNG, GIF, WebP and ZIP, up to 100 MB. Downloads use attachment disposition, no-store and nosniff headers. This validation does not constitute a malware scan.

Records and files are retained as client history. Corrections are new uploads; this capability has no replace or delete action. The additive migration does not change existing identities or lead records, and its rollback refuses destructive table removal. Recovery uses a tested, consistent database and private-storage backup.

`uploaded_by` and `created_at` describe the actual CRM upload. They must not be rewritten to imply that an imported source author performed that action. Source asset IDs, source relationships and original authors/timestamps belong in the reviewed migration provenance ledger; the current capability alone is not a completed historical import.

Production PHP and reverse-proxy request limits must accommodate the chosen file size and multipart overhead. Capacity planning and backups must include the installation's private `documents/` storage alongside its database. Missing files remain visible as unavailable; they must not be silently removed from the document inventory.

The focused backend suite covers profile permissions, revocation, cross-profile/type binding, metadata privacy, original filenames, exact downloaded bytes, file validation, failure cleanup and an additive migration around existing records. Local browser verification uses only guarded fictional accounts and preserves private recovery copies of the exact selected test files. Earlier fixture-recovery formats remain supported.
