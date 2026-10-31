# Nonprofit Programs

Capability `nonprofit:programs` belongs to optional package `awove/nonprofit` 1.0.0, Core `^1.1`, contract 1. Namespace `Awove\Nonprofit\Programs`. Source alias: **עמותה**. Programs runs with all other Nonprofit capabilities and Sales, Finance, Operations, Assets, HR, Marketing and Knowledge disabled or absent. Root module navigation contributes only authorized enabled capabilities; enabling Programs grants no permissions.

## Records and dates

The slice maintains locations and groups with responsible CRM staff, programs with optional start/end dates and current location/group, and a restricted participant roster. Participants have a unique case-insensitive Participant ID, name, active flag, responsible staff, optional current program/location/group and minimal private contact details. An optional explicit shared Contact link reuses an existing identity; it never creates or matches contacts by name. Private email/phone are deliberately participant-specific channels, manually entered and labeled as private. They are not copied from or synchronized to the shared Contact. Independently authorized shared Contact phone/email are shown live in a separate read-only section. A link never grants participant or Contact access.

No birth dates, assessments, safeguarding data or expanded participant case management are collected. Private roster records do not contribute to Core general contact search or contact profile backlinks. Missing access redacts linked names and IDs; ordinary saves preserve restricted historical references rather than silently clearing them. Replacing an inaccessible link is denied. Inactive historical references can be retained unchanged.

Sessions are explicit dated records under a program, with their own name, location/group and notes. Distinct sessions may share a date. There is exactly one attendance record per participant and session, protected by a PostgreSQL unique constraint and parent/child locking. Default stable keys are `present`, `absent`, `excused`; deployment `nonprofit_attendance_statuses` may replace known display labels without rewriting records. Unrecorded attendance is never assumed Absent. Corrections retain basic actor/time/change history. Changing a participant's current program, deactivating or archiving them does not rewrite historical sessions/attendance.

Active/inactive is a reversible list classification for programs, participants, locations and groups; the source specifies no richer lifecycle. Participant assignment is one current program/location/group; this is not an enrollment-period or eligibility engine. Session attendance explicitly selects an authorized active participant; it does not silently enroll them or infer attendance from a group.

## Access

General `nonprofit.programs.view.own` / `view.all` and `edit.own` / `edit.all` scope programs, locations and groups by their responsible CRM staff. Sessions and feedback inherit their program's current access. `create`, `assign`, `archive` and `export` are independent permissions.

The restricted roster has separate `nonprofit.programs.participants.view.own`, `view.all`, `create`, `edit.own`, `edit.all`, `assign`, `archive`, `export` grants. A general program reader cannot discover private participant names, IDs, channels, selectors, counts, history or attendance through program access alone. Attendance requires both program and participant visibility. `nonprofit.programs.attendance.manage` additionally requires editable parent program/session before recording or correcting attendance. Assigning a responsible person does not grant access; follow-up owners must already have current program access.

DTO/history services reauthorize a fresh active actor and current parent scope themselves. Tables, relation selectors, reports, exports, document redirects and reminders all enforce the exact capability and fresh record permissions. Disabled capabilities deny direct routes and background work while retaining stored records and table preferences for re-enablement.

## Feedback, resources and reminders

Feedback records date, program, comments and optional follow-up owner/action/date/completion date. A dated follow-up requires both action and eligible responsible staff. Completion is manually recorded; clearing its completion date is an explicit correction retained in change history. No automation, external communication, recurring task generator or participant assessment is implied.

`program-follow-ups` contributes to Core WorkReminders. Notices store only record/version/recipient identifiers. Delivery, listing and previews freshly verify capability, active recipient, current program access, archive state, assigned owner, due date, completion and version. Rescheduling, completing, archiving or disabling suppresses stale previews and queued deliveries.

Program resource documents use shared HTTPS DocumentLinks. `nonprofit.programs.documents.view` and `manage` are separate from program access; management also needs parent edit. URLs are opened only through a freshly authorized internal redirect, with no-store/no-referrer headers. Raw URLs are absent from DTOs, tables and exports. Archive retains previous document records and denies opening archived resources. No duplicate purchasing or equipment register is introduced.

## Tables, summaries and exports

`/nonprofit/programs` has Programs, Participants, Locations, Groups, Sessions, Attendance and Feedback sections. All record lists use the shared advanced DataTable with search, sorting, filters, column selection/order/widths and saved preferences. Stable table IDs are `nonprofit.programs-<section>`, matching Core's existing table namespace contract.

Attendance summaries are grouped by program and the session's location. They use the same authorized filtered attendance query as the table/export, including advanced search/filter state. Date ranges are inclusive and use actual session dates; source period/program/location summaries count only explicitly recorded statuses. Attendance summaries are omitted entirely without restricted roster access. Counts are scoped records, not installation-wide totals or attendance rates. Historical inactive/archived participants remain counted when their historical attendance is authorized. Archived sessions are available through the explicit Archived view.

Program filters use program start date, sessions/attendance use session date, feedback uses feedback date, and participant/location/group lists use creation date. Follow-ups due is a separate feedback view. Selecting a restricted program/location/group is denied rather than treated as an empty unrestricted filter.

CSV exports use the identical scoped advanced query, shared formula escaping and a preflight limit of 5,000 filtered rows. Only permitted business columns appear; hidden relation IDs and resource URLs do not. Spreadsheet import remains the existing Core contact/company/lead flow; Programs does not implement a separate participant import pipeline.

## Mutation and lifecycle contract

`Records::save($user,$kind,$input,?id)` handles programs/participants/locations/groups. `child($user,$kind,$programId,$input,?id)` handles sessions/feedback. `attend($user,$sessionId,$input,?id)` handles attendance. Root edits include `version`; child writes also include `program_version`, attendance includes `session_version`. Optimistic mismatch returns HTTP409 without overwriting a draft. Child writes lock program before session/child and bump parent versions atomically. No deletes are exposed. Archive retains records, relationships and append-only history and makes that record read-only.

Routes and migration are owned by this slice: `routes/programs.php` and `2026_10_31_001300_create_nonprofit_programs.php`. Migrations run through controlled installation/update commands, never web requests. Destructive down migration is unsupported; restore a tested backup.

Feature acceptance covers isolated PostgreSQL persistence, explicit permissions, private roster/direct DTO redaction, hidden relation preservation, attendance uniqueness/stale requests, historical attendance after reassignment/archive, period/location summaries, scoped CSV safety, document privacy, active actor/capability lifecycle and stale reminders. Guarded desktop/mobile workflows use exact reserved fictional accounts, private evidence/recovery files and strict before/after cleanup. No real person or live messages/integrations are used.

Deferred by source: grant applications, funder reporting, safeguarding casework, participant assessments, donation receipting and external reporting integrations. Donors/pledges and projects are independently enabled sibling capabilities; Programs adds no financial calculations or transactions.
