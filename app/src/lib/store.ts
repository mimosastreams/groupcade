// What the app remembers on this phone: the host's last room (so a crash or a closed app can
// get back in), the player token if the host also plays, and the last settings used.
import AsyncStorage from '@react-native-async-storage/async-storage';

export type SavedRoom = {
  code: string;
  host: string;
  game: string;
  path: string; // Page path on the site, e.g. 'trivia'.
  made: number;
};

const ROOM = 'gc:room';
const ROOM_MAX_AGE = 12 * 60 * 60 * 1000; // Rooms expire 2 h after their last activity; the home screen checks.

async function getJson<T>(key: string): Promise<T | null> {
  try {
    const v = await AsyncStorage.getItem(key);
    return v ? (JSON.parse(v) as T) : null;
  } catch {
    return null;
  }
}

async function setJson(key: string, value: unknown) {
  try {
    if (value === null) await AsyncStorage.removeItem(key);
    else await AsyncStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage is a convenience; the game works without it.
  }
}

export const store = {
  async room(): Promise<SavedRoom | null> {
    const r = await getJson<SavedRoom>(ROOM);
    return r && Date.now() - r.made < ROOM_MAX_AGE ? r : null;
  },
  saveRoom: (r: SavedRoom) => setJson(ROOM, r),
  clearRoom: () => setJson(ROOM, null),

  /** Player token when the host joins their own game to play. */
  playerToken: (code: string) => getJson<string>(`gc:tok:${code}`),
  savePlayerToken: (code: string, token: string | null) => setJson(`gc:tok:${code}`, token),

  name: () => getJson<string>('gc:name'),
  saveName: (n: string) => setJson('gc:name', n),

  settings: <T>(game: string) => getJson<T>(`gc:settings:${game}`),
  saveSettings: (game: string, s: unknown) => setJson(`gc:settings:${game}`, s),
};
