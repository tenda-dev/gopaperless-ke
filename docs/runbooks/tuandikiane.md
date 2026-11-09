---
title: "Tuandikiane: Seal & Deliver for WhatsApp agreements"
type: runbook
status: draft
domain: product
layer: runbooks
owner: engineering
date: 2026-11-09
commit: "1f5ca739a"
---

<!--
 - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Tuandikiane drafts

Tuandikiane is the WhatsApp agreement intake in the payments service (`gopaperless`
repo, `app/services/intake_service.py`). The contract between the two sides is
`docs/tuandikiane-contract.md` in that repo. This runbook covers the GoPaperless half.

Tuandikiane never holds a user's token, and nobody signs in to GoPaperless. Tuandikiane
names everyone by MIMI account: the **moderator**, who wrote the agreement on WhatsApp,
and each signer whose number paid MIMI. GoPaperless asks MIMI for each account
(`SecurySignService::accountFor`) and makes the GoPaperless account a first login would
make if there isn't one, with the certificate and drawn signature MIMI holds. The draft
is the moderator's own document, in draft status, so nobody is notified.

The moderator gets **Seal & Deliver** on WhatsApp and opens it:

1. The page shows their address, the document, the signers (and who still has to join
   MIMI), and **Pay for signers**, on by default, with what their credits cover.
2. Only when the credits fall short does it show a payment: M-Pesa on the moderator's
   own number, Airtel Money, or a card on DPO's page.
3. **Seal**. A moderator who doesn't sign confirms with their MIMI passkey, and every
   ready signer gets the WhatsApp card at once. A moderator who signs goes to their own
   card instead: their signature is the seal, and the others hear only after it.
4. With Pay for signers on, the moderator's credits are reserved for every signer
   (requester sponsorship), so the signers' cards say "Paid for by".
5. A signer without MIMI (a **joiner**) gets an invite from Tuandikiane. It leads
   through MIMI, where they pay, and back. When Tuandikiane hears their payment landed, it
   calls `join` and their slot becomes their account. Then their card goes.

Ready signers never wait for joiners: everyone has signing order 1.

## Routes

| Route | Who calls it |
| --- | --- |
| `POST /ocs/v2.php/apps/libresign/api/v1/tuandikiane/drafts` | Tuandikiane, header `X-Tuandikiane-Key` |
| `POST /ocs/v2.php/apps/libresign/api/v1/tuandikiane/drafts/{uuid}/join` | Tuandikiane, when a joiner's MIMI payment lands |
| `GET` and `POST /apps/libresign/p/t/form/{token}` | the moderator, from the details form link (public) |
| `GET /apps/libresign/p/t/review/{code}` | the moderator, from Seal & Deliver |
| `GET /apps/libresign/p/t/review/{code}/pdf` | that page |
| `POST /apps/libresign/p/t/review/{code}/options` | that page, for the MIMI passkey challenge |
| `POST /apps/libresign/p/t/review/{code}/pay`, `GET .../pay/{reference}` | that page, when the credits fall short |
| `POST /apps/libresign/p/t/review/{code}/seal` | that page, with the passkey answer |
| `GET /apps/libresign/p/t/join/{code}` | a joiner, from Tuandikiane's invite |
| `POST /apps/libresign/p/whatsapp/{code}/pay`, `GET .../pay/{reference}` | the signing card, for a signer with no credit |
| `GET /apps/libresign/p/whatsapp/{code}/signature` | the signing card's preview of the signature it places |

Every page route is public. The link is what admits: the code is the draft or sign
request with a MAC over it.

## The details form

When Tuandikiane finds no agreement in the chat, it sends a link to
`/p/t/form/{token}`. The page reads the form from Tuandikiane
(`GET /external/tuandikiane/forms/{token}`, with `whatsapp_api_url` and
`whatsapp_api_key`): first the kinds of agreement as a list, then the fields of the one
picked, filled in from `prefill`. **Continue** posts the answers back as typed. Then:

- 200: the browser goes straight to Seal & Deliver. Only this instance's
  `/p/t/review/...` is followed; any other address shows the error below.
- 422: each error shows beside its field, the values stay. An error about the whole form
  (key `template`) shows above the fields.
- 502 `draft_failed`, or Tuandikiane down: "Something went wrong on our side. Please try
  again in a few minutes.", values kept.
- 404 or 410: "This link has expired. Send your agreement on WhatsApp again."

## Codes

