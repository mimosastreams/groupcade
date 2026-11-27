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
5. Groupcade > Analytics shows live rooms and stats; Groupcade > Settings has the switches. The Cast app ID stays empty until Chromecast is set up.

Updating: upload the new zip and pick "Replace current with uploaded". Tables update themselves.

### Pressable notes

- The game pages and REST replies send `Cache-Control: no-store` and cancel Batcache, so the edge
  cache doesn't serve stale room state. If a page ever shows an old room, add `/trivia/*`, `/tv/*`
  and `/wp-json/groupcade/*` to any cache exclusions in the Pressable dashboard.
- Phones poll about once a second while a game runs (the same load as mimosa.gg/party today).
- The plugin needs MySQL's `GET_LOCK`, which Pressable has.

### Home page

From 1.1.0 the plugin serves the groupcade.com home page itself (join box, game shelf, how to
host, app links), in place of the theme's front page. Its words and pictures are edited on
Pages > Home (the page set as the front page) with Advanced Custom Fields; the field group is
registered in code (`GC_Home_Fields`), so nothing needs importing in ACF. Groupcade > Settings:

- **Use the Groupcade home page**: on by default. Turn it off to show the theme's front page again.
- **App Store link** and **Google Play link**: while empty, the home page shows "iPhone app coming
  soon" and "Android app coming soon" instead of store buttons.

The game shelf lists every registered game (name, `blurb()`, `tags()`), then "coming soon" cards
so it always shows three. The join box sends players to `/join/CODE/`. The page is
`plugin/groupcade/assets/home.html`. `[groupcade_games]` still works on any other page.

Upgrading to 1.3.0 moves the old Settings > Groupcade home values onto the Home page (making a
Home page and setting it as the front page if there isn't one), keeps a copy in the
`gc_home_content_backup` option, and copies the trivia questions into Groupcade > Questions a few
hundred at a time. Games use the built-in question file until that copy finishes.

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
