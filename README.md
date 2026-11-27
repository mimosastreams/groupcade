# Groupcade

Party games for the big screen. Players join on their phones with a room code, and the host puts the game on the TV.

Game one: **Trivia**, ported from the Survive Trivia Party web game (mimosa-party 0.8.3).

## What's here

- `docs/HANDOFF.md`: decisions so far, the TV/AirPlay plan and open questions. Start here.
- `plugin/groupcade/`: the WordPress plugin that runs groupcade.com's games.
  - `includes/`: the multi-game core (rooms, players, chat, limits, REST API `groupcade/v1`, pages, stats).
  - `games/trivia/`: Trivia (rules, question bank in `data/questions.txt`, web client in `assets/app.html`).
  - Pages: `/trivia/` (and `/trivia/CODE`), `/tv/` (and `/tv/CODE`) for the big screen, `/join/CODE`.
- `app/`: the iPhone host app (Expo). Native host screens, and the TV over AirPlay as a second screen. See `app/README.md`.
- `plugin/tests/api-smoke.mjs`: plays two short games against a running site through the API.
- `scripts/build-plugin.sh`: zips the plugin for upload.
- `docs/DEPLOY.md`: installing on groupcade.com, and what the gametogethr.com / game2night.com redirects need.
- `docs/ADDING-A-GAME.md`: how a second game plugs in.
- `reference/mimosa-party-0.8.3/`: the original plugin, kept for reference only. Not deployed.

## Testing the plugin locally

Any WordPress with pretty permalinks works. Activate the plugin, open `/trivia/`, then run:

```
node plugin/tests/api-smoke.mjs http://localhost:8080
```

## Hosting

groupcade.com is WordPress on Pressable (WP Cloud). gametogethr.com and game2night.com should redirect to it (see `docs/DEPLOY.md`).
