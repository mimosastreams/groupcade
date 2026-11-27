# Groupcade iPhone app

The host's app. Only the host installs it. Players join in their phone's browser with the room code,
and the app puts the game on the TV over AirPlay while the host's phone stays the controller.

Expo SDK 58, Expo Router, TypeScript. Bundle ID `com.groupcade.app` (change it in `app.json` before
the first build if you want a different one).

## How the TV works

- `modules/groupcade-tv/` is a small local Expo module:
  - `ios/GCExternalDisplaySceneDelegate.swift` is a scene delegate for the
    `UIWindowSceneSessionRoleExternalDisplayNonInteractive` role. When a TV connects (Control Center >
    Screen Mirroring, or a Lightning/USB-C HDMI adapter), iOS gives the TV its own window instead of
    mirroring the phone. That window is a `WKWebView` of the room's TV page (`groupcade.com/tv/CODE/`),
    or an idle Groupcade screen when no room is open.
  - `app.plugin.js` adds that scene role to Info.plist at prebuild, next to the app's own scene
    (SDK 58's template already uses the scene life cycle, so nothing else changes).
  - `index.ts` lets JS set the TV's page (`setTvRoomUrl`) and hear when a TV connects (`addTvListener`).
- The TV page polls the server itself, like any TV browser would. The phone never streams video.
- In Expo Go the native module is missing; the app still runs, and shows the "TV browser" instructions.

## Screens (all native, so the app is more than a wrapped website)

- `src/app/index.tsx`: pick a game, resume your open room, or join someone else's game by code.
- `src/app/trivia/new.tsx`: mode, rounds, seconds, categories. Makes the room.
- `src/app/room/[code].tsx` with `src/games/trivia/Host.tsx`: code, QR and share link, TV status,
  players (tap to remove), settings, start; then round, question, timer, locked-in count, reveal,
  still-in list or scores, next round, end game; then winners, standings, play again.
- `src/app/play/[code].tsx`: a web view of the player page, for when the host plays too (the app signs
  the page in with its tokens, and the page's "Host screen" button comes back to the native screen),
  or for joining someone else's game.

## Running it

```
cd app
npm install
npx expo prebuild --platform ios   # makes ios/ (gitignored)
npx expo run:ios                   # simulator or a plugged-in iPhone, on a Mac with Xcode
```

To point a dev build at a local WordPress: `EXPO_PUBLIC_API_BASE=http://192.168.x.x:8080 npx expo start --dev-client`.

### Testing the TV without a TV

In the iOS Simulator: I/O > External Displays > 1920×1080. The second window shows what the TV
would get. On a real iPhone, AirPlay to an Apple TV or an AirPlay 2 TV, or use an HDMI adapter.

## Store builds

`eas.json` has development, simulator, preview and production profiles. The first time, run
`npx eas-cli@latest init` to make the Expo project (it adds the project ID to `app.json`). After that,
the usual `eas build --platform ios --profile production` and `eas submit --platform ios`.

Review notes worth giving Apple: the app hosts party games natively (room setup, lobby with QR,
live game controls, results), players join from any browser, and the TV gets a separate game screen
through the external display scene. A demo room needs at least one player: open the join link from
the lobby's QR on a second device.
