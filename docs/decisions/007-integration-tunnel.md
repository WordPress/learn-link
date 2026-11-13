# 007. The temporary tunnel for the integration day

- **Status:** Accepted
- **Date:** 2026-11-12
- **Decided by:** the team (Wilberth, Rafael and Pedro), after the tunnel test on 12 November
- **Issue:** #26

## Context

Handbook section 10 plans one integration day against staging, around 12 November, and Phase 2 is done when a test student connects on staging and sees their local Learn grades. The staging Dashboard (`https://learnlinktest.wpcomstaging.com`) is on the internet, but the Learn half runs only on a team member's computer, at `http://localhost:8882`. The staging site and the student's browser both have to reach it.

Section 7.6 of the technical guide says: "Use a temporary tunnel (for example Cloudflare Tunnel or ngrok), only while you test and only with invented data. Record the choice in a decision record." The team had not used either tool on this computer before, so the choice was checked in a tunnel test, planned for 10 November and run on 12 November.

## Decision

Use a Cloudflare quick tunnel, started by hand with `cloudflared tunnel --url http://localhost:8882`, only during the test sessions and only with invented data. Rafael runs it on the computer that hosts the local Learn, and stops it as soon as each session ends.

## Alternatives considered

- **ngrok:** it does the same job, but it needs an account and an auth token on the computer that runs it. A quick tunnel needs neither, so there is one less credential to look after for a few days of testing. It was kept as the fallback, and was not needed.
- **A named Cloudflare Tunnel:** it gives a stable address, but it needs a Cloudflare account and a domain, which is more setup than a few test sessions justify, and it invites leaving the tunnel in place.
- **No tunnel, only the two local sites:** this cannot meet the Phase 2 criterion, because the Dashboard under test is on staging. It was used for the local run of the six cases on 12 November, before the staging run, and would have been the fallback if staging or the tunnel had not been ready.

## Consequences

- The address is random and changes each time the tunnel restarts, so `learn_base_url` on staging, and the address in the local Learn (see below), have to be set again after every restart. Keep the tunnel running for the whole session.
- The OAuth `redirect_uri` belongs to staging, not to the tunnel, so the client entry in the local Learn does not change when the tunnel address does.
- Learn builds its links from `siteurl`, so the consent screen and the sign-in screen need the tunnel address. The test, planned for 10 November and run on 12 November, confirmed that it is needed: through the tunnel, the sign-in form posted to `https://localhost:8882/wp-login.php`, which only exists on Rafael's computer.
- Setting `WP_HOME` and `WP_SITEURL` in the local site's `wp-config.php` was not enough on WordPress Studio. Its web server defines both as `http://localhost:8882` before `wp-config.php` runs, so the new values applied to `studio wp` but not to browser requests. A temporary must-use plugin, `wp-content/mu-plugins/learn-link-tunnel.php`, rewrites `http://localhost:8882` to the tunnel address in `site_url`, `home_url` and `admin_url`, only for requests that arrive through the tunnel (those with `X-Forwarded-Proto`), so the local address keeps working. `wp-config.php` also sets `$_SERVER['HTTPS']` from `X-Forwarded-Proto`, so WordPress builds `https` links behind the tunnel. With both, the sign-in form and the `wp-admin` redirect stay on the tunnel.
- While the tunnel is up, the local Learn is reachable from the internet. It must hold invented data only, and no token or code may appear in a screenshot or a log that we share.
- Quick tunnels are meant for testing and come with no uptime guarantee. If one drops during the day, restart it and set `learn_base_url` again, and note the interruption in `docs/integration-day.md`.
- After the last session, the tunnel is off, `WP_HOME` and `WP_SITEURL` are reverted, the must-use plugin is deleted, and this is confirmed in #26.
