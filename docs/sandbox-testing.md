<!--
 - SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Local sandbox: testing Signa SSO + LibreSign operations

This documents a real, running local environment — not a description of a
hypothetical setup. Everything below was actually executed against a live
Nextcloud 34 + LibreSign instance, wired to the real Signa/Keycloak realm.

## What's running

- Sandbox repo: `nextcloud-docker-development` (separate checkout, sibling
  of this one), providing Nextcloud core + MySQL + nginx via `docker compose`.
- `docker-compose.override.yml` in that repo:
  - Bind-mounts this `gopaperless-ke` checkout (branch
    `feat/signa-oidc-account-safety-v2`, based on `feature/sponsorship-workflow`)
    into the container as `apps-extra/libresign`.
  - Excludes `node_modules` from the mount via an anonymous volume (it's
    irrelevant to PHP testing and its size made container startup crawl
    over the Windows↔Docker bind-mount layer).
  - Drops the `mysql` service's host port binding (port 3306 was already
    taken by an unrelated process on the host; not needed since only the
    `nextcloud` container talks to `mysql`, over the internal Docker network).
- `.env` in that repo: `VERSION_NEXTCLOUD=stable34` (matching this app's
  declared `<nextcloud min-version="34" max-version="34"/>` — the default
  `stable33` does NOT match and was corrected), `HTTP_PORT=8080`.
- Installed Nextcloud version: **34.0.2**. LibreSign: **14.0.0-dev.4**, enabled.

## 1. Reproducing the environment

From a clean `docker compose down`, this is what actually stood it up:

```bash
cd nextcloud-docker-development
docker compose up -d nextcloud mysql nginx mailpit redis
# poll until ready (initial bootstrap clones Nextcloud core + 3rdparty from
# scratch and can take 10-20+ minutes; be patient, don't force-recreate
# mid-install — doing so corrupts config.php and requires a manual reset)
until docker compose exec -T nextcloud php occ status 2>/dev/null | grep -q "installed: true"; do sleep 10; done
```

If the container crash-loops printing `❌ Nextcloud is not installed.
Skipping startup tasks and stopping container.` — this means `config/config.php`
was left empty by an interrupted install (this happened twice while setting
this up, likely OOM-killed during a heavy `npm ci` step for a bundled app).
Fix:

```bash
docker compose stop nextcloud
rm -f volumes/nextcloud/config/config.php volumes/nextcloud/config/CAN_INSTALL
docker compose start nextcloud
# poll again as above — this resumes cleanly, and skips re-cloning any
# bundled app whose directory already exists from the interrupted attempt
```

Once installed, get LibreSign's own dependencies (needed once per checkout,
or after a `composer.lock` change):

```bash
docker compose exec -u www-data nextcloud bash -c \
  "cd apps-extra/libresign && composer install --no-interaction --prefer-dist"
```

Note: this repo's `.patches/*.diff` files are checked in with LF line
endings; on a Windows host with `core.autocrlf=true`, checking them out
silently converts them to CRLF, which breaks Composer's patch hash
verification (`Hash mismatch for patch downloaded from .patches/...`). Fix by
normalizing the working tree (does not affect the actual git-committed
content, which is already correct LF):

```bash
sed -i 's/\r$//' .patches/*.diff
```

## 2. Testing LibreSign operations directly

Log in as the local admin account at **http://localhost:8080**:

- Username: `admin`
- Password: `admin`
(`NEXTCLOUD_ADMIN_USER`/`NEXTCLOUD_ADMIN_PASSWORD` defaults from
`nextcloud-docker-development/docker-compose.yml`.)

From there:
- Open the **LibreSign** app from the app switcher, upload a PDF, and create
  a signature request — exercises the real signing flow end to end against
  a real Nextcloud 34 instance.
- Run the new diagnostic command this branch adds:
  ```bash
  docker compose exec -u www-data nextcloud bash -c \
    "cd /var/www/html && php occ libresign:diagnose:duplicate-emails"
  ```
  On a clean instance it reports "No duplicate emails found." Create two
  users sharing an email (`occ user:add` twice, then
  `occ user:setting <uid> settings email <address>` — or via the admin UI)
  to see it flag the collision.

