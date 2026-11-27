// Games the app can host natively. A game listed by the site but missing here still shows on the
// home screen, and opens the game's web host screen instead (see docs/ADDING-A-GAME.md).
export type AppGame = {
  slug: string;
  name: string;
  tagline: string;
  path: string; // Page path on the site.
  newRoute?: string; // Native "new room" screen.
};

export const NATIVE_GAMES: Record<string, AppGame> = {
  trivia: {
    slug: 'trivia',
    name: 'Trivia',
    tagline: 'Answer on your phone. Survival or Points.',
    path: 'trivia',
    newRoute: '/trivia/new',
  },
};
