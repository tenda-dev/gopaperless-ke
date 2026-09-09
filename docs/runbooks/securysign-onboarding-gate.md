# SecurySign onboarding gate

How an OIDC user who has no usable SecurySign certificate gets routed through
TendaWorld's KES 300 payment and the SecurySign enrolment ceremony, then back to
the GoPaperless task they started.

Email/password users are untouched. The gate only fires for a logged-in session
that came from the configured `user_oidc` provider, so token signers, public
links and legacy accounts never see it and keep the local signing engine.

## Rollout status

| Piece | State |
|---|---|
| Page gate, onboarding handoff, return journey | code complete, unit tested, **off by default** |
| Signing API gate (`/api/v1/sign/...`) | code complete, unit tested |
| `force=1` handoff replacing a different local account | code complete, unit tested |
| Website payment and enrolment continuation | code complete, script tested |
| Importing the SecurySign visible signature | code complete, unit tested |
| Signing with the user's own SecurySign certificate | **not built.** See "The upstream blocker" |

Nothing is enabled until `securysign_provider_id` is set to a positive value.
With it unset, `SecurySignService::applies()` returns false everywhere and both
apps behave exactly as before.

## Config keys

GoPaperless (Nextcloud app config, app id `libresign`):

```bash
occ config:app:set libresign securysign_provider_id --value 2 --type integer
occ config:app:set libresign securysign_issuer     --value https://idp.dev.securysign.com/realms/signa
occ config:app:set libresign securysign_url        --value https://signa.dev.securysign.com
occ config:app:set libresign tendaworld_url        --value https://tendaworld.com
occ config:app:set libresign oidc_sso_handoff_enabled --value 1 --type boolean
```

- `securysign_provider_id` is the `user_oidc` provider id, per instance. On
  `gopaperless.ke` that is `2`; `1` is the dead `ke_mimi_id` provider. Read it
  from the `initial-state-core-alternativeLogins` input on `<base>/login`.
  `0` or unset disables the gate.
- `securysign_issuer` must equal the `iss` claim in the id_token exactly. A
  mismatch is treated as a hostile session and refused, not as an outage.
- `oidc_sso_handoff_enabled` gates `/apps/libresign/sso`, the route the website
  links to for the return leg. Off by default, and with it off the route
  redirects to the app root, so the handoff looks like it silently does nothing.
- `securysign_url` and `tendaworld_url` must be bare origins: https everywhere,
  or http on `localhost` / `127.0.0.1` / `[::1]` so the flow can be driven on a
  local stack. A path, a query, a fragment or embedded credentials are rejected,
  and `localhost.example.com` is not loopback.
- **`tendaworld_url` can come from the environment.** Nextcloud maps any
  `NC_`-prefixed variable into system config, so `NC_tendaworld_url` on the
  container sets it with no occ step, which is what a Dokploy or compose deploy
  wants. `onboardingUrl()` reads app config first and falls back to the system
  value, so an image's env cannot quietly override an admin who ran occ. Verified
  on the local stack 2026-09-09:
  `docker exec -e NC_tendaworld_url=… occ config:system:get tendaworld_url`.

Website (`.env.local` / `.env.production`, all of them already exist):
`NEXT_PUBLIC_GOPAPERLESS_URL`, `NEXT_PUBLIC_GP_OIDC_PROVIDER_ID`,
`SIGNA_BASE_URL`, `PAYMENT_URL_BASE`, `PAYMENT_GATEWAY_KEY`,
`SIGNA_SESSION_SECRET`.

The gate reads the user's SecurySign access token through `user_oidc`'s
`TokenService`, so the provider needs **store login token** switched on.
Verified against user_oidc 8.11-dev in the local sandbox; 8.10 carries the same
`TokenService::getToken()`, `decodeIdToken()` and `Token::isExpired()` API.

## The journey

1. An OIDC user opens any LibreSign page. `SecurySignMiddleware::afterController`
   asks SecurySign for the certificate and the visible signature card.
2. Ready (certificate active, inside its validity window, card bound to that
   `certificateId`) means the page renders untouched.
3. Not ready sends the user to `/apps/libresign/securysign/onboard?returnTo=...`,
   which mints a one-hour nonce in the Nextcloud session bound to the uid and the
   OIDC `sub`, then hands off to `<tendaworld>/onboarding/gopaperless?state=&subject=`.
4. The website seals the same pair into `gp_onboarding` (AES-256-GCM, one hour)
   and starts a fresh OIDC exchange. The live Keycloak session makes this
   prompt-free, so `prompt=select_account` is suppressed on this leg only.