## 3. The live Signa SSO test

`user_oidc` (Nextcloud's official OIDC login app) was cloned directly into
`apps-extra/user_oidc` and enabled (the appstore install failed with "not
found on the appstore" — likely an RC-version-tag quirk with this build;
the direct clone method the sandbox already uses for other bundled apps
worked cleanly and confirmed `user_oidc` declares
`min-version="29" max-version="35"`, so 34 is fully supported).

Registered a provider pointed at the real Signa realm, mapping accounts by
email (matching the recommendation in `docs/oidc-signa-setup.md`):

```bash
docker compose exec -u www-data nextcloud bash -c \
  "cd /var/www/html && php occ user_oidc:provider signa \
    -c signa-rp-2003 \
    -s '<SIGNA_CLIENT_SECRET — see tendaworld_website/.env>' \
    -d https://idp.dev.securysign.com/realms/signa/.well-known/openid-configuration \
    --mapping-uid=email --unique-uid=0"
# -> registered as provider id 1
```

### What actually happened when logging in

Hitting the login-initiation route:

```bash
curl -sD - -o /dev/null "http://localhost:8080/apps/user_oidc/login/1"
```

...correctly returned an HTTP 303 with a **fully well-formed** OIDC
authorization request:

```
Location: https://idp.dev.securysign.com/realms/signa/protocol/openid-connect/auth
  ?client_id=signa-rp-2003
  &response_type=code
  &scope=openid+email+profile
  &redirect_uri=http%3A%2F%2Flocalhost%3A8080%2Fapps%2Fuser_oidc%2Fcode
  &claims={"id_token":{"email":{"essential":true},...}}
  &state=...&nonce=...
  &code_challenge=...&code_challenge_method=S256
```

PKCE, state, nonce, and claims all present and correct — Nextcloud's side of
the redirect is handled completely gracefully, no crash, no malformed
request.

Following that URL to the real Keycloak:

```bash
curl -sD - "<the Location URL above>"
```

...returned:

```
HTTP/1.1 400 Bad Request
...
<title>Sign in to Signa Trust Service Provider</title>
...
We are sorry...
Invalid parameter: redirect_uri
```

**This is the expected, correct result, not a bug to fix.** The
`signa-rp-2003` Keycloak client currently only has
`http://localhost:3000/callback` (the Tendaworld website's dev callback)
registered as an allowed redirect URI. This sandbox's callback —
`http://localhost:8080/apps/user_oidc/code`, which `user_oidc` derives
automatically and is not configurable to something else — was never added to
that whitelist. Keycloak is doing exactly its job: refusing to redirect an
authorization response to a destination it doesn't recognize, rather than
trusting it blindly. Both sides handled the redirect gracefully — Nextcloud
by building a correct request, Keycloak by safely rejecting an unregistered
target instead of silently allowing it.

### The one step left for a full end-to-end demo

Ask whoever administers the Signa Keycloak realm to add
`http://localhost:8080/apps/user_oidc/code` to `signa-rp-2003`'s allowed
redirect URIs. Once that's done, visiting
**http://localhost:8080/apps/user_oidc/login/1** in a browser (or clicking
the "signa" button on the login page) will carry all the way through: Google
account chooser (or silent SSO if already signed in elsewhere against the
same realm) → back to this Nextcloud instance, signed in, with the account
resolved by the email-mapping rule already configured.

## Reproducing the whole SSO test yourself

With the sandbox already running as described above:

```bash
# 1. Trigger the login redirect, capture where it goes
curl -sD - -o /dev/null "http://localhost:8080/apps/user_oidc/login/1" | grep Location

# 2. Follow that Location URL to Keycloak directly
curl -sD - "<paste the Location URL from step 1>"
# -> HTTP 400, "Invalid parameter: redirect_uri" (until the redirect URI is registered)

# Once the redirect URI IS registered:
# 3. Open in an actual browser (not curl, since Google's login needs a real UI):
#    http://localhost:8080/apps/user_oidc/login/1
```
