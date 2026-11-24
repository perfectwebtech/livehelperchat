# Upstream security sync

This fork branched from LiveHelperChat at commit `c2897bac` (2023-02-07). On 2026-11-24 the security fixes made upstream since then, up to `09745cb4` (2026-11-23), were reviewed and ported. Features were not merged.

Of 2,977 upstream commits, 69 security fixes were reviewed. 21 applied cleanly as cherry-picks, keeping the upstream author. 35 were ported by hand. 13 do not apply because the vulnerable code or feature is not in this fork. Each fix is a separate commit whose message names the upstream sha.

To find what is new upstream next time:

```
git remote add upstream https://github.com/LiveHelperChat/livehelperchat.git
git fetch upstream master
git log --since=2026-11-24 --format='%h %cs %s' upstream/master | grep -iE 'secur|xss|csrf|ssrf|inject|escap|sanitiz|redirect|permission|harden|🔒|🛡'
```

## Applied cleanly (cherry-picked)

| upstream | fix |
|---|---|
| 5027f412b, 8ef3c0c6c | escape department name and nick in transfer messages |
| f49766aa2 | reset force_logout on login flows |
| 1ed8de9df | visitor ids always longer than 20 characters |
| 01845dab5 | write access required for saving remarks |
| b10ded5a9 | forgot-password hashes expire after 15 minutes |
| 9e2780d7b | HMAC-SHA256 for password-update and cross-domain logout tokens |
| af97a930a | co-browse CSS proxy: SSRF and origin binding |
| 36932b7db | REST API user responses no longer include the password hash |
| 3263312bd, 576caeb22 | react-to-message: validate message, require write access |
| 83b08fb86 | login-by-token rejects blank or revoked tokens |
| 30a7992fa | cron.php only from the command line, no file inclusion |
| 0241a0008 | theme import and image upload cannot write arbitrary files |
| c44200578 | sessions and remember-me tokens invalidated on password change |
| b1023f232 | visitors cannot send `[html_snippet]` |
| ceb38c955 | HTML stripped from visitor messages |
| 1178bec7c | user CSV import limited to whitelisted fields |
| fcc05cec2 | REST API session tokens expire (30 days, `site.session_token_expire`) |
| 7af080847 | autologin honours back-office IP restrictions |
| d68cb4b55 | exception log only when `site.log_errors` is true |

## Ported by hand

| upstream | fix |
|---|---|
| 4dc3eae7b, 1e3786b42, 9674651c9 | SSRF: webhook file downloads and decode requests are limited to http(s), pinned to the resolved IP, and blocked from private and reserved ranges |
| f1ddb3e51, c7121946b | bot REST API action: opt-in `site.rest_api_limit` (`deny_local`, `allow_host`) |
| d151f8a00, 3d068e58b (footprint) | referrer, current page and footprint URLs: only http(s) is stored and linked |
| 016f48629, 677b4ebaf, 9568b2fcd, b38395046 + template sweep | XSS: escaping in templates, chat email, agent statistics and the dropdown plugin (including the shipped minified bundles) |
| 7a001f4d6 | REST API JSONP callback must be a JavaScript identifier |
| f21cfd5b4 | `[html]` stripped from incoming webhook payloads |
| d02df2733 | screenshot storage needs a valid chat hash and a real PNG |
| f4e0f202a, 1bf208a2f | chat list export needs `export_chats` and a CSRF token |
| cbe94b0c2, 7402ad50b | chat-changing actions need read and write access to the chat |
| 423843363 | archive, role and user endpoints validate ids (SQL identifier from URL) |
| cf572eaed | export API hash empty (export off) on new installs |
| 2ae1b6484 | private group chats are visible only to their members |
| b84f39436 | REST API permission checks on chat, file and operator endpoints; the inverted chat DELETE check is fixed |
| abb5e8f31 | login-as-user resets force_logout |
| ede3520db | constant-time password checks and forgot-password timing |
| 82ad20e6d | autologin: HMAC-SHA256, hash_equals, nonce, mandatory expiry |
| a00f4241f | template compilation cannot be injected through config or translation values |
| 3fd83d73a | single-setting endpoint limited to a whitelist |
| 3d068e58b | CSRF, access-control and open-redirect fixes across endpoints; safe Referer redirects |
| f51f62a9d | open redirects in login and autologin; collected form data is unserialised without objects |
| 83295e53f | REST API session tokens revoked on password change |
| 946477445 | `.htaccess` blocks private folders and passes the Authorization header |
| 04baca653 | unauthenticated `xml/userinfo` view removed |

## Not applicable to this fork

The vulnerable code is in modules or features this fork does not have: mail conversations, department aliases, the webhook dispatcher, the demo view, private forms, exclude permissions, the department edit endpoints, the image-zoom branch, and REST API audit logging. The commits are 0fb7c0615, 42f720cd9, 614eaabed, d0b4dd0b3, e198fe253, 51c52fd30, 6e5cee851, c2b76e756, 19022a61e, cb0778f73, 224b7d9d6, 420089e68, fada1464f, 992b7383a, a9e977699 and e43047f96. 83f5312bd (activity-tracking opt-out) and fc57cfd21 (CAPTCHA refactor) are features, not fixes.

## Changes admins and integrators will notice

- **Autologin links** built outside the app must use `hash_hmac('sha256', implode(',', $data), $secret)` and include an expiry `t`. Old SHA-1 links stop working.
- **REST API session tokens** expire after 30 days and are revoked when the password changes. The mobile app has to log in again.
- **REST API chat and file updates** accept only whitelisted fields. Changing a chat's department or owner needs `lhchat/modifychatcore`.
- **Operators with read-only department access** can no longer close, transfer or otherwise change those chats.
- **Incoming webhooks** no longer download media from localhost or private networks.
- **Direct HTTP access** to `settings/`, `cache/`, `var/` (non-static files), `lib/`, `doc/` and similar folders returns 403.
