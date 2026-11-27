# Putting Groupcade on groupcade.com

## The plugin

1. Build the zip: `./scripts/build-plugin.sh` (makes `dist/groupcade-<version>.zip`).
2. On groupcade.com: Plugins > Add New > Upload Plugin, pick the zip, activate.
3. Settings > Permalinks: anything except "Plain" (the pages need pretty URLs). Saving the page
   once also refreshes the rewrite rules.
4. Check:
   - https://groupcade.com/trivia/ shows the Trivia home page.
   - https://groupcade.com/tv/ shows the TV code screen.
   - https://groupcade.com/wp-json/groupcade/v1/games lists Trivia.
5. Settings > Groupcade shows live rooms and stats. The Cast app ID stays empty until Chromecast is set up.

Updating: upload the new zip and pick "Replace current with uploaded". Tables update themselves.

### Pressable notes

- The game pages and REST replies send `Cache-Control: no-store` and cancel Batcache, so the edge
  cache doesn't serve stale room state. If a page ever shows an old room, add `/trivia/*`, `/tv/*`
  and `/wp-json/groupcade/*` to any cache exclusions in the Pressable dashboard.
- Phones poll about once a second while a game runs (the same load as mimosa.gg/party today).
- The plugin needs MySQL's `GET_LOCK`, which Pressable has.

### Home page

The WordPress home page is yours to design. `[groupcade_games]` is a shortcode that lists the games
with links and the TV address, if you want a quick start.

## Redirecting gametogethr.com and game2night.com

Nothing in this repo can do this; it's hosting and DNS. What's needed:

1. In the Pressable dashboard, open the groupcade.com site > Domains and add `gametogethr.com`,
   `www.gametogethr.com`, `game2night.com` and `www.game2night.com`. Keep `groupcade.com` as the
   primary domain. Pressable sends added domains to the primary domain with a redirect; check that
   after the DNS step, and if it doesn't, use the registrar's forwarding below instead.
2. At the registrar for each domain, point DNS at Pressable using the records the Domains screen
   shows (an A record for the bare domain and a CNAME for `www`).
3. Wait for Pressable to issue the SSL certificates, then check that
   `https://gametogethr.com/trivia/ABCD` lands on `https://groupcade.com/trivia/ABCD` (the path
   should carry over so shared links keep working).

Simpler alternative if you'd rather not add them to Pressable: use the registrar's "URL forwarding"
to `https://groupcade.com` with a 301 and path forwarding on. HTTPS on the old domains then depends
on whether the registrar's forwarding supports it.

## mimosa.gg/party

Untouched. This plugin uses its own names (`groupcade`, `gc_` tables and options, `groupcade/v1`),
so even on the same WordPress it would not clash. It is meant for groupcade.com only.
