# Host accounts

Hosts can sign in to save their setup, keep a game history and write their own question packs.
It's optional: hosting works without an account, and players never sign in. The plan is in the
"Groupcade host accounts plan" doc.

## How it works

- A host is a WordPress user with the **Groupcade host** role (`gc_host`). They have no WordPress
  password and can't reach wp-admin. See them at Users, filtered by that role.
- Sign-in: **Sign in with Apple**, **Google**, or a **6-digit code by email**. No passwords.
  A Google or email sign-in with the same verified email as an existing host joins that account.
- The plugin gives out its own session token (90 days, renewed as it's used). The app keeps it in
  the phone's secure storage and sends it as `X-Groupcade-Session`. The website keeps it in the
  `gc_session` cookie (http-only).
- A room records its owner when it's made. A guest host can save a room after the game from the
  "Keep this game?" card (the room's games so far move to their history).
- Phones' room polls (the fast path) never look at accounts.
- Everyone who signs up before paid plans launch gets a **founding** entitlement
  (`wp_gc_entitlements`), so early hosts can be thanked later. Turn on "Paid plans have launched"
  in Groupcade > Settings when paid plans start.

Pages: `/account/` (sign in, games, packs, delete) and `/account/delete/` (for the store listings).

Tables: `gc_identities`, `gc_sessions`, `gc_email_codes`, `gc_entitlements`, `gc_packs`,
`gc_pack_questions`, plus `owner_id` on `gc_rooms` and `owner_id` and `standings` on `gc_matches`.
Saved settings are user meta (`gc_prefs_trivia`).

## Setup (one time)

Until these are filled in, only email codes show. Each button appears once its IDs are saved in
**Groupcade > Settings > Host sign-in**.

### Email codes

Pressable's built-in mail tends to land in spam. Set up a sending service (Postmark or Amazon SES)
with an SMTP plugin such as WP Mail SMTP, sending from `hello@groupcade.com`. Then try the email
sign-in at groupcade.com/account/ with your own address.

### Sign in with Apple (developer.apple.com > Certificates, IDs & Profiles)

1. **Identifiers > App IDs > com.groupcade.app**: turn on **Sign In with Apple**. (EAS also turns
   it on during the next build, because the app now asks for it.)
2. **Identifiers > Services IDs > +**: make one for the website, for example `com.groupcade.web`.
   Turn on Sign In with Apple, Configure: primary App ID `com.groupcade.app`, domain
   `groupcade.com`, return URL `https://groupcade.com/account/`.
3. **Keys > +**: a key with Sign In with Apple (primary App ID `com.groupcade.app`). Download the
   `.p8` file and note the Key ID. Your Team ID is at the top right of the developer site.
4. In Groupcade > Settings: Services ID, Team ID, Key ID, and paste the whole `.p8` file. The key
   is used to cancel Apple's sign-in when a host deletes their account, which Apple requires.

### Google (console.cloud.google.com)

1. Make a project (or use an existing one) and set up the **OAuth consent screen** (External,
   app name Groupcade, support email, `groupcade.com` as the authorized domain, privacy policy URL).
2. **Credentials > Create credentials > OAuth client ID**, three times:
   - **Web application**: authorized JavaScript origin `https://groupcade.com`. Copy the client
     ID to "Google: web client ID". The Android app uses it too.
   - **iOS**: bundle ID `com.groupcade.app`. Copy the client ID to "Google: iOS client ID" on the
     site **and** to `app/app.json` at `expo.extra.googleIosClientId`, then make a new build (the
     iPhone app needs it baked in; until then it hides the Google button).
   - **Android**: package `com.groupcade.app` and the SHA-1 of the app signing key (Play Console >
     App integrity, or `eas credentials`). Nothing to copy; it just needs to exist.

## App Store and Google Play

- **App Store Connect > App Privacy**: collected data, all linked to the user, none used for
  tracking: Contact info (email address, name), Identifiers (user ID), User content (other: question
  packs, game results). Purpose: App functionality.
- Account deletion is in the app: Your account > Delete account.
- **Play Console > Data safety**: same data, and the deletion URL
  `https://groupcade.com/account/delete/`.
- The privacy policy should mention host accounts, email, and deletion. A draft is in
  `docs/PRIVACY-DRAFT.md`; paste it into the site's Privacy Policy page (Settings > Privacy).

## Testing locally

`plugin/tests/accounts-smoke.mjs` runs against a throwaway local site with
`plugin/tests/gc-test-helpers.php` in `wp-content/mu-plugins/` (it fakes the mail and Apple/Google
keys; never put it on the real site):

    node plugin/tests/accounts-smoke.mjs http://127.0.0.1:8899 /path/to/site/wp-content
