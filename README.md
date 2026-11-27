# Groupcade

Party games for the big screen. Players join on their phones with a room code, and the host puts the game on the TV.

Game one: **Trivia**, ported from the Survive Trivia Party web game (mimosa-party 0.8.3).

## What's here

- `docs/HANDOFF.md`: decisions so far, the TV/AirPlay plan and open questions. Start here.
- `reference/mimosa-party-0.8.3/`: the working WordPress plugin the Trivia game comes from, imported as-is for reference. It still carries Mimosa names (prefix MPT, REST `mimosa-party/v1`) and should be renamed and rebranded, not deployed as it is.
  - `data/questions.txt`: the question bank (`Easy|Medium|Hard | Category | Question | Right | Wrong | Wrong | Wrong`)
  - `data/new/`: per-category question packs
  - `assets/app.html`: the single-page host, TV and player client (still inlines the Mimosa logo; swap for Groupcade branding)

## Hosting

groupcade.com is WordPress on Pressable (WP Cloud). gametogethr.com and game2night.com should redirect to it.