5. `/onboarding/gopaperless/continue` re-checks the subject, then entitlement
   (`checkEntitlement`, backend first, unchanged), then the certificate and the
   card. It sends the user to `/subscribe` or `/enrol/start` as needed. No
   payment logic is duplicated.
6. Once both are in place it redirects to
   `<gopaperless>/apps/libresign/securysign/return?state=<nonce>`, which verifies
   nonce, expiry, uid and `sub`, re-reads readiness, then drops the nonce and
   returns the user to the exact page from step 3.

Failures are loud. An outage anywhere returns 503 with the payment retained,
never a redirect loop and never a signature. `/enrol/start` returning "done"
while the certificate or card is still missing comes back as `completed=1`,
which reports the failure instead of bouncing again.

### Cookie lifecycle

`gp_onboarding` is dropped on success and on an account mismatch (both in
`/auth/callback` and in the continuation). Leaving it in place after a
wrong-account login would bounce every later website visit into the same 409 for
an hour. GoPaperless keeps its own nonce for that hour, so a fresh click there
resumes the same task. Two tabs on two Google accounts therefore end with one
tab finishing and the other told to restart from GoPaperless.

The continuation clears the website cookie before GoPaperless confirms. That is
deliberate: GoPaperless still holds the nonce, so the return leg is retryable.

### force=1

`gpLoginUrl()` now points at `/apps/libresign/sso?providerId=<id>&force=1`.
`/apps/user_oidc/login/<id>` ignores `force`: `LoginController::login()` returns
immediately when a session already exists, so a user signed in as a different
local account stayed on that account. `SsoController::handoff()` logs the local
session out first, then enters `user_oidc`, then returns through
`/apps/libresign/sso/complete`, which sanitises the redirect a second time
before sending the user on.

## The visible signature

**SecurySign does not compose a card.** Probed 2026-09-06 against live:
`/api/signature/visible` returned a 366x137 PNG of handwriting and nothing else,
and `/download` is the same bytes with tEXt chunks added. Signa's own
`RP_VISIBLE_SIGNATURES_USER_GUIDE` says it implements "a user-created PNG
signature image", and that PAdES appearance placement is "not yet implemented"
in the Java service. The website's profile panel had its plate and detail rows
deleted on the belief that Signa composited them in; it does not.

GoPaperless stores that handwriting **unchanged**. The account, issuer and
validity lines are added at signing time by LibreSign's own signature text
template, which already ships variables for all of them. An earlier pass here
composited a card with GD instead; that duplicated a feature the app has, so it
was deleted.

### The template

Set through `SignatureTextService::save()`, stored in app config
`signature_text_template`, rendered by Twig at signing time:

```
Account : {{SignerEmail}}
Issuer : {{IssuerCommonName}}
Date of Issue : {{CertificateValidFrom|date("j M Y")}}
Expiry Date : {{CertificateValidTo|date("j M Y")}}
```

Three things about `save()` that are not obvious:

- It runs **`strip_tags()`**. Only `<br>` and `</p>` survive, as newlines. A
  `<table>` collapses into one run-on line — which is what happened first.
- The QR is **not** drawn by the template. `save()` regex-matches the submitted
  text for `{{ qrcode }}` or a base64 `<img>`, sets `signature_stamp_has_qrcode`,
  and then strips the tag. `JSignPdfHandler::createQrOnlyBackground()` composites
  the QR onto the stamp from the document's validation URL. So submit the
  `<img src="data:image/png;base64,{{ qrcode }}">` form: it sets the flag and
  leaves no placeholder behind. A bare `{{ qrcode }}` would survive stripping and
  render a base64 blob as visible text.
- `signature_render_mode` must stay `GRAPHIC_AND_DESCRIPTION`, or the handwriting
  is dropped in favour of text alone.

### The fields describe the signing certificate, not the PDC

`SignFileService::buildBaseSignatureParams()` fills `IssuerCommonName`,
`CertificateValidFrom` and `CertificateValidTo` from `readCertificate()` — the
certificate that **actually signs**, which today is LibreSign's local one. So the
stamp currently reads "GoPaperless Local", not SecurySign, and the dates are the
local certificate's. That is accurate rather than aspirational, and it only
becomes the SecurySign PDC when the upstream blocker below is cleared.

### Mirroring

