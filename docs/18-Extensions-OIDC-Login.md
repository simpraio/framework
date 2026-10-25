# Extension: OIDC Login

Microsoft and Google sign-in through one optional extension, one `Login` / `User` API and separate provider identities. No database, account schema, new packages or core request hooks are required. Application controllers own the login and callback routes; templates use the existing contributor mechanism.

The extension authenticates external identities. Creating local accounts, registration policy and account linking belong to the application, as described under [Registration](#registration).

## Configuration

Defaults live in `config/oidc-login.php`. The extension and each provider are disabled by default. Enable only the providers you need. Put credentials in the uncommitted `config/framework.local.php`:

```php
return [
    'extensions' => [
        'oidc-login' => [
            'enabled' => true,
            'microsoft' => [
                'enabled' => true,
                'tenant' => 'YOUR_DIRECTORY_GUID',
                'client_id' => 'YOUR_MICROSOFT_CLIENT_ID',
                'client_secret' => 'YOUR_MICROSOFT_CLIENT_SECRET',
                'redirect_uri' => 'https://example.com/auth/microsoft/',
                'success_redirect' => '/account/microsoft/',
                'failure_redirect' => '/login/',
                'allowed_groups' => [],
            ],
            'google' => [
                'enabled' => true,
                'client_id' => 'YOUR_CLIENT_ID.apps.googleusercontent.com',
                'client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',
                'redirect_uri' => 'https://example.com/auth/google/',
                'success_redirect' => '/account/google/',
                'failure_redirect' => '/login/',
                'allowed_domains' => [],
            ],
        ],
        'http-client' => [
            'enabled' => true,
            'verify_tls' => true,
            'egress' => [
                'enabled' => true,
                'allowlist' => ['login.microsoftonline.com', 'oauth2.googleapis.com'],
                'block_private_ips' => true,
            ],
        ],
    ],
];
```

Vendor and enable `http-client` too. Merge its allowlist with existing entries; add `graph.microsoft.com` when using Microsoft group fallback. The browser visits Google's `accounts.google.com`; PHP only calls `oauth2.googleapis.com`. Both providers refuse redemption if TLS verification is disabled, even when the HTTP client's insecure-TLS override was acknowledged.

Register the exact callback URI with each provider. For Google, create a **Web application** OAuth client and configure its audience/consent screen; see [Google's setup guide](https://developers.google.com/identity/protocols/oauth2/web-server). For Microsoft, register a Web redirect URI on the Entra application and select the intended supported account types.

### Shared Provider Settings

These keys live inside each provider's section, not at the extension root:

| Setting | Default | Meaning |
| --- | --- | --- |
| `enabled` | `false` | Enable this provider independently. The root `enabled` must also be true; while it is false every provider is off and its credentials are not checked, so a local file can switch the extension off with the root key alone. |
| `client_id`, `client_secret` | Empty | Required when enabled. Secrets are redacted from registration dumps and JSON. |
| `redirect_uri` | Empty | Required when enabled. Absolute HTTPS, except loopback HTTP; no userinfo, fragment, whitespace or backslashes. Never derived from request headers. |
| `session_key` | `microsoft.user` / `google.user` | Identity key; pending flows use `<session_key>.flow`. Enabled providers' keys must not overlap, including flow keys. Do not reuse local `auth` keys. |
| `success_redirect`, `failure_redirect` | `/` | Local paths: `/account` stays exact; `account` becomes `/account/`. |
| `clock_skew` | `60` | Token-clock tolerance in seconds, clamped to 0-300. |
| `flow_ttl` | `600` | Unfinished sign-in lifetime in seconds, minimum 30; at most five attempts per provider. |
| `session_max_age` | `28800` | Seconds a completed sign-in lasts. The provider is not asked again after sign-in, so an account disabled there, or removed from a required group, stays signed in here until this age; then the identity is dropped and the user signs in again, usually without a prompt while the provider session lasts. A sign-in without `authenticated_at`, or stamped more than `clock_skew` seconds ahead of the server clock, counts as expired. `0` keeps it for the life of the session; a negative value is a config error. |

### Microsoft Settings

| Setting | Default | Meaning |
| --- | --- | --- |
| `tenant` | Empty | Directory GUID pins one tenant. `common`, `organizations` and `consumers` use Microsoft's multi-tenant authorities instead; no single-tenant pin is applied. |
| `scopes` | `['openid', 'profile', 'email']` | `openid` and `profile` are always added. Add consented Graph scopes when needed. |
| `allowed_groups` | `[]` | Exact group object IDs (GUIDs), not names. Empty imposes no group restriction. Invalid values fail closed. |
| `group_match` | `any` | Require any or all configured groups. |
| `graph_fallback` | `true` | Resolve group overage through Graph. Requires permissions and its host allowlisted. |

### Google Settings

`allowed_domains` defaults to `[]`, accepting ordinary Google accounts. A nonempty list requires an exact matching Workspace `hd` claim. The email suffix does not prove membership. Names are case-insensitive ASCII hostnames, with no wildcards or trailing dot; use punycode for international domains. Invalid restrictions fail closed.

For `['example.com']`, a personal Google account using `person@example.com` without `hd=example.com` is refused, as is `hd=sub.example.com`. These distinctions follow [Google's ID-token claims](https://developers.google.com/identity/openid-connect/openid-connect#an-id-tokens-payload).

When exactly one domain is allowed the authorization request also carries `hd=<domain>`, and Google's account chooser then declines to offer an account outside it. That is a convenience, not the control: the parameter is a UI hint, and anything building its own authorization URL can drop or change it, so the claim is checked again on the way back. The practical effect is that an interactive sign-in with the wrong account usually fails at Google's own screen rather than reaching `GOOGLE_LOGIN_DOMAIN_DENIED`; configure two domains if you need to see that refusal, since no hint is sent then.

Google scopes are fixed to `openid email profile`. Workspace group queries, offline access and general Google API authorization are not provided.

## Controller

The application owns separate provider routes. Hardcode the provider in each callback controller; never infer it from untrusted callback claims or an arbitrary query parameter.

```php
// modules/auth/Google.php -> /auth/google/
namespace modules\auth;

use core\Controller;
use core\Log;
use core\Template;
use core\http\Response;
use extensions\oidc_login\Login;
use extensions\oidc_login\LoginException;

final class Google extends Controller
{
    public function compose(Template $template): Template|Response
    {
        if ($this->request->query('state') === null
            && $this->request->query('code') === null
            && $this->request->query('error') === null
        ) {
            // The page that sent the user here; ignored unless it is a path on this site.
            return Login::start('google', $this->request->query('return'));
        }

        return Login::callback('google', $this->request, static function (\Throwable $e): void {
            Log::warning($e instanceof LoginException ? $e->reason : 'OIDC login failed');
        });
    }
}
```

For Microsoft, use `modules/auth/Microsoft.php`, class `Microsoft` and the literal `'microsoft'` in both calls. Keep login/callback controllers reachable by guests, including in local `auth` route rules. A route with no controller or template is still a normal 404; this extension adds no routing middleware.

Missing that rule does more than break sign-in. Under `auth`'s `deny` default policy, a callback route without a guest rule is turned away like any protected page, and the gate carries the request to `guest_route` as its return target - so the provider's `?code=…&state=…` ends up in the sign-in URL, and from there in access logs and browser history. The exposure is limited: a code is single-use, and redeeming it needs the client secret and the PKCE verifier held in the session that began the sign-in. But it is a credential written down where it should not be. The fix is the rule above, not turning off `extensions.auth.return_param`.

A successful sign-in lands on the `$returnTo` passed to `Login::start()`, or on `success_redirect` when there is none. Only a path on this site is kept, so an absolute URL, a protocol-relative `//host` or a value with control characters is ignored rather than refused, and the sign-in proceeds to `success_redirect`. The target stays on the server with that sign-in, is never sent to the provider, and is checked again before it is followed. Each tab's sign-in keeps its own target. A failed sign-in always lands on `failure_redirect`.

## Public API

All calls select `'google'` or `'microsoft'` explicitly:

| API | Purpose |
| --- | --- |
| `Login::start($provider, $returnTo = null)` | Create state, nonce and S256 PKCE, keep a local return target if given, and redirect to the provider. |
| `Login::callback($provider, $request, $onFailure = null)` | Validate the flow and provider policy, rotate the session, store identity and redirect. |
| `Login::logout($provider, $route = null, destroySession: false)` | Clear only this provider's identity/flows and rotate the session. Full destruction is explicit. |
| `User::isAuthenticated($provider)`, `isGuest($provider)` | Identity checks; anonymous reads keep sessions lazy. |
| `User::id($provider)` | Microsoft directory object ID (`oid`) or Google subject (`sub`). |
| `User::subject($provider)` | The OIDC `sub` claim, preserving case. |
| `User::name($provider)`, `email($provider)` | Profile data, possibly empty. Never an account-linking key. |
| `User::current($provider)`, `profile($provider, $key = null, $default = null)` | Session profile object or a selected value. |
| `User::clear($provider)` | Forget only that identity, without a full logout. |
| `User::set($provider, $profile)` | Trusted application code only; writes session data without authenticating it. Never pass request data. Stamps `authenticated_at` with the current time unless the profile carries one, so `session_max_age` counts from the call. |

Provider-specific reads fail explicitly on the wrong provider:

- Microsoft: `User::tenantId('microsoft')`, `groups('microsoft')`, `groupsKnown('microsoft')`, `inGroup('microsoft', $groups, all: false)`.
- Google: `User::hostedDomain('google')`, `emailVerified('google')`.

Templates retain `{MS_USER_NAME}`, `{MS_USER_EMAIL}`, `{IsMicrosoftAuthenticated}`, `{IsMicrosoftGuest}` and the corresponding `{GOOGLE_USER_NAME}`, `{GOOGLE_USER_EMAIL}`, `{IsGoogleAuthenticated}`, `{IsGoogleGuest}`.

Every configured provider answers for both of its blocks on every request, enabled or not; a disabled one answers `false` to both, so neither its signed-in area nor its sign-in prompt is rendered. Profile tokens are contributed only for a provider somebody is actually signed in with. Do not drop a provider's blocks from a page expecting them to hide it - a block nobody answers for is left in the output, markers and contents alike.

Controllers must authorize protected actions before performing them. Template blocks are presentation, not access control. Neither provider signs in the local `extensions\auth\User` automatically. Use a CSRF-protected POST action for logout; logout is local and does not end the provider's own session.

## Registration

**Yes: the same flow supports both signing in and registering with your application.** It verifies an external identity; your app then finds its existing account or creates one according to your registration policy. It does not create a Microsoft or Google account.

Use the configured success route as an application-owned completion controller. For example, inside the Google completion controller:

```php
use core\http\HttpException;
use extensions\oidc_login\User;
use app\accounts\Accounts;

if (!User::isAuthenticated('google')) {
    throw new HttpException(403);
}

$account = Accounts::resolveExternal(
    provider: 'google',
    tenant: '',
    externalId: User::id('google'),
    profile: User::current('google'),
);
```

`app\accounts\Accounts::resolveExternal()` is an example application service you implement, **not a framework API**. It must check whether registration is allowed, reject disabled accounts, and complete any invitation, consent or onboarding requirements before issuing your local authenticated session. A success-route visit can repeat and is not proof that this request just completed a new login: resolve accounts idempotently and require fresh authentication for sensitive linking operations.

Store external identities in an application-owned table with a unique key:

- Google: provider + `sub` (`User::id('google')`), with an empty tenant component.
- Microsoft: provider + `tid` (`User::tenantId('microsoft')`) + `oid` (`User::id('microsoft')`). Microsoft describes their identity semantics in its [claims reference](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference).

Enforce that uniqueness in the database, including concurrent registration attempts. Do not automatically attach an external identity to an existing account because their emails match. Linking requires an authenticated local account and deliberate confirmation; one local account may then have multiple external identities. A local account created from OIDC does not need a password unless you separately offer password login.

When using local `auth`, complete local authentication through your application integration, not `auth\User::set()` alone (which only updates its in-request view). Local account authorization and logout remain your application's responsibility; clearing the OIDC identity does not clear a separately issued local session.

## Microsoft Group Membership

Enable a groups claim with **Group ID** on the Entra app registration. Inline membership needs no Graph call. If the token carries a group-overage marker, `graph_fallback` makes one `POST /v1.0/me/getMemberGroups` call, never following a token-supplied endpoint or response next-link.

This requires delegated `User.ReadBasic.All` and `GroupMember.Read.All`, the necessary consent, both scopes in `microsoft.scopes`, and `graph.microsoft.com` allowlisted. [Microsoft documents](https://learn.microsoft.com/en-us/graph/api/directoryobject-getmembergroups?view=graph-rest-1.0) alternative permission combinations and the 11,000-ID limit; this operation does not paginate, and personal Microsoft accounts do not support it.

No groups claim or a failed overage lookup means **unknown**, not an empty known membership. With no configured group restriction sign-in may proceed, but `groupsKnown('microsoft')` is false. With required groups, unknown membership refuses sign-in with `MS_LOGIN_GROUPS_UNAVAILABLE`; known non-membership gives `MS_LOGIN_GROUP_DENIED`. `inGroup()` throws on unknown membership instead of silently returning false.

Provider policies are checked at sign-in, not continuously. Invalidate existing sessions when tightening restrictions or removing access. An identity lasts as long as the framework session, capped by `session_max_age`, not by the redeemed ID token's expiry.

## Security And Failures

- State, nonce and S256 PKCE are independent per attempt. Only matching state is consumed, before redemption; other tabs and the other provider survive failures and session rotation.
- No provider tokens are persisted. Only Microsoft group resolution temporarily uses the returned access token.
- ID tokens are accepted only from the fixed provider token endpoints over authenticated, verified TLS. Token and Graph requests refuse redirects and require HTTPS, even if a caller asks the HTTP client to follow redirects. This backchannel-only flow relies on that transport for token origin; it does not verify JWT signatures. See [OIDC Core 3.1.3.7](https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation). Never use these internal parsers for browser-submitted tokens; those require signature/JWKS validation.
- Issuer checks remain provider-specific, with Microsoft's tenant/group checks and Google's domain checks preserved. Audience and nonce are one shared rule for both: `aud` must be the client ID alone, `azp` must be the client ID too when present, and the nonce must match the one this sign-in created. The `exp`/`iat`/`nbf` window is one shared rule for both: `exp` and `iat` are required, time claims must be finite JSON numbers, fractional seconds are preserved, and `clock_skew` widens the window at both ends.
- Successful callbacks rotate the session ID and clear `_csrf_token`. Failed account switching preserves the previous identity; a failure redirect does not mean the browser is now signed out.
- Expected callback failures redirect locally. The optional observer receives a `LoginException` with a stable `reason`; raw provider responses and transport exception details are not forwarded. Observer failures do not replace the redirect. Programming `Error`s propagate; disabled/invalid configuration fails at configuration access.

Common failure suffixes are `NO_FLOW`, `STATE_MISMATCH`, `FLOW_EXPIRED`, `PROVIDER_ERROR`, `NO_CODE`, `HTTP_UNAVAILABLE`, `TOKEN_REQUEST_FAILED`, `TOKEN_INVALID`, `NONCE_MISMATCH`, `TOKEN_EXPIRED`, prefixed by `MS_LOGIN_` or `GOOGLE_LOGIN_`. Microsoft additionally uses `TENANT_MISMATCH`, `GROUPS_UNAVAILABLE`, `GROUP_DENIED`; Google uses `DOMAIN_DENIED`. The root disable code is `OIDC_LOGIN_DISABLED`, and selecting a disabled provider raises `OIDC_LOGIN_PROVIDER_DISABLED`.

## Installing

Vendor `extensions/oidc-login/` and `config/oidc-login.php` alongside `http-client`, then remove the generated `config.php` and `extensions.php` from the configured bundle directory (default `cache/`) and warm the application to rebuild them. `tools/clear-cache.php` clears derived cache keys, not those files; apply your normal PHP-FPM/OPcache invalidation too.

The implementation shares protocol mechanics but retains explicit Microsoft and Google validation; no provider registry, arbitrary issuer configuration or core changes were added.