The Seal & Deliver code is `<draft uuid>.<MAC>`, like the WhatsApp card's code. Before
sealing it opens Seal & Deliver; after, the done screen (or, while a moderator who signs
hasn't, their card). An unsealed draft past its expiry shows "This link has expired."

An invite code is `<draft uuid>.<key>.<MAC>`. It works once the draft is sealed: to MIMI
first, then, coming back, a page that waits until the slot is theirs and opens their card.

## Before you start

These must already work on the instance:

- **SecurySign login.** `securysign_provider_id` names the `user_oidc` provider. Accounts
  made for MIMI users are made in that provider, as a first login would make them, uid
  and address both MIMI's `email`.
- **MIMI's partner calls**: `mimi_url`, `mimi_client_id` and `mimi_client_secret`, and on
  MIMI, this client id in `MIMI_PERSON_PARTNERS`. Without it MIMI answers 403 to the
  person call and every draft call answers 503. If MIMI doesn't answer, Seal refuses with
  "MIMI isn't answering. Try again in a minute."
- **WhatsApp signing requests** (`docs/runbooks/whatsapp-signing-requests.md`):
  `whatsapp_enabled`, `whatsapp_api_url` and `whatsapp_api_key`. The cards go through
  it, and the `sent`, `signed` and `completed` events go to the same address with the
  same partner key, at `/external/tuandikiane/events`.
- **Credits**: `sponsorship_enabled`. Off, the page shows no money at all and nobody pays.
  The price is `SIGN_DOCUMENT` in `oc_gopaperless_products`.
- **Payments**: the Daraja and DPO settings the app's own payment step uses.
- **Background jobs** on cron. `TuandikianeCleanup` runs hourly.

## Set it up

Run these as the web server user (`-u www-data` in the local container).

```sh
occ upgrade
KEY="$(openssl rand -hex 32)"
occ config:app:set libresign tuandikiane_api_key --value="$KEY" --sensitive
```

Give the same value to the payments service as its GoPaperless key for Tuandikiane
(`TUANDIKIANE_GOPAPERLESS_API_KEY`). Don't paste it into chat or a ticket.

An instance that ran the first version has a `tuandikiane` service account and a
`tuandikiane_service_uid` setting. Neither is used now. Delete them once no draft it holds
is still waiting:

```sh
occ config:app:delete libresign tuandikiane_service_uid
occ user:delete tuandikiane
```

With no key set, the drafts call answers 503. With the wrong key it answers 401, and
Nextcloud's brute-force protection slows that caller down.

## Try it

```sh
curl -sS -X POST "https://staging.gopaperless.dev/ocs/v2.php/apps/libresign/api/v1/tuandikiane/drafts" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Tuandikiane-Key: $KEY" \
  -d @draft.json
```

`draft.json` is the body from the contract, with `moderator.sub` a MIMI account. The
answer's `ocs.data` holds `draft_uuid`, `review_url` (Seal & Deliver) and `expires_at`.
Sending the same `reference` again returns the same draft, unless that one expired
unsealed: then it is deleted and made again.

A body that breaks the contract answers 422 with a `message` naming the field, such as
`signers[1].phone must be E.164, like +254712345678`. A moderator MIMI doesn't know
answers 422 `moderator.sub has no MIMI account GoPaperless can use`.

## What gets stored

One row per draft in `oc_gopaperless_tuandikiane`: `draft_uuid`, `reference`
(unique), `file_id`, `status`, `expires_at`, `sent_at`, and `slots` as JSON. Status is
`draft`, `sealed` (waiting for the moderator's own signature) or `sent`. Each slot holds
its key (`s1`, ...), phone, whether it is the moderator's, whether it came with a name,
its sign request id, its account (`null` for a joiner), and whether its card went.

A joiner's slot is an email signer with the address
`tuandikiane-<draft uuid>-<key>@placeholder.securesign.local`, never the phone number,
until their account takes it in place. Its sponsorship, if any, stays.

## Cleaning up

`TuandikianeCleanup` deletes drafts nobody sealed before `expires_at`: the sign
requests, boxes and file row, the PDF folder in the moderator's files, then the row.
Sealed drafts are the moderator's documents and stay.

## Known limits

- **The Seal & Deliver link is a bearer capability**, sent only to the moderator's
  WhatsApp. Without the moderator's MIMI passkey it can't seal, but it can start an
  M-Pesa prompt to the moderator's number (rate limited to six a minute) and, for a
  moderator who signs, seal without delivering: nobody hears until their signature.
- A joiner is matched by the number MIMI charged. One who pays MIMI from another number,
  or already had MIMI under another number, waits on the invite page and is never added.
- MIMI keeps a certificate copy from the day it shipped (2026-11-09) and on each visit.
  Someone whose certificate MIMI hasn't copied yet gets "Sign in to GoPaperless once" on
  the card until they open MIMI or sign in here.
- The signature preview shows above SecurySign's frame. Inside the frame needs an image
  parameter on SecurySign's side.
- The payments service only sends cards to Kenyan mobile numbers, so the drafts call
  refuses any other number for a signer who isn't the moderator.
