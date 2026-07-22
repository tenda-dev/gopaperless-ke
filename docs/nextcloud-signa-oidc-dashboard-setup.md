<!--
 - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Enabling "Log in with Signa" on the Nextcloud dashboard

**Audience:** whoever administers the Nextcloud instance that GoPaperless runs
on (the person with the admin account and, ideally, `occ` shell access).

**What this achieves:** adds Signa (the SecurySign / Keycloak identity
provider) as a **third login option** on the Nextcloud sign-in page, alongside
the existing local password and Google (`sociallogin`) options. It does not
remove either of those — this is additive.

**Important scope note — no GoPaperless/LibreSign code is involved here.**
Logging in is handled entirely by Nextcloud core plus the `user_oidc` app.
GoPaperless never sees passwords or identity-provider tokens; it only ever
trusts whichever Nextcloud user is already signed in. So everything below is
Nextcloud **instance configuration**, done through the admin dashboard (or the
`occ` command line). The accompanying code change in this repo is a separate,
one-time account-safety net (see [Step 0](#step-0--pre-flight-run-the-account-safety-check-once)
and `docs/oidc-signa-setup.md`).

---

## What you need before you start

| Thing | Value (dev) | Notes |
|---|---|---|
| Discovery URL | `https://idp.dev.securysign.com/realms/signa/.well-known/openid-configuration` | Swap the host/realm for the production issuer when promoting beyond dev. |
| Client ID | issued for **this** Nextcloud instance | In the dev sandbox the existing `signa-rp-2003` client was reused. For a real deployment, ask the Signa/Keycloak admin for a client (or confirm reuse). |
| Client secret | issued with the client | **Never commit this or paste it into chat/tickets.** Get it from the Signa/Keycloak admin. |
| Redirect URI | `https://<your-nextcloud-host>/apps/user_oidc/code` | `user_oidc` derives this automatically and it is **not** configurable. It **must** be whitelisted on the Keycloak client (see [Step 4](#step-4--the-one-thing-that-must-happen-on-the-keycloak-side)). Local dev example: `http://localhost:8080/apps/user_oidc/code`. |

You will also need **admin access to the Signa Keycloak realm** (or someone who
has it) for Step 4 — that step happens in Keycloak, not in Nextcloud.

---

## Step 0 — Pre-flight: run the account-safety check once

Signa users will be matched to existing Nextcloud accounts **by email**. If two
Nextcloud accounts already share an email address, that match is ambiguous.
Before enabling the provider, run the diagnostic this repo adds:

```bash
occ libresign:diagnose:duplicate-emails
```

- A clean instance prints **"No duplicate emails found."** — proceed.
- If it flags any collisions, resolve them by hand (merge / correct / delete the
  stale account) **before** turning on SSO.

> If you don't have shell access, ask whoever deploys the instance to run this
> one command and share the output. It is read-only.

---

## Step 1 — Install the `user_oidc` app

`user_oidc` is Nextcloud's official OpenID Connect login app (its display name
in the app store is **"OpenID Connect user backend"**).

**Via the dashboard (preferred):**

1. Sign in as an admin.
2. Top-right avatar → **Apps**.
3. Search for **OpenID Connect** (or `user_oidc`).
4. On **"OpenID Connect user backend"**, click **Download and enable**.

**If the app-store install fails** (in the dev sandbox it once returned
"not found on the appstore" on a release-candidate Nextcloud build), install
from the command line instead:

```bash
occ app:install user_oidc
occ app:enable user_oidc
```

Confirm it's enabled:

```bash
occ app:list | grep user_oidc
```

---

## Step 2 — Register the Signa provider

This is where the IdP URL becomes configuration. You can do it in the UI or the
CLI — they configure the same thing.

**Via the dashboard:**

1. Avatar → **Administration settings**.
2. In the left sidebar under *Administration*, open **OpenID Connect**.
3. Click **Register a new provider** and fill in:
   - **Identifier / Client name:** `signa`
   - **Client ID:** the client ID issued for this instance (e.g. `signa-rp-2003`)
   - **Client secret:** the matching secret (from the Keycloak admin)
   - **Discovery endpoint:** the discovery URL from the table above
   - **Scopes:** `openid email profile`
4. Save.

**Via the CLI (this is exactly what was run and verified in the sandbox):**

```bash
occ user_oidc:provider signa \
  -c <CLIENT_ID> \
  -s '<CLIENT_SECRET>' \
  -d https://idp.dev.securysign.com/realms/signa/.well-known/openid-configuration \
  --mapping-uid=email --unique-uid=0
```

The `--mapping-uid=email --unique-uid=0` part is what makes returning users
match an existing account by email instead of getting a brand-new one — see the
next step for why that matters.

---

## Step 3 — The one setting that must be right: match by email, don't auto-create

Every GoPaperless certificate, PFX file, and signed document is tied to the
Nextcloud **user id**. If Signa login mints a *new* account for someone who
already has one, they'll look like a stranger — none of their existing
documents or certificates will resolve.

So configure the provider to:

- **Identify returning users by their email claim** against existing Nextcloud
  accounts, and
- **NOT** "auto-provision / create a new account on first login" as the primary
  behaviour for existing users.

The exact wording of this setting depends on the `user_oidc` version installed —
on the **OpenID Connect** admin page look for options named like *"unique
identifier claim"* / *"attribute mapping"*, and prefer **email-based matching**
over `sub`-based auto-provisioning for this migration. (The CLI equivalent is
the `--mapping-uid=email --unique-uid=0` flags in Step 2.)

Leave the local-password and Google (`sociallogin`) logins enabled — this is
additive, not a replacement.

---

## Step 4 — The one thing that must happen on the **Keycloak** side

`user_oidc` will send users back to:

```
https://<your-nextcloud-host>/apps/user_oidc/code
```

Keycloak will **refuse the login** (HTTP 400, *"Invalid parameter:
redirect_uri"*) unless that exact URL is in the client's **Valid redirect URIs**
list. This is Keycloak doing its job, not a bug.

**Action:** ask whoever administers the Signa Keycloak realm to add your
instance's redirect URI to the client's allowed list. For example:

- Production: `https://<your-nextcloud-host>/apps/user_oidc/code`
- Local sandbox test: `http://localhost:8080/apps/user_oidc/code`

Until this is done, the "signa" button will bounce off Keycloak with the 400
above. Once it's done, login carries all the way through.

---

## Step 5 — Verify it works

1. Open your Nextcloud login page — you should now see a **"signa"** login
   button in addition to the username/password form and the Google button.
   (You can also hit the login route directly:
   `https://<your-nextcloud-host>/apps/user_oidc/login/<provider-id>` — the
   sandbox registered as provider id `1`.)
2. **Existing user:** log in through the Signa button. Confirm you land on your
   existing files and that a previously issued signing certificate still
   resolves — sign a test document in GoPaperless.
3. **Brand-new user:** log in through Signa. Confirm a fresh Nextcloud account
   is provisioned normally.
4. Re-run `occ libresign:diagnose:duplicate-emails` periodically as ongoing
   hygiene.

---

## Rollback

Disabling the Signa provider (remove it on the **OpenID Connect** admin page, or
`occ user_oidc:provider:delete <id>`) does **not** touch existing local or
Google accounts or their sessions. Those login doors were never modified.

---

## Related docs in this repo

- `docs/oidc-signa-setup.md` — the "why" behind the email-matching rule and the
  account-safety reasoning.
- `docs/sandbox-testing.md` — a full walk-through of the local sandbox where
  this exact flow was tested end-to-end against the real Signa realm (including
  the redirect-URI 400 and what it means).
