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
| Form I-9 (Section 1) | `wet_signature_upload` | Safe default. The client initially said print and sign manually. The HR consultant's guidance that e-signature *may* be acceptable if the system meets identity, attestation, linkage, tamper-prevention, audit-trail and reproduction requirements has **not** been turned into an approved workflow. | Pending | — | OPEN_QUESTIONS #4 |
| SSA-89 | `wet_signature_upload` | Client stated SSA-89 requires a wet signature. | Pending written confirmation | — | OPEN_QUESTIONS #5 |
| All other forms | To be decided per form | Forms not yet supplied. | — | — | OPEN_QUESTIONS #1, #2 |

## Evidence the portal will keep if `provider_esign` is ever enabled

From the provider, as far as its API supports: envelope/transaction ID, signer identity-verification evidence references, consent and attestation records, timestamps, SHA-256 of the completed document, the provider's audit certificate, final status, and the immutable completed document. These are kept on private storage. A signature image is never the sole evidence. Webhooks are verified by signature, with idempotency enforced.

## Out of scope for the portal

- I-9 Section 2 (employer examination of documents) and any E-Verify or remote-examination procedure.
- Determining legal sufficiency of any signature method.
