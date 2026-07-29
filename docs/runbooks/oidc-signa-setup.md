---
title: "Adding Signa (SecurySign / Keycloak) as a login provider"
type: runbook
status: active
domain: ops
layer: runbooks
owner: engineering
date: 2026-07-29
commit: "05f7f3566"
related: ["docs/runbooks/nextcloud-signa-oidc-dashboard-setup.md", "docs/runbooks/sandbox-testing.md"]
---

<!--
 - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Adding Signa (SecurySign / Keycloak) as a login provider

This is an **ops/admin runbook for the Nextcloud instance**, not a LibreSign
code change — LibreSign never sees passwords or identity-provider tokens. It
only ever consumes whatever Nextcloud user (`uid`) is already logged in
(`OCP\IUserSession`/`IUserManager`). Wiring a new login provider into
Nextcloud is entirely a Nextcloud-core configuration step, done with the
`user_oidc` app.

## The mental model

Think of "logging in" as a front desk (Nextcloud core) checking ID, and
"signing documents" (this LibreSign app) as a department upstairs that never
checks ID itself — it just trusts whatever badge number (`uid`) the front desk
hands it, and it writes that badge number permanently onto every certificate
and signed document it produces.

Today the front desk accepts two doors: a local password, or "flash your
Google badge" via the `sociallogin` app. This adds a **third door** — Signa —
without removing the other two. Nextcloud supports multiple login backends
side by side; this is additive, not a replacement.

## The one setting that matters

When an existing user walks through the new Signa door for the first time,
the front desk **must** recognize them as someone it already has a badge for —
matched by email — rather than print them a brand-new badge. If it printed a
new one, LibreSign would treat them as a stranger: a new, empty account, with
no access to their existing signed documents, certificates, or PFX files
(all of which are keyed on the Nextcloud `uid` — see
`lib/Service/IdentifyMethod/Account.php`,
`lib/Handler/SignEngine/SignEngineHandler.php`).

**Do not enable "auto-provision a new account on first login."** Configure
account linking by email against existing users instead.

## Before enabling the new provider

Run the pre-flight safety check added alongside this runbook:

```
occ libresign:diagnose:duplicate-emails
```

This reports any existing Nextcloud accounts that already share an email
address. If it finds any, resolve them by hand first (merge, correct, or
delete the stale one).

Note: `Service\IdentifyMethod\Account::getSigner()` already handles this at
runtime — if two accounts share an email, it prefers whichever one matches
the currently authenticated session, so a user signed in via Signa/SSO can
still sign a request even when their account shares an email with an older
local/sociallogin account. This command exists for the residual gap that
runtime preference can't fix: there's no session to prefer for an anonymous
email-link signer, so a genuine collision there still needs resolving by
hand. Treat this as a proactive hygiene check, not a substitute for that
logic.

## Configuring `user_oidc`

1. Install and enable the `user_oidc` app (`occ app:install user_oidc` if not
   already present).
2. Register a provider pointed at the `signa` realm's discovery document:
   `https://idp.dev.securysign.com/realms/signa/.well-known/openid-configuration`
   (swap for the production issuer when promoting beyond dev), with the
   client ID/secret issued for this Nextcloud instance's redirect URI.
3. **Account linking / provisioning**: set the provider to identify returning
   users by their **email claim** against existing Nextcloud accounts, not to
   mint a new account per login. (Exact setting name depends on the
   `user_oidc` version in use — check its admin settings page under
   Settings → Administration → OpenID Connect; look for "unique identifier
   claim" / "attribute mapping" options and prefer email-based matching over
   `sub`-based auto-provisioning for this migration.)
4. Leave the existing local-password and `sociallogin` (Google) providers
   enabled — this is additive.

## Verifying it worked

- Log in as an **existing** user through the new Signa door. Confirm they
  land on their existing files and that a previously-issued signing
  certificate still resolves (sign a test document).
- Log in as a **brand-new** user through Signa. Confirm a fresh Nextcloud
  account is provisioned normally.
- Re-run `occ libresign:diagnose:duplicate-emails` periodically — new
  duplicate emails could reappear if account creation elsewhere in the
  instance ever bypasses this check.

## Rollback

Disabling the Signa provider in `user_oidc` does not affect existing local or
`sociallogin` accounts or sessions — those doors were never touched.

## Explicitly out of scope here

Payments/subscription entitlement (Daraja/M-Pesa, DPO, the local
Product/Entitlement system, and the new external subscription API) is a
separate, already in-flight effort — see the `feature/sponsorship-workflow`
branch. This runbook and its accompanying code change only cover identity/
login continuity.