`readiness()` returns the handwriting and the certificate facts in the pair of
calls the gate already makes; the middleware hands that to
`syncVisibleSignature()`, which writes it as a LibreSign signature element
stamped with `metadata.securysign_certificate_id` and `metadata.securysign_card`
(`SecurySignService::CARD_VERSION`). A later page load does nothing while both
match; a renewed certificate replaces the element, and **bumping `CARD_VERSION`
re-imports for everyone**. Failures are logged and swallowed.

`JSignPdfHandler` stamps the element image into the PDF
(`$signatureImagePath = $element->getTempFile()`), so the handwriting plus the
template text plus the QR are what land on the page.

Editing is refused at the endpoints that mutate — `createSignatureElement`,
`patchSignatureElement`, `deleteSignatureElement` return 403 while the gate
applies. Reads stay open. Do **not** wire this to
`SignerElementsService::canCreateSignature()`: false there means "no graphic
signature at all", which hides the Signatures view and makes `SignFileService`
drop user images from the PDF entirely.

## When the gate cannot answer

Every failure renders `templates/securysign_notice.php` through Nextcloud's guest
layout, so it carries the instance logo, theme and footer rather than plain text
on white. Each one offers a primary action and a link out of LibreSign entirely —
the rest of Nextcloud is never gated, so that link is the escape hatch when the
gate itself is what is broken.

| Situation | Status | What the user is offered |
|---|---|---|
| Signa answers 401 or 403 | 401 | "Sign in again", pointing at `/apps/libresign/sso?providerId=<id>&force=1` — one click that drops the local session and re-enters OIDC |
| Signa unreachable or 5xx | 503 | "Try again" on the page they came from |
| Onboarding round trip broken | 503 | "Start again" at the app root |

Upstream text never reaches the user. `SecurySignService::request()` logs the
status, the path and the first 500 bytes of Signa's body, which is the only place
that says whether a 401 was an expired token, a rejected audience or a
misconfigured introspection client. Signa tries introspection first and falls
back to local JWKS validation, so a 401 means **both** paths refused the token.

## Testing it on the local stack

The devcontainer stack in this repo bind-mounts it straight onto
`apps-extra/libresign`, so there is nothing to deploy:

```bash
docker compose -p devcontainer -f .devcontainer/docker-compose.yml \
  -f .devcontainer/docker-compose.signa-terms.yml up -d
```

The override pins PHP 8.3 and Nextcloud stable34. The base file defaults to PHP
8.2 and master, and NC 34's typed class constants fatal on 8.2. Nextcloud
answers on `http://localhost` (nginx publishes 80). The website runs next to it
on `http://localhost:3100`, not 3000, and reads `.env` in that repo.

Both on `localhost` means one cookie jar: cookies ignore ports, so the site's
chunked sealed session is sent to Nextcloud on every request too, and nginx
answers `400 Request Header Or Cookie Too Large` from its default 8k buffer.
`.devcontainer/nginx-header-buffers.conf` raises it and the signa-terms override
mounts it. Production hosts differ, so nothing there needs this.

Loopback origins are allowed over plain http, so both config values below are
accepted. Every other host must be https.

```bash
docker compose exec --user www-data nextcloud php occ   config:app:set libresign securysign_provider_id --value <local provider id> --type integer
docker compose exec --user www-data nextcloud php occ   config:app:set libresign securysign_issuer --value https://idp.dev.securysign.com/realms/signa
docker compose exec --user www-data nextcloud php occ   config:app:set libresign securysign_url --value https://signa.dev.securysign.com
docker compose exec --user www-data nextcloud php occ   config:app:set libresign tendaworld_url --value http://localhost:3100
```

New PHP classes do not load until the autoloader knows about them. This repo
sets `classmap-authoritative` in `composer.json`, so Composer never falls back to
a PSR-4 filesystem lookup and `SecurySignService`, `SecurySignController` and
`SecurySignMiddleware` are invisible until the classmap is regenerated:

```bash
docker exec devcontainer-nextcloud-1   sh -c 'cd /var/www/html/apps-extra/libresign && composer dump-autoload --no-dev --no-scripts'
```

**`--no-dev` is not optional.** `nextcloud/ocp` is a require-dev package and
`autoload-dev` maps `OCP\` at it, so a plain `dump-autoload` writes a thousand-odd
OCP stub entries into an authoritative classmap. They shadow the server's own
OCP, `OC\TaskProcessing\Manager` then fails its `#[\Override]` check, and
Nextcloud fatals on every request. The container decides it is not installed and
enters a restart loop, at which point `docker exec` stops working and the way
back in is a one-off container:

