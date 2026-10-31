# Lead-to-sale workflow

This records the requested business flow and the boundaries between the CRM changes in this release and the integrations still to connect. Credentials, business telephone numbers, customer documents and installation settings stay in private deployment storage.

## Module ownership

**Marketing module:** acquisition/referral and campaign/ad/page attribution, advertising budgets/spend, both marketing dashboards, qualification/nurture WhatsApp, and marketing AI drafts. Clearly flag these requests as Marketing module work before starting. Reuse generic core records and shared integrations through module contracts.

**CRM core:** contact/company records, lead ownership/stage/creation date, notes, follow-up tasks and private proposal files. **Transactional sales operations:** meeting/calendar reminders, proposal responses, contracts/signatures, payment approval, bank confirmation, receipts and onboarding. A WhatsApp message is classified by its purpose rather than its transport.

The local generic Checkpoint 4 now owns attribution in optional Marketing storage. The original Core column is frozen and hidden, with immutable exact snapshots retained by the additive extraction. This work has not been pushed or remotely deployed. The client-specific integrations below remain separate future scope; they are not part of the generic manual-entry build.

## Available in this release

- Change a lead's stage directly from its table bubble or profile. Changes enforce current permissions and optimistic versions and retain stage history.
- See the lead's creation date on the profile and in the table. Saved personal table layouts remain intact; add Created and Proposal file through Columns when using an existing layout.
- Upload private PDF, DOC or DOCX proposal versions up to 10 MB from the lead profile. The latest file is available through the Proposal file table column; earlier versions remain on the profile. Access follows the lead's permissions.
- With Marketing enabled, its attribution grants and current Lead access, capture/search media/platform, originating page, campaign/ad IDs/names, named referrer and UTMs through Marketing contributions. Generic source remains Core/Sales. These are manually entered fields; no intake integration is connected.

These features do not send WhatsApp messages, share proposals publicly, issue financial documents or connect an external account.

## Requested journey

1. Website, campaign, social form or referral creates a lead and records the exact origin.
2. Send one immediate WhatsApp welcome, invite the lead to answer qualification questions and offer a discovery booking link. This welcome may run at any hour.
3. Store replies, questions, bookings and meeting reminders on the lead. Import the meeting recorder's recording/transcript and summary; generate editable post and proposal drafts.
4. Review the proposal before sharing it. The recipient can choose Approved or Have questions. Record the response and notify the owner; questions return the proposal to review.
5. Prepare the contract, send it through FillFaster and track every required signer. Keep the final signed document and audit information privately in the CRM.
6. Only when every required party has signed, create one payment-request draft for owner approval. Use Morning's document preview while it is still a CRM draft. Issue the selected Morning document only after explicit approval.
7. The owner checks the bank and records confirmation of received funds. The owner then issues the appropriate tax invoice/receipt; contract signature or an approved payment request does not imply payment.
8. After confirmed payment, prepare the WhatsApp invitation to book the first 90-minute meeting. Link that event and its reminders to the same record.

## Follow-up assistant

A due follow-up prepares a draft, for example: “Yael has not replied. Here is a proposed WhatsApp message. Approve and send?” The owner may edit and approve the exact text, reject it and choose Remind me later, or reject it and Stop reminders. Rejection must not silently create another reminder.

Follow-up sends are restricted to Sunday–Thursday, 09:00 inclusive to 15:00 exclusive. Proposed business timezone: `Asia/Jerusalem`, pending confirmation; store it independently of user display preferences. Recheck the window at dispatch, including after an approval or retry. Outside-window approvals wait until the next permitted time. The immediate welcome is a separate rule and may run any time.

Approval binds to the draft version, recipient, sender, relevant lead/task state and expiry. A new reply, opt-out, completed task, changed ownership or changed draft invalidates stale approval. Double clicks, job retries and duplicate callbacks must not duplicate a message. Store the approved content and provider delivery status in the lead timeline.

