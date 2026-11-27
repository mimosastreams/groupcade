# Groupcade app (iPhone and Android)

The host's app. Only the host installs it. Players join in their phone's browser with the room code,
and the app puts the game on the TV over AirPlay while the host's phone stays the controller.

Expo SDK 58, Expo Router, TypeScript. Bundle ID `com.groupcade.app` (change it in `app.json` before
the first build if you want a different one).

## Ways to host

Hosting works every way, and the app is just the nicest one:

| Host on | TV options |
| --- | --- |
| The app on iPhone | AirPlay (TV gets its own game screen), Chromecast, any TV browser at groupcade.com/tv |
| The app on Android | Chromecast, a USB-C to HDMI cable (TV gets its own game screen), any TV browser |
| A browser on any phone or computer (groupcade.com/trivia) | Any TV browser, Chromecast from Chrome on Android or a computer, or screen mirroring |

Players always join in their phone browser with the code.

## How the TV works

- `modules/groupcade-tv/` is a small local Expo module that gives the TV its own screen:
  - iOS: `ios/GCExternalDisplaySceneDelegate.swift` is a scene delegate for the
    `UIWindowSceneSessionRoleExternalDisplayNonInteractive` role. When a TV connects (Control Center >
    Screen Mirroring, or a Lightning/USB-C HDMI adapter), iOS gives the TV its own window instead of
    mirroring the phone. That window is a `WKWebView` of the room's TV page (`groupcade.com/tv/CODE/`),
    or an idle Groupcade screen when no room is open. `app.plugin.js` adds the scene role to Info.plist.
  - Android: `android/.../GCTvPresenter.kt` does the same with an Android `Presentation` on any
    presentation display (a USB-C/HDMI adapter or dock, or a wireless display the phone offers as one).
  - `index.ts` lets JS set the TV's page (`setTvRoomUrl`) and hear when a TV connects (`addTvListener`).
- Chromecast: `src/lib/cast.ts` uses `react-native-google-cast` with the site's own Cast receiver (the
  same one the web host page casts to). The app sends the room code over the `urn:x-cast:com.groupcade`
  channel and the Chromecast loads that room's TV view. Needs `GROUPCADE_CAST_APP_ID` (see below).
- The TV page polls the server itself, like any TV browser would. The phone never streams video.
- In Expo Go the native modules are missing; the app still runs, and shows the "TV browser" instructions.

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

## Turning on Chromecast (once)

1. In the [Google Cast SDK Developer Console](https://cast.google.com/publish) ($5 one-time), add a
   **Custom Receiver** app. Receiver URL: `https://groupcade.com/wp-json/groupcade/v1/app?cast=1`.
2. Paste its app ID in WordPress under **Settings > Groupcade** (turns on Cast for web hosts).
3. Put the same ID in `eas.json` as `GROUPCADE_CAST_APP_ID` (it's there, empty, in every build
   profile), then make a new build. Until then the app hides Cast.

New Cast apps only show on Chromecasts registered as test devices in that console until you publish
the app there, so publish it once it works.

## Builds (TestFlight and Google Play)

Same setup as Seen Sort and Mimosa: EAS builds in the cloud, under the `seensorts-team` Expo account.
No Xcode or Android Studio needed. Bundle ID and Android package: `com.groupcade.app` (fixed after
the first upload, so change it in `app.json` before then if you want a different one).

```sh
cd app
npm install
npx eas-cli@latest build --platform ios --profile production
npx eas-cli@latest submit --platform ios --latest
```

For Android, the same with `--platform android` (or `--platform all` for both). The first Android
submit needs a Google Play service account key; EAS walks you through it, as with Mimosa.

The first build asks two things: to create the Expo project `@seensorts-team/groupcade` (say yes; it
writes the project ID into `app.json`, so commit that), and to sign in to Apple and set up signing.
The first iOS submit offers to create the app in App Store Connect. After that, add its App ID to
`eas.json` under `submit.production.ios.ascAppId`, as the other two apps do.

## Quick fixes without a new build (EAS Update)

Builds include `expo-updates` (channel `production`, runtime = app version). Screen and text fixes go
straight to installed apps:

```sh
npx eas-cli@latest update --channel production --message "What changed"
```

Anything native (app.json plugins, the TV module, the Cast app ID, new native packages, the version) still needs a new build.

Review notes worth giving Apple: the app hosts party games natively (room setup, lobby with QR,
live game controls, results), players join from any browser, and the TV gets a separate game screen
through the external display scene. A demo room needs at least one player: open the join link from
the lobby's QR on a second device.