```bash
docker run --rm --entrypoint composer -v "<repo>:/app" -w /app \
  ghcr.io/librecodecoop/nextcloud-dev-php83:latest dump-autoload --no-dev --no-scripts
```

The tests need the opposite. They mock OCP interfaces that exist only in those
stubs, so dump with dev to run the suite and with `--no-dev` to run the app.

It takes a few minutes over the Windows bind mount. Confirm it worked before
blaming anything else — a `false` here explains a gate that never fires:

```bash
docker exec devcontainer-nextcloud-1 php -r   'require "/var/www/html/apps-extra/libresign/vendor/autoload.php";
   var_dump(class_exists("OCA\Libresign\Service\SecurySignService"));'
```

The same applies to any deployment whose pipeline does not run `composer install`
after taking these files. It is also why `tests/php/securysign-bootstrap.php`
turns the authoritative classmap off.

Read the local provider id from `occ user_oidc:provider`; it is per instance and
almost certainly not the `2` that `gopaperless.ke` uses. Set
`NEXT_PUBLIC_GOPAPERLESS_URL=http://localhost` and
`NEXT_PUBLIC_GP_OIDC_PROVIDER_ID=1` in the website's `.env` so the return leg
comes back to the sandbox instead of production.

Two things live outside these repos and have to be right before the round trip
completes. The `user_oidc` provider needs **store login token** on, or
`identity()` refuses with "Sign in again to connect to SecurySign" — the gate
reads the SecurySign access token out of `TokenService`. And the Keycloak client
has to whitelist both loopback redirect URIs,
`http://localhost/apps/user_oidc/code` and `http://localhost:3100/callback`.
Neither is something this app can set.

The scope stays `openid signa-basic`. `GET /api/pki/certificates/me` and
`GET /api/signature/visible` both authenticate on the bearer token alone —
`requireAuth()` in Signa's `AuthFunctions.php` checks introspection, not scopes.
`signa-visible-signature` only governs the reference claims Keycloak exposes
through userinfo, which this gate never reads.

What the three states look like when you drive it:

| Signa state | What you should see |
|---|---|
| No certificate | `certificates/me` answers `{"status":"none"}`, any LibreSign page redirects to `/onboarding/gopaperless` on the website |
| Certificate, no card | certificate parses and is current, `signature/visible` 404s, same redirect |
| Both, card bound to the active `certificateId` | the page renders and nothing happens |

A brand-new Google account that has never touched Signa resolves there as user
id `0`, which returns `{"status":"none"}` rather than an error, so it lands in
onboarding rather than on the 503 page. That is the path worth walking first.

To take the gate back out, unset the provider id — `applies()` then returns
false everywhere and both apps behave exactly as they did before:

```bash
docker compose exec --user www-data nextcloud php occ   config:app:delete libresign securysign_provider_id
```

## Tests

GoPaperless (portable PHP 8.3, no Nextcloud server needed; the standalone
bootstrap turns off the authoritative classmap so the new classes load):

```bash
OPENSSL_CONF=<php-runtime>/extras/ssl/openssl.cnf \
php -d extension=mbstring -d extension=openssl vendor/bin/phpunit \
  --bootstrap tests/php/securysign-bootstrap.php --no-configuration \
  tests/php/Unit/Service/SecurySignServiceTest.php \
  tests/php/Unit/Controller/SecurySignControllerTest.php \
  tests/php/Unit/Controller/SsoControllerTest.php \
  tests/php/Unit/Middleware/SecurySignMiddlewareTest.php
```

`OPENSSL_CONF` only matters on Windows, where `openssl_csr_sign` cannot find a
config; without it the one certificate test skips instead of failing. The rest of
`tests/php/Unit` still needs the real `lib/base.php` and will not run here.

Website:

```bash
node node_modules/tsx/dist/cli.mjs scripts/test-gopaperless-onboarding.ts
node node_modules/typescript/bin/tsc --noEmit --incremental false
```

The script fakes the network, so it covers the bad-state 400, the wrong-account
409 and its cookie clear, the tampered and expired cookie, the unpaid and outage
branches, `completed=1`, the certificate validity window, the card-to-certificate
binding, the successful return with the nonce attached, and the `force=1` URL.

## Probed 2026-09-06: `signa-rp-15` access tokens carry no `sub`

Signa answers `401 {"error":"Token missing subject claim"}` to every call made
with an access token from the client the local `user_oidc` provider uses. Its
`AuthFunctions::resolveUser()` reads `sub ?? username ?? client_id` and throws
when all three are empty.