WhatsApp business policy requires appropriate consent and an approved template outside the customer-service window. Owner approval alone does not make an arbitrary AI-written message deliverable. Show the applicable approved template or the reason sending is unavailable. [WhatsApp Business policy](https://business.whatsapp.com/policy)

## Integration readiness

| Connection | Setup still required | Completion evidence |
| --- | --- | --- |
| Website/forms | Actual sites/form platform; field mapping; signed inbound webhook; opt-in text | Replayed and concurrent callbacks create one lead with the page URL and original submission time |
| Social/campaign intake | Selected platforms, ad account/Page/form access, webhook subscriptions | Campaign/ad/form IDs and name snapshots match the provider; organic/referral leads do not receive invented ad IDs |
| WhatsApp | Register/connect the designated number to a chosen WhatsApp Business provider; credentials, callback verification and approved templates | One opted-in test welcome; inbound reply and delivery events attach to the correct lead |
| Calendar/booking | Provider/account, selected calendar, discovery duration, booking service, availability and reminder offsets | Booking/reschedule/cancellation are idempotent; reminders follow current event; kickoff lasts 90 minutes |
| “Timeless” recorder | Exact product URL/account and its API/export capabilities | Authenticated transcript import, recording link and summary mapped to the correct meeting; no guessed vendor |
| AI drafting | Chosen model/provider credentials and approved language/tone/templates | Drafts use CRM context and remain reviewable; no send/issue action is performed by generating text |
| FillFaster | API token, contract template, signer-role mapping and webhook setup | Only verified overall completion triggers the payment draft; final PDF and signer evidence retained |
| Morning | Sandbox/production credentials, business/document type, currency, tax and client mapping | Preview remains unissued; owner approval creates one external document and stores its ID/PDF |

FillFaster exposes signer states and an overall `Submitted` status only once the last signer finishes. A `recipient_completed` event is insufficient to advance the payment step. Authenticate and reconcile the submission state before accepting completion. [FillFaster OpenAPI](https://help.fillfaster.com/fillfaster-openapi.json)

Morning's documented outgoing API has `POST /documents/preview` and `POST /documents`; the latter generates the document. Its draft endpoints concern incoming expenses, not an outgoing payment-request draft. Hold the draft in the CRM and call the issuing endpoint only after approval. Its current authentication uses client credentials; keep tokens private. [Morning API reference](https://developers.morning.co/api), [official OpenAPI](https://developers.morning.co/docs/openapi.bundled.json)

Calendar callbacks can be missed; schedule reconciliation alongside event notifications. Confirm the actual provider before implementing its adapter. [Google Calendar notification behavior](https://developers.google.com/workspace/calendar/api/guides/push)

## Attribution and reporting definitions

Preserve stable provider IDs and name snapshots, original submission time, CRM receipt time, landing page/UTMs and a named referrer. Keep original acquisition attribution separate from later touches. A referral is a source category, not a social platform. Intake retries must retain the original lead and source rather than replacing them.

### Monthly acquisition report

Group by acquisition month in the configured business timezone, source category and platform. Include advertising budget and actual spend as separate measures, lead count, won-deal count, closed deal value and bank-confirmed income. Use the following calculations:

| Measure | Calculation |
| --- | --- |
| Cost per lead | Actual attributed spend / acquired leads |
| Cost per closing | Actual attributed spend / won deals from that acquisition cohort |
| Closing percentage | Won deals from that acquisition cohort / acquired leads × 100 |
| Closing income | Bank-confirmed receipts linked to those deals, with booked value shown separately |

Keep a separate closing-month view for sales made in that month. Acquisition-cohort conversion and closing-month sales answer different questions. Define actual spend and currency explicitly; do not combine currencies without a recorded exchange-rate basis. A zero denominator produces an unavailable value, not a misleading zero rate.

### Monthly ad report

Include ad ID/name, month, platform/campaign, impressions, landing-page link clicks, actual landing-page views, leads, reach, spend and cost per lead. Label derived rates precisely:

- Click-through rate: landing-page link clicks / impressions × 100.
- Page-load rate: landing-page views / landing-page link clicks × 100.
- Lead conversion: leads / landing-page views × 100.
- A page CTA-click rate requires separately measured CTA clicks; confirm whether this is the requested “entered the landing page till click” measure.

CRM lead records do not supply impressions, spend or visits. Import those through ad/analytics APIs and first-party events. Reach is deduplicated: request an aggregate for the reporting period rather than adding daily reach. Leave missing metrics unavailable instead of inventing values. [Meta lead fields](https://raw.githubusercontent.com/facebook/facebook-python-business-sdk/main/facebook_business/adobjects/lead.py), [Meta insights fields](https://raw.githubusercontent.com/facebook/facebook-python-business-sdk/main/facebook_business/adobjects/adsinsights.py)

## Delivery and acceptance

1. CRM essentials: current release. Verify keyboard/mobile stage controls, stale-save handling, attribution validation and authorized file upload/download.
2. Intake and WhatsApp: connect the selected accounts and test deduplication, attribution, opt-in, welcome and inbound conversations before enabling real automatic welcomes.
3. Approval assistant and calendar: test working hours, Israel DST boundaries, stale approvals, rejection choices, repeated sends, cancelled/rescheduled meetings and 90-minute kickoff bookings.
4. Proposals and contracts: implement scoped expiring recipient response links, record questions/approval, notify owner and verify all-party FillFaster completion.
5. Finance: keep the Morning preview unissued until approval; test duplicate completion callbacks and manual bank confirmation before receipt/kickoff.
6. Reporting: reconcile spend and lead totals to provider exports; test cohort/month boundaries, reach aggregation, missing values, zero denominators and currency handling.

Each external adapter needs authenticated callbacks, replay protection, durable event receipt, idempotency keys, retry/error visibility and a reconciliation path. Store provider secrets in private installation configuration. Keep document versions, approvals, conversations, contracts and payment references attached to the lead; retain human review gates throughout the requested workflow.
