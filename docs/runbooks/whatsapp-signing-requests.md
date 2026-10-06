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
`sub` is remembered on every SecurySign login (user preference
`libresign/securysign_sub`), and read from `oc_user_oidc_sessions` the first time
for anyone who has not logged in since this shipped. One WhatsApp request per
signer per 24 hours; a failed send does not count.

## Settings

GoPaperless (app `libresign`):

```sh
occ config:app:set libresign whatsapp_enabled --value 1 --type boolean
occ config:app:set libresign whatsapp_api_url --value https://gopaperless.astralyngroup.com
occ config:app:set libresign whatsapp_api_key --value '<partner key>' --sensitive
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
variables across body and button together.

| Template | Body | Button |
| --- | --- | --- |
| Request | `{{1}} has sent you "{{2}}" to sign on GoPaperless.` | URL "Review and sign": `https://gopaperless.ke/apps/libresign/p/sign/{{3}}` |
| Receipt | `You signed "{{1}}".` | URL "View": `https://gopaperless.ke/apps/libresign/p/sign/{{2}}` |

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

The MIMI passkey gate does not stop a SecurySign signature: SecurySign's frame
asks for the same passkey. If SecurySign is down, the local engine asks for the
MIMI passkey (`MimiPasskeyRequired`, action 1000) before it signs.

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