The id_token from the same login is complete; only the access token is stripped:

| Token | Claim names | `sub` | `aud` |
|---|---|---|---|
| id_token | `exp iat jti iss aud sub typ azp nonce sid at_hash preferred_username picture` | yes | yes |
| access token | `exp iat jti iss typ azp sid scope name preferred_username given_name family_name picture` | **no** | **no** |

Since Keycloak 25 the access token's `sub` is contributed by the built-in
**`basic`** client scope rather than being hardcoded; the id_token keeps `sub`
either way because OIDC requires it there. `signa-rp-15` does not have that scope.

Probed 2026-09-06 against the authorization endpoint, one scope at a time, the
same way the realm's requestable scopes were probed before:

| Client | `basic` | `roles` | `acr` | `web-origins` | `signa-basic` |
|---|---|---|---|---|---|
| `signa-rp-6` (website, dev) | **accepted** | invalid_scope | — | — | — |
| `signa-rp-15` (gopaperless) | **invalid_scope** | invalid_scope | invalid_scope | invalid_scope | accepted |

`roles` is rejected on both, so it is not the variable. `basic` is the only scope
that separates a client whose tokens Signa accepts from one whose tokens it
refuses.

**The fix is one assignment:** Keycloak → Clients → `signa-rp-15` → Client scopes
→ Add client scope → `basic` → Default. Nothing in either repo can add a claim to
a token it does not mint.

To re-check both tokens, from PowerShell (claim **names** only — the values are
the user's identity):

```powershell
docker exec --user www-data devcontainer-nextcloud-1 php /var/www/html/apps-extra/libresign/docs/runbooks/show-token-claims.php
```

`show-token-claims.php` decrypts the id_token of each recent login out of
`oc_user_oidc_sessions` and reads the access token's names from the last refusal
in `nextcloud.log`, printing PRESENT/MISSING for `sub` on each. It is a file
rather than a documented one-liner because the equivalent inline PHP cannot be
quoted in PowerShell.

The access token alone, if the log is all you want:

```powershell
docker exec devcontainer-nextcloud-1 grep tokenClaims /var/www/html/data/nextcloud.log | Select-String -Pattern '"tokenClaims":"[^"]*"' -AllMatches | ForEach-Object { $_.Matches.Value }
```

Grep runs **inside** the container over the whole file. A `tail -c` window misses
the entry once the log grows, and PowerShell has no `grep` of its own.

## The upstream blocker

GoPaperless must eventually produce PDF signatures with the user's own SecurySign
certificate and stamp their canonical card. It cannot yet, and no flag in this
repo pretends otherwise. Signing still runs on LibreSign's local engine for
everyone, OIDC users included. Read the three findings in `signa-original`
before wiring anything:

1. `SignaPadesServer.doPrepare` reads only `pdfBase64`, `certId` and `certPem`.
   The `options` that `SignRouteHandler::padesPrepare` passes are dropped, and
   the appearance it builds is invisible. There is no way to place the canonical
   card into the byte range, so a naive integration would sign a document that
   shows nothing.
2. `doFinalize` falls back to an ephemeral P-256 key and a self-generated proxy
   certificate whenever `credentialID` is empty, logs one line to stdout, and
   returns the result as a successfully signed PDF. That is the silent
   substitution the requirement forbids.
3. `padesFinalize` requires `signatureBase64` and stores it, but never verifies
   the WebAuthn assertion and never binds it to the prepared hash. The CSC call
   is authorized by the bearer token alone: `CscClient.signHash` fetches its own
   SAD, and Java sends no `clientData`. So "the user approved this document" is
   not enforced anywhere on the PAdES path, even though
   `SingleSigningService` already verifies assertions for the headless API.

The contract Signa has to expose before GoPaperless can switch:

- `POST /api/sign/pades/prepare` honours an appearance option carrying the
  canonical card and returns the byte range covering it, so the card is inside
  the signed bytes rather than stamped afterwards.
- `POST /api/sign/pades/finalize` verifies the WebAuthn assertion against the
  prepared hash, refuses the operation when it does not match, and returns
  4xx rather than any signature when the credential is missing. Deleting the
  ephemeral branch, or gating it behind an explicitly non-production flag, is
  part of this.
- The response states the signing `certificateId` so GoPaperless can assert it
  is the same certificate the gate above validated.

Until all three hold, keep `securysign_provider_id` unset on any instance where
the local engine's signatures would be mistaken for SecurySign ones. The gate is
onboarding, not signing.
