# Protected Monday workdocs

Vault owns this optional extension. It remains inactive until the installation operator supplies both `VAULT_MONDAY_WORKDOC_PLAN_FILE` and `VAULT_MONDAY_WORKDOC_PLAN_SHA256`. The independent supplement does not change the already armed item/file plan, provider credentials, Vault permissions or five-minute unlock.

The external, deployment-owned supplement must have a private 0700 parent directory and a regular 0600 file. Its version-1 structure is:

```json
{
  "version": 1,
  "main_plan_hash": "<existing main plan SHA256>",
  "run_id": "<new UUID>",
  "code_revision": "<reviewed deployed Git revision>",
  "mapping_revision": "<reviewed mapping SHA256>",
  "documents": [
    {
      "source": {
        "item_id": "<owning source item>",
        "board_id": "<owning source board>",
        "object_id": "<workdoc object ID>",
        "doc_id": "<internal workdoc ID>",
        "file_id": "<file descriptor ID>",
        "column_id": "<owning file column>"
      },
      "blocks": [
        {"id": "<block ID>", "doc_id": "<internal workdoc ID>", "parent_block_id": null, "type": "normal text"}
      ]
    }
  ]
}
```

Source documents must exactly cover the existing main plan's workdoc occurrences in their original order. Ordered block IDs, types and parents come from the reviewed metadata-only terminal-empty-page inventory. Unknown types, cross-document parents, cycles, duplicates, missing blocks and extra blocks are refused. There are no row values, document names, contents or access tokens in this supplement. Operator preparation must verify its hash against the deployed code and the frozen metadata inventory; no endpoint accepts caller-selected source or target IDs.

`GET /vault/api/imports/monday/workdocs` returns protected progress. One explicit `POST /vault/api/imports/monday/workdocs/next` with only a UUID `request_key` selects one eligible workdoc. Its existing parent Vault import entry must already be committed and pass actual decrypted readback and Ledger verification. The accepted encrypted file-column JSON must contain exactly its `MONDAY_DOC` object/file descriptor. Every source page additionally proves the current typed `FileDocValue` and its internal document ID. Original column values are compared as exact JSON strings.

The existing private encrypted token is used only within the current authenticated administrator's actual email/riddle unlock. Every page is limited to 100 blocks; an empty terminal page and complete frozen coverage are mandatory. The unchanged source transport deadline and 25-second remaining-window guard apply before each call. No source requests run after the fixed grant expires, in a queue, or during SQL transactions. Partial data, account/version changes, changing document metadata or unsupported JSON scalar shapes fail closed.

The frozen inventory proves block IDs, types, parents and returned order. Position was not selected in that inventory: its current finite numeric value is validated and preserved at import, without claiming a frozen position comparison. Document metadata and file-column ownership are checked on every page, including the final empty page. The source API does not provide an atomic document snapshot; the export records the ordered pages retrieved, while the destination acceptance is atomic.

Source document name/settings, each exact escaped JSON content string, pagination order, numeric position, hierarchy, creators and date strings remain inside native Vault encryption. Date-only source dates stay date-only. The adapter does not render source HTML, fabricate timestamps, claim a PDF export, or turn workdocs into uploaded assets. The resulting level-one native note has a generic metadata title and an encrypted structured source export, including its explicit original-parent entry and native item IDs. The native limits remain 60 fields, 100,000 characters per multiline secret and 512 KiB encoded payload; oversize documents stay pending for a separately reviewed encrypted attachment strategy, without truncation.

Migration `001860` creates one metadata-only durable journal. A committed pending reservation survives a disconnected request; a different request key cannot take it over. Native item creation, decrypted equality readback, verified Ledger mapping/receipt and journal activation commit atomically. Identical replay requires that same journal, parent proof, actual decrypted target and verified receipt; edits are conflicts rather than overwrites. No file writes or additional provenance table are needed.

`POST /vault/api/imports/monday/workdocs/{native-item-uuid}/source` returns a truthful structured TXT download. Authentication, current grant, parent and target proofs run again immediately before stream output; expiry or revocation produces no bytes. The export uses an attachment filename unrelated to the source title and no-store headers. Already viewed or downloaded information cannot be recalled.

Schema compatibility was checked without source contents against the pinned 2026-10 metadata contracts: Query.docs IDs/limit/page, Document.blocks(limit,page), Document settings JSON/name/creator/date fields, DocumentBlock content JSON/position Float/parent/creator/date fields, and FileDocValue object/file/doc IDs. The [official block documentation](https://developer.monday.com/api-reference/reference/blocks) describes escaped JSON content and date-only dates; the [official docs reference](https://developer.monday.com/api-reference/reference/docs) documents internal IDs and block pagination. This adapter deliberately uses the proved legacy JSON scalar fields, not the separately typed V2 API.
