# Tuandikiane drafts

Tuandikiane is the WhatsApp agreement intake in the payments service (`gopaperless`
repo, `app/services/intake_service.py`). The contract between the two sides is
`docs/tuandikiane-contract.md` in that repo. This runbook covers the GoPaperless half.

Tuandikiane never holds a user's token. It creates a draft here with a server key. A
service account holds the draft, in draft status, so nobody is notified and nobody else
sees it. Tuandikiane sends the sender a review link. The sender opens it, signs in to
GoPaperless the normal way, reads the agreement and taps **Send for signing**. A MIMI
passkey authorises the send. Then GoPaperless:

1. copies the PDF into the sender's LibreSign folder (`tuandikiane-<draft uuid>/`) and
   makes the sender the document's owner, the way a public upload claim does;
2. binds the sender's signer slot to their account, if the sender signs at all;
3. moves the document to able-to-sign without any email or notification;
4. sends every signer the WhatsApp signing card on the number Tuandikiane gave, the
   sender too, so a sender who closes the page can still get back to sign;
5. tells Tuandikiane `sent`, and later `signed` per signer and `completed`.

A signer known only by phone opens the card, signs in, passes the MIMI passkey, and the
slot becomes their account. Then they see the normal card. That is the whole binding:
the link reached their WhatsApp, they signed in, they passed the passkey.

## Routes

| Route | Who calls it |
| --- | --- |
| `POST /ocs/v2.php/apps/libresign/api/v1/tuandikiane/drafts` | Tuandikiane, header `X-Tuandikiane-Key` |
| `GET` and `POST /apps/libresign/p/t/form/{token}` | the sender, from the details form link (public) |
| `GET /apps/libresign/p/t/review/{code}` | the sender, from the review link |
| `GET /apps/libresign/p/t/review/{code}/pdf` | the review page |
| `POST /apps/libresign/p/t/review/{code}/options` | the review page, for the passkey challenge |
| `POST /apps/libresign/api/v1/tuandikiane/review/{code}/send` | the review page, with the passkey answer |
| `GET /apps/libresign/p/t/sign/{code}` | a signer with no account on the slot yet; the card sends them here |
| `POST /apps/libresign/p/t/sign/{code}/options` and `/bind` | that page |

## The details form

When Tuandikiane finds no agreement in the chat, it sends a link to
`/p/t/form/{token}`. The page reads the form from Tuandikiane
(`GET /external/tuandikiane/forms/{token}`, with `whatsapp_api_url` and
`whatsapp_api_key`): first the kinds of agreement as a list, then the fields of the one
picked, filled in from `prefill`. **Continue** posts the answers back as typed. Then:

- 200: the browser goes straight to the review link. Only this instance's
  `/p/t/review/...` is followed; any other address shows the error below.
- 422: each error shows beside its field, the values stay. An error about the whole form
  (key `template`) shows above the fields.
- 502 `draft_failed`, or Tuandikiane down: "Something went wrong on our side. Please try
  again in a few minutes.", values kept.
- 404 or 410: "This link has expired. Send your agreement on WhatsApp again."

The page has no script. The POST carries Nextcloud's request token, so the CSRF check
stays on, and it works in WhatsApp's own browser. The review link it leads to hands off to
Chrome itself.

## Review codes

The review code is `<draft uuid>.<MAC>`, like the WhatsApp card's code. It works until
the draft is sent or expires. After that the page says "This link has expired. Send your
agreement on WhatsApp again."

## Before you start

These must already work on the instance:

- **SecurySign login.** `securysign_provider_id` names the `user_oidc` provider. The
  passkey check needs a SecurySign session.
- **WhatsApp signing requests** (`docs/runbooks/whatsapp-signing-requests.md`):
  `whatsapp_enabled`, `whatsapp_api_url` and `whatsapp_api_key`. The cards go through
  it, and the `sent`, `signed` and `completed` events go to the same address with the
  same partner key, at `/external/tuandikiane/events`. That partner must be allowed to
  call the Tuandikiane endpoints in the payments service.
