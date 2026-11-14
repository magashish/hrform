# Open Questions for the Client

Priority: **P0** blocks a build phase or a production launch. **P1** is needed before UAT. **P2** can be decided later. Until an answer arrives, the portal uses the "Interim default" shown, and every default is configurable.

| # | Pri | Question | Interim default | Blocks |
|---|---|---|---|---|
| 0 | ✅ | ~~Framework version.~~ **Resolved 2026-11-03:** Laravel 12 (`^12.70.2`). 11.57.1 was rejected because of 5 unfixed security advisories. No Node.js toolchain. | — | — |
| 1 | P0 | Complete inventory of onboarding forms, with the current official/sample PDFs for each (I-9, SSA-89, W-4, state withholding, direct deposit, handbook acknowledgement, etc.). | Generic template engine; I-9 and SSA-89 templates seeded with no PDF attached | Phase D content |
| 2 | P0 | For each **remaining** form: online vs. print/upload; fields, validation and required status; supporting uploads; file types and size; whether answers should pre-fill the PDF. **I-9 and SSA-89 are now decided (see #20–#24).** | Upload-based; PDF/JPEG/PNG, 10 MB | Remaining forms (client: "first of next week") |
| 3 | P0 | Final disclosure/consent wording (after HR consultant review). Must applicants re-acknowledge when the wording changes mid-onboarding? | Placeholder marked DRAFT; re-acknowledgement required on new version | Production |
| 4 | ✅ | ~~E-signature approval for I-9.~~ **Resolved Nov 2026:** no system meeting the DHS/consultant requirements is in use, so I-9 is printed and signed by hand. E-sign stays disabled (still needed per form for any future e-sign). | — | — |
| 5 | ✅ | ~~SSA-89 wet signature.~~ **Confirmed Nov 2026** by the HR consultant firm: handwritten signature required; use the form as SSA issues it. | — | — |
| 6 | P1 | Final filename pattern, date format (e.g. `YYYYMMDD`), timezone, and ZIP folder structure. | `{Last}_{First}_{DocType}_{YYYYMMDD}`; UTC; `{Last}_{First}_Packet_{date}/{nn}_{DocCode}/` | — |
| 7 | P0 | Applicant identity verification: is invitation code + email OTP acceptable, given that both go to the same mailbox? Or do you want an additional out-of-band check (PIN given by HR by phone, or matching a date of birth HR entered)? Is the code reusable until expiry (each login requiring a new OTP), or strictly single-use with OTP-only return logins? | Code reusable until expiry + fresh email OTP on every login | Phase C |
| 8 | P1 | Reminder timing, frequency and wording; reminder recipients (applicant only, or HR too); should HR get each event immediately or as a daily digest? | Applicant reminders 3 days and 1 day before expiry, incomplete reminder every 3 days; HR emails sent immediately to all HR users | Phase F |
| 9 | P1 | Review model: per-document approval, packet-level approval, or both? Should HR keep internal notes? Do you want a fixed list of correction reasons? | Per-document approve/correct; free-text correction message; no internal notes | Phase E |
| 10 | P0 | Retention trigger: when documents are completed, or when HR marks the packet as downloaded and transferred? Duration (≤ 60 days unless approved)? Policy for abandoned or incomplete applications? Is automatic purge wanted, or manual purge after a dry-run report? | Trigger = HR "transferred"; 60 days; abandoned policy off; auto-purge off (dry-run reports only) | Production |
| 11 | P0 | Backup retention period, audit-log retention period, legal-hold process (who may set or clear a hold), and deletion requirements for backups. | Audit log 1 year (pseudonymous); legal hold settable by any HR user, audited; backup retention not set | Production |
| 12 | P0 | Hosting provider/region, PHP and database versions, file storage (local disk vs. S3-compatible bucket with encryption), mail provider, queue/cron availability, and whether TLS is terminated in front of the app. | PostgreSQL; private local disk in dev; `database` queue | Phase B deploy |
| 13 | P1 | Is malware scanning available or required (e.g. ClamAV on the host, or a cloud scanning service)? | Strict file-type validation only; marked "not scanned" | Production risk sign-off |
| 14 | P2 | Is a future e-sign provider (DocuSign, Adobe Acrobat Sign) or HRIS integration planned? Who holds the licences? | Neither; interface stub only | — |
| 15 | P1 | HR account management: may any HR user create/disable other HR users in the app (all users have equal permissions), or should only the technical operator do it via the command line? | **Implemented:** any HR user, with password re-confirmation, audited; cannot disable self. Can be restricted later. | — |
| 16 | P1 | Should IP address and browser user-agent be recorded on consent and audit records? | Yes, as security metadata | Phase B |
| 17 | P1 | Should HR be able to see full SSNs on screen, or only masked SSNs plus the downloaded document? | Masked; audited "reveal" with password confirmation | Phase E |
| 18 | P2 | Should superseded (corrected) document versions be deleted immediately, or kept until the packet's retention purge? | Kept until purge | — |
| 19 | P1 | Does the employer use E-Verify or remote document examination for I-9 Section 2? This affects whether ID document copies should be collected through the portal at all. | Portal handles only applicant-side uploads that you configure; Section 2 is out of scope | Form config |
| 20 | P0 | **I-9 timing.** The client says the I-9 "is obtained on day 1 of hire", and Section 1 may not be completed before the job offer is accepted. Should the I-9 be in the portal packet at all? If so, sent only after the offer is accepted, or completed in person on day 1 instead? | I-9 template is active and pre-selected for new applicants; instructions state the timing rule. HR can untick it per applicant. | Packet content |
| 21 | P1 | **I-9 Section 2 / employer signature.** Section 2 is completed and hand-signed by the employer within 3 business days after physically examining documents. Should HR upload the final I-9 (Sections 1 + 2) into the portal packet, or is that handled only in the HR system? | Out of portal scope; the portal collects the applicant's hand-signed Section 1 only. | — |
| 22 | P1 | **SSA-89 reason checkbox.** Should "To apply for a job" be pre-ticked on the pre-filled SSA-89, or left for the applicant to tick by hand? | Left blank for the applicant (it is part of their consent). Pre-ticking is a one-line map change. | — |
| 23 | P1 | **SSA-89 validity.** Consent is one-time use and valid 90 days from the date signed. Should HR be reminded to send it to the screening vendor (Accio Data) within 90 days of submission? | No reminder; HR process. | — |
| 24 | P2 | **"Use it exactly as SSA issues it" vs. pre-filling.** The consultant asked for the form to be used as issued; the client confirmed typing name/DOB/SSN onto it is acceptable. Please confirm the consultant agrees (nothing else on the form is changed). | Pre-fill name, DOB and SSN only. | — |

## Notes on implemented defaults

Every interim default above is implemented and configurable (`.env` or HR → Settings). Changing one does not require code changes, except:
- **#2 PDF pre-fill** is now available for flat (unencrypted) PDFs via a per-template pre-fill map (used for SSA-89); encrypted PDFs such as the official I-9 cannot be pre-filled;
- **#4/#14 e-signature provider** (needs a provider adapter implementing `SignatureProvider`);
- **#7 extra out-of-band check** (needs a small addition to the applicant sign-in flow).
