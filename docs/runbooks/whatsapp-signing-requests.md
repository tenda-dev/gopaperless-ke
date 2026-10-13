# WhatsApp signing requests

The signer row in the request sidebar has three actions: **Request on WhatsApp**
(or **Send on WhatsApp** once requested), **Copy link** and **Show QR**. WhatsApp
only carries the signing link. The signer stays an account signer, so SecurySign
signs with their own certificate. Spec: "GoPaperless fast signing: implementation
spec" (Claude Doc, 2026-10-06).

GoPaperless never holds a full phone number. It names the signer by their OIDC
`sub` and the payments service (`gopaperless` repo, `/external/whatsapp/*`) looks
up the number MIMI collected at payment and sends. The row shows it masked.

## Who qualifies

An account from the SecurySign `user_oidc` provider, with a `sub` GoPaperless
knows and a WhatsApp number on file, on a single document (no envelopes). The
`sub` is remembered on every SecurySign login while the setting is on (user
preference `libresign/securysign_sub`), and read from `oc_user_oidc_sessions` the
first time for anyone who has not logged in since. One WhatsApp request per
signer per 24 hours; a failed send does not count.

## Settings

GoPaperless: **Administration settings → LibreSign → WhatsApp signing requests**.
Fill in the payments service address and its partner API key, turn on **Send
signing requests on WhatsApp**, and save. The switch stays greyed out until a
SecurySign login provider, the address and the key are all set.

While it is off, GoPaperless behaves as if this feature were not installed:

- No WhatsApp, Copy link or Show QR on the signer row.
- A signing link is not redirected to SecurySign setup or the MIMI passkey.
- Signed-out signers get the normal login page.
- Signing in as the wrong account shows "Invalid user".
- No receipt is sent, and the `sub` is not remembered at login.

The same settings from the command line:

```sh
occ config:app:set libresign whatsapp_api_url --value https://gopaperless.astralyngroup.com
occ config:app:set libresign whatsapp_api_key --value '<partner key>' --sensitive
occ config:app:set libresign whatsapp_enabled --value 1 --type boolean
```

Payments service (`.env`):

| Setting | Value |
| --- | --- |
| `PARTNER_API_KEYS` | include `gopaperless:<partner key>` |
| `WHATSAPP_SIGN_PARTNERS` | `gopaperless` (the default) |
| `WHATSAPP_TEMPLATE_SIGN_REQUEST` | Content SID of the request template |
| `WHATSAPP_TEMPLATE_SIGN_RECEIPT` | Content SID of the receipt template |
| `SIGN_LINK_BASE` | only for the free-text fallback below |

## Templates to submit to Meta

Both English, category Utility, on the GoPaperless WhatsApp sender. Twilio numbers
variables across body and button together, and a body may not start or end with a
variable, hence the "Hi,".

| Template | Body | Button |
| --- | --- | --- |
| Request: `gopaperless_sign_request`, `HX6d1d3bf0059a9691c779e5561c9941c3`, approved as Utility 2026-10-07 | `Hi, {{1}} has sent you "{{2}}" to sign on GoPaperless.` | URL "Review and sign": `https://gopaperless.ke/apps/libresign/p/sign/{{3}}` |
| Receipt: the already approved `document_signed_successfully`, `HX1da175d1f4390bf449864a061a5ad7ec` | `✅ Document signed successfully! 📄 {{1}} View your signed document:` | URL "View Document": `https://gopaperless.ke/apps/libresign/f/validation/{{2}}`, which accepts a sign-request uuid |

Read the live sign URL before submitting: the approved base is fixed, and it has
to match whether production serves `/index.php`.

With a template SID unset, that message goes out as free text with a link from
`SIGN_LINK_BASE`. WhatsApp only delivers free text to someone who messaged the
number in the last 24 hours, so that is for testing.

## The signing link

`/p/sign/<uuid>` for a signed-out signer whose account came from the SecurySign
login goes straight to `/apps/user_oidc/login/<provider>` instead of the login
page. Signed in as the wrong account, they see "This document was sent to a
different account" and **Switch account**, which signs out through user_oidc's
`/sls` (ending the SecurySign session too) and leaves a `libresign_resume`
cookie that `src/login.ts` uses to come straight back.

A signing link goes through the MIMI passkey check like every other page, and the
sign API refuses until it is done. Then SecurySign's frame asks for the passkey
again to sign. The spec's one-tap version skipped the MIMI check; the product
owner made it mandatory on 2026-10-07.

## Local testing

The payments service runs from its worktree against a throwaway Postgres and
Redis:

```sh
docker start fast-signing-pg fast-signing-redis
cd TENDAWORLD/gopaperless-fast-signing
../gopaperless/venv/Scripts/python.exe -m uvicorn app.main:app --host 0.0.0.0 --port 8041
PYTHONPATH=. ../gopaperless/venv/Scripts/python.exe scripts/dev_seed_whatsapp_number.py --sub <signer sub> --number 2547XXXXXXXX
```

Local Nextcloud reaches it at `http://host.docker.internal:8041`. Message the
GoPaperless WhatsApp number from the signer's phone first, so the free text is
delivered. A phone cannot open `http://localhost`; open the link on this machine,
or put the stack behind a tunnel to try it on the phone.
