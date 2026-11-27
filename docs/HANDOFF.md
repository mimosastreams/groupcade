# Groupcade handoff brief (from the Mimosa project, 2026-11-26)

**What it is:** Groupcade, a standalone party games app and site, not Mimosa-branded. Game one is the party trivia game from mimosa.gg/party, renamed just "Trivia". It should be built so more party games can be added later.

**Name and domains:** Groupcade is the brand, at groupcade.com. It's live as a fresh WordPress install on Pressable/WP Cloud ("My WordPress Site", nothing built yet). Dylan also owns gametogethr.com and game2night.com, which should redirect to groupcade.com.

**Repo:** https://github.com/mimosastreams/groupcade (empty or new). The Mimosa app repo (mimosastreams/mimosa-app) is reference only. Don't add Groupcade to it.

**Main goal: iPhone to TV.** Everyone who played asked for a way to put the game on the TV from an iPhone. Safari can't do that for a web page, so it needs a native app:
- Only the host installs the app. Players still join in their phone browser with a 4-letter code or a link, no install.
- AirPlay second screen: add the UIWindowSceneSessionRoleExternalDisplayNonInteractive scene in Info.plist and a small Swift scene delegate that shows a WKWebView of the room's TV view (the ?code=XXXX&tv=1 page). The host's phone stays the controller. Expo SDK 58 uses the scene lifecycle, and ExpoAppDelegate doesn't override configurationForConnecting, so the plist route works.
- The party page posts {code} to window.ReactNativeWebView when an app flag is set, so the app learns the room code.
- Apple rejects apps that are only a wrapped website, so host screens (create room, settings, lobby with QR, start) should be native.
- Later: Chromecast through Google Cast with the TV page as the receiver ($5 Cast registration), then Android once Dylan has a Google Play account. Any TV browser can already open the /tv page with a code.

**Recommended stack:**
- Backend: a renamed copy of the party WordPress plugin on groupcade.com.
- App: Expo (the same stack as the Mimosa app and SeenSort), with a new bundle ID and a new Expo project.
- Dylan runs store builds and submits himself, on his existing Apple developer account (to be confirmed).

**Game as it is today (mimosa-party 0.8.3, live at mimosa.gg/party and /party/tv):**
- Host makes a room and gets a code, link and QR. Players join on their phones and answer there.
- Two modes. Survival: a wrong answer puts you out, and if everyone misses, nobody goes out. Points: a 5-second read period first.
- Question time is 15, 20 or 30 seconds, and the reveal shows for 7 seconds. Rounds are 10, 15 or 20, or "until one left". Questions get harder through the game (easy, then medium, then hard).
- Also built: a host category picker, a specialty Christmas Movies category that's off by default, swipe-to-lock answers, trash talk chat, a still-in bar, and a stats page.
- Limits: 50 players per room, rooms deleted 2 hours after the last activity.
- REST namespace mimosa-party/v1: rooms, join, answer, host actions, leave. Phones poll about once a second. Own tables, wp_mpt_rooms and wp_mpt_players.
- It must stay separate from the Twitch stream's Survive Trivia scores and records. The MIMOSA category (questions about Dylan) is excluded.

**Where the code lives (Mimosa project files, not visible to the new project):**
- Plugin source: /mnt/project-files/site/mimosa-party/ (mimosa-party.php, includes/, assets/app.html, data/questions.txt with about 2,668 lines of questions)
- Zips: /mnt/project-files/site/mimosa-party-0.8.3.zip
- Question format: Easy|Medium|Hard | Category | Question | Right | Wrong | Wrong | Wrong
- To move it over, push the plugin source into the groupcade repo from the Mimosa project, or upload the 0.8.3 zip to the new project.

**Keep mimosa.gg/party live** as it is.

**Open questions for Dylan:** free or a one-time unlock, whether he has an Apple TV or AirPlay TV to test on, and which Apple developer account to use.

## Status (2026-11-27)

- **Plugin:** `plugin/groupcade/` is the Groupcade version of the party plugin, set up for more games
  (core in `includes/`, Trivia in `games/trivia/`). Pages `/trivia/` and `/tv/`, REST `groupcade/v1`.
  Install steps and the domain redirects are in `docs/DEPLOY.md`.
- **iPhone app:** `app/` (Expo SDK 58). Native host screens, AirPlay second screen through the
  `UIWindowSceneSessionRoleExternalDisplayNonInteractive` scene in `app/modules/groupcade-tv/`.
  The page's app flag is `window.GROUPCADE_APP` and it posts `{groupcade:1, type:'room', game, code, role}`.
  Bundle ID `com.groupcade.app` until Dylan says otherwise.
- **Brand (plugin 1.2.0):** the TV mascot and three phone friends, files in `brand/`. Home page is
  design C "Sticker Book"; every word and picture is edited on Pages > Home with ACF
  (`GC_Home::fields()` lists them; `GC_Home_Fields` builds the ACF field group from that list).
- **Admin (plugin 1.3.0):** a top-level Groupcade menu: Questions (post type `gc_question`,
  categories `gc_question_cat`), Analytics and Settings. Trivia reads questions from there.
- **Still open:** free or a one-time unlock; an Apple TV or AirPlay TV to test on; which Apple
  developer account.