- **The MIMI passkey**: `mimi_url`, `mimi_client_id` and `mimi_client_secret`. If MIMI
  doesn't answer, Send refuses with "MIMI isn't answering. Try again in a minute." It
  never sends without the passkey, unlike the check after sign-in.
- **Background jobs** on cron. `TuandikianeCleanup` runs hourly.

## Set it up

Run these as the web server user (`-u www-data` in the local container).

Upgrade, so the migration creates `oc_gopaperless_tuandikiane`:

```sh
occ upgrade
```

Create the service account. Nobody signs in as it. Give it a long random password:

```sh
export OC_PASS="$(openssl rand -base64 36)"
occ user:add --password-from-env --display-name="Tuandikiane" tuandikiane
unset OC_PASS
occ config:app:set libresign tuandikiane_service_uid --value=tuandikiane
```

Make the key Tuandikiane sends, and store it as a sensitive value:

```sh
KEY="$(openssl rand -hex 32)"
occ config:app:set libresign tuandikiane_api_key --value="$KEY" --sensitive
```

Give the same value to the payments service as its GoPaperless key for Tuandikiane
(its settings name it). Don't paste it into chat or a ticket.

The service account needs no group. `groups_request_sign` is checked by the request
API's `RequireManager` gate, and drafts don't go through it. Send doesn't check it for
the sender either: anyone who signs in with the review link can send their agreement.

With no key set, the drafts call answers 503. With the wrong key it answers 401, and
Nextcloud's brute-force protection slows that caller down.

## Try it

```sh
curl -sS -X POST "https://staging.gopaperless.dev/ocs/v2.php/apps/libresign/api/v1/tuandikiane/drafts" \
  -H "OCS-APIRequest: true" -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Tuandikiane-Key: $KEY" \
  -d @draft.json
```

`draft.json` is the body from the contract. The answer's `ocs.data` holds `draft_uuid`,
`review_url` and `expires_at`. Sending the same `reference` again returns the same
draft, unless that one expired unsent: then it is deleted and made again.

A body that breaks the contract answers 422 with a `message` naming the field, such as
`signers[1].phone must be E.164, like +254712345678`. Every signer but the sender needs a
Kenyan mobile number, because their link goes out on WhatsApp.

## What gets stored

One row per draft in `oc_gopaperless_tuandikiane`: `draft_uuid`, `reference`
(unique), `file_id`, `status` (`draft` or `sent`), `expires_at`, `sent_at`, and `slots`
as JSON. Each slot holds its key (`s1`, ...), phone, whether it is the sender's, whether
it came with a name, its sign request id, the account that took it, and whether its
card went.

Until someone takes a slot it is an email signer. With no email from Tuandikiane the
address is `tuandikiane-<draft uuid>-<key>@placeholder.securesign.local`, never the
phone number. A slot with no name reads "Signer 1", "Signer 2" until it is taken, then
the account's display name.

## Cleaning up

`TuandikianeCleanup` deletes drafts nobody sent before `expires_at`: the sign requests,
boxes and file row, the PDF folder, then the row. Sent drafts belong to their senders
and stay.

Deleted folders go to the service account's trash bin, like any Nextcloud delete. Empty
it now and then, or let `trashbin_retention_obligation` do it:

```sh
occ trashbin:cleanup tuandikiane
```

## Known limits

- The payments service only sends cards to Kenyan mobile numbers, so the drafts call
  refuses any other number for a signer who isn't the sender. A sender abroad still gets
  **Sign now** on the review page, but their own card fails (`bad_number` in the log).
- With the `terms_of_service` app on, a user who hasn't accepted the terms can't read any
  file, the draft's PDF included. The review page then shows **Open the agreement** in
  place of the pages, and that link fails too until they accept. Users accept at first
  sign-in, so it shows only for someone who never got past that dialog.
- The payments service needs a `sub` with every card. A slot nobody holds yet has none,
  so GoPaperless sends `tuandikiane:<draft uuid>:<key>` in its place.
- In the ordered signing flow, the next signer's card goes out after the signature
  before theirs. A card that failed to go is tried again at the next signature.
