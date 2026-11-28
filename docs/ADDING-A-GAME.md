# Adding a game

Groupcade is one WordPress plugin (`plugin/groupcade/`) with a small core and one folder per game.

## What the core does

- Room codes (4 letters, no vowels), the host token, players joining, kicking and leaving.
- Storage and locking: `wp_gc_rooms` holds each room's state as JSON, `wp_gc_players` holds players.
- Moving the game forward. There is no websocket or cron: every request calls `GC_Room::tick()`,
  which asks the room's game `due()` and runs `step()` until nothing is due.
- Trash talk chat, rate limits, the name filter, cleanup 2 hours after a room's last activity.
- Stats (`wp_gc_matches`, `wp_gc_room_log`), shown under Groupcade > Analytics.
- REST API (`groupcade/v1`) and pages: `/<game>/`, `/<game>/CODE`, `/tv/`, `/tv/CODE`, `/join/CODE`.

## What a game provides

A folder `plugin/groupcade/games/<slug>/` with:

- `<slug>.php` that loads the game's classes and calls `GC_Games::register( new My_Game() );`.
  Add one `require_once` line for it in `groupcade.php`.
- A class that extends `GC_Game` (`includes/class-gc-game.php` documents each method):
  - `slug()`, `name()`, `tagline()`
  - `new_state( $params )`: the room's starting state from the host's settings
  - `due()` and `step()`: the timed phases
  - `player_action()`: what a player can send (Trivia: `answer`)
  - `host_action()`: start, settings, next, end, again (the core handles `kick`)
  - `view()`: the JSON phones, the host and the TV get
  - `client_config()`: settings the web client and the iPhone app need (Trivia: categories, rounds, seconds)
- `assets/app.html`: the web client. One self-contained page with player, host and TV views.
  The server fills `{{CONFIG}}`, `{{LOGO}}` and `{{QRLIB}}`. Copy Trivia's as a start: the
  networking, polling, join flow, chat, QR, TV and app bridge parts carry over as they are.

## Rules every game keeps

- The state has `phase`, `t0` and `t1`. `lobby` and `end` are the phases where the host can change
  settings and players can join to play straight away.
- The view never leaks what players should not see yet (Trivia hides the right answer until the reveal).
- The TV view is read only. `/tv/CODE` serves the room's game, so any game works on a TV browser,
  AirPlay from the iPhone app, and later Chromecast.
- The page tells the iPhone app which room it is in with `appPost({ type: 'room', game, code, role })`
  when `window.GROUPCADE_APP` is set.

## The iPhone app

The app reads `GET /wp-json/groupcade/v1/games`. A new game shows up in the app's picker on its own,
but the app's native host screens are per game. Until you add them the app opens the game's web host
screen. To add them:

1. `app/src/games/<slug>/Settings.tsx` and `Host.tsx`. Build the host on `app/src/games/shared/`:
   `LobbyFrame` (join card, TV, players, play too, settings), `GameFrame` (round, clock, host
   controls), `ResultsHeader`, `PointsStandings`, `useHostRun`. Phony Baloney is the simplest example.
2. `app/src/app/<slug>/new.tsx` on `NewRoomScreen`, and a `Stack.Screen` line in `app/_layout.tsx`.
3. An entry in `NATIVE_GAMES` (`app/src/games/index.ts`) with `newRoute`, a branch in
   `app/src/app/room/[code].tsx`, the view's type in `app/src/lib/api.ts`, and any new phases in the
   poll speeds in `app/src/lib/useRoom.ts`.

A new host screen needs a new App Store build.

## Testing

`node plugin/tests/api-smoke.mjs http://your-local-site` plays two short Trivia games through the API.
Add the same kind of script for a new game.
