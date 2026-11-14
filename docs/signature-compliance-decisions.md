# Signature Compliance Decisions

This is a decision record, **not legal advice**. Changes to signature modes require written client approval, and for regulated forms approval from the client's qualified HR/legal adviser. Each approval is also captured in the application's `signature_mode_approvals` table.

## Supported modes (per document template)

| Mode | Meaning |
|---|---|
| `none` | No signature (e.g. upload-only or informational items). |
| `wet_signature_upload` | Applicant downloads/prints the form, signs by hand, scans or photographs it, and uploads it. **Default for regulated forms.** |
| `provider_esign` | Signed through a third-party e-signature provider behind the `SignatureProvider` interface. **Unavailable** until a provider is selected, configured and validated, and an approval record exists for that template. |

The portal will **not** include an in-house drawn or typed signature pad presented as a legally valid signature.

## Current decisions

| Document | Mode | Basis | Approved by | Date | Reference |
|---|---|---|---|---|---|
| Form I-9 (Section 1) | `wet_signature_upload` | USCIS: the fillable I-9 must be printed and signed by hand because it does not meet DHS standards for electronic generation, storage and retention (8 CFR 274a.2). HR consultant: e-signature is allowed only with a system that verifies identity, captures attestations, links the signature to the form, prevents changes, keeps an audit trail and stores/reproduces records. The client uses no such system, so **print and sign by hand** (employee and employer). Applicants may type Section 1 answers into the official fillable PDF before printing, but never into the signature box. | Client, relaying HR consultant firm guidance | Nov 2026 | Client emails "Form I-9 / digital signatures" and "update for these 2 forms" |
| SSA-89 | `wet_signature_upload` | HR consultant: Social Security's verification program requires a handwritten signature and won't accept electronic ones; use the form exactly as SSA issues it. Client: the applicant's name, DOB and SSN may be filled in on the client's copy (company and agent details already completed), then printed, signed by hand and uploaded. | Client, relaying HR consultant firm guidance | Nov 2026 | Same emails; client's SSA-89 (05-2025) with company details |
| All other forms | To be decided per form | Forms not yet supplied. | — | — | OPEN_QUESTIONS #1, #2 |

## Evidence the portal will keep if `provider_esign` is ever enabled

From the provider, as far as its API supports: envelope/transaction ID, signer identity-verification evidence references, consent and attestation records, timestamps, SHA-256 of the completed document, the provider's audit certificate, final status, and the immutable completed document. These are kept on private storage. A signature image is never the sole evidence. Webhooks are verified by signature, with idempotency enforced.

## Out of scope for the portal

- I-9 Section 2 (employer examination of documents) and any E-Verify or remote-examination procedure.
- Determining legal sufficiency of any signature method.

## How this is enforced in the application

- Each template has a `signature_mode`. `DefaultTemplatesSeeder` creates I-9 and SSA-89 as `wet_signature_upload`.
- Selecting `provider_esign` is rejected unless **both** of these hold:
  1. a provider is configured and available (`ESIGN_PROVIDER`; only `disabled` exists for production today);
  2. a `signature_mode_approvals` record for that template and mode exists. It names who authorized it, a reference to the written approval, the date, and who recorded it (HR → Documents → template → *Signature-mode approvals*).
- Each applicant's document keeps a snapshot of the signature mode at assignment, so changing a template later does not alter documents already in progress.
- Wet-signature documents show explicit hand-signing instructions and a link to the official blank PDF that HR uploaded.
- `App\Services\Signatures\SignatureProvider` defines the integration boundary: envelope creation, webhook verification before parsing, and evidence artifacts. The webhook endpoint returns 404 while the provider is disabled, verifies HMAC signatures with timestamp tolerance, and ignores duplicate event IDs.

## Pre-filling and signatures

- **SSA-89:** the portal types only the printed name, date of birth and SSN onto the client's copy (template *pre-fill map*). The signature, date signed, the reason for consent and the optional consent-period initials are left for the applicant to complete **by hand**. The pre-filled PDF is generated when downloaded and is never stored.
- **I-9:** the official USCIS PDF is encrypted and is not pre-filled by the portal. Applicants can type into its fillable fields on their own computer before printing. The instructions tell them not to type anything in the "Signature of Employee" box.
- **HR review:** approving any wet-signature document requires the reviewer to confirm the upload carries a handwritten ink signature and includes all required pages (`document.approved` audit event records `handwritten_signature_checked`).
- **Form editions:** each template records the official edition and expiry date (I-9: edition 01/20/25, expires 05/31/2027). HR is warned on the dashboard, on the System page and by a weekly email from 90 days before expiry, so the new USCIS revision is uploaded as soon as it is published.
