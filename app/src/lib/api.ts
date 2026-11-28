// REST client for the Groupcade WordPress plugin (namespace groupcade/v1).
import Constants from 'expo-constants';

/** Site the app talks to. Set EXPO_PUBLIC_API_BASE to point a dev build at a local site. */
export const API_BASE: string = (
  process.env.EXPO_PUBLIC_API_BASE ||
  (Constants.expoConfig?.extra?.apiBase as string | undefined) ||
  'https://groupcade.com'
).replace(/\/$/, '');

const NS = `${API_BASE}/wp-json/groupcade/v1`;

export const links = {
  tv: (code: string) => `${API_BASE}/tv/${code}/`,
  game: (path: string, code: string) => `${API_BASE}/${path}/${code}/`,
  short: (path: string) => `${API_BASE.replace(/^https?:\/\//, '')}/${path}`,
  tvShort: () => `${API_BASE.replace(/^https?:\/\//, '')}/tv`,
};

export class ApiError extends Error {
  status: number;
  code: string;
  constructor(message: string, status: number, code: string) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

type Headers = Record<string, string>;

/** The signed-in host's session (see lib/account.ts). Sent on every call so rooms know their owner. */
let session: string | null = null;
export const setSession = (s: string | null) => {
  session = s;
};

async function call<T>(method: 'GET' | 'POST', path: string, body?: object, headers: Headers = {}): Promise<T> {
  const h: Headers = { 'X-Groupcade': '1', Accept: 'application/json', ...headers };
  if (session) h['X-Groupcade-Session'] = session;
  if (body) h['Content-Type'] = 'application/json';
  const url = NS + path + (method === 'GET' ? (path.includes('?') ? '&' : '?') + '_=' + Date.now() : '');
  let res: Response;
  try {
    res = await fetch(url, { method, headers: h, body: body ? JSON.stringify(body) : undefined });
  } catch {
    throw new ApiError('Can’t reach Groupcade. Check your connection.', 0, 'network');
  }
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new ApiError(json?.message || `Error ${res.status}`, res.status, json?.code || '');
  }
  return json as T;
}

// ------------------------------------------------------------------ types

export type GameInfo = {
  slug: string;
  name: string;
  tagline: string;
  url: string;
  maxPlayers: number;
  config: Record<string, unknown>;
};

export type TriviaConfig = {
  cats: string[];
  specCats: string[];
  rounds: number[];
  secs: number[];
  modes: string[];
};

export type Player = {
  id?: number;
  name: string;
  alive: boolean;
  ko: number;
  best: number;
  in: boolean;
  score: number;
  gain: number;
};

export type Phase = 'lobby' | 'cat' | 'q' | 'rev' | 'end';

export type RoomState = {
  code: string;
  game: string;
  ver: number;
  now: number;
  host: boolean;
  phase: Phase;
  round: number;
  rounds: number;
  secs: number;
  t0: number;
  t1: number;
  grace: number;
  played: number;
  players: Player[];
  alive: number;
  locked: number;
  mode: 'survive' | 'points';
  only: string[];
  allCats?: string[];
  specCats?: string[];
  level?: 'easy' | 'medium' | 'hard';
  cat?: string;
  q?: string;
  choices?: string[];
  last?: {
    round: number;
    right: number;
    counts: number[];
    out: string[];
    outN: number;
    revived: boolean;
    left: number;
    nright?: number;
    fast: { name: string; ms: number } | null;
  };
  winners?: string[];
  stopped?: boolean;
  /** The host's own question pack in play, if any. */
  pack?: { id: number; name: string } | null;
  /** The room belongs to a signed-in host, so its games are saved to their history. */
  owned?: boolean;
  /** The caller's own player, when the request carried their player token. ans: 1-4 locked in, 0 = not yet. */
  me?: { name: string; alive: boolean; ans: number; score: number; gain: number };
};

// ------------------------------------------------------------------ calls

// ------------------------------------------------------------------ accounts

export type User = {
  id: number;
  name: string;
  email: string;
  relay: boolean;
  news: boolean;
  via: string[];
  plan: string;
  founding: boolean;
  since: number;
};

export type AuthConfig = {
  apple: { web: string; bundle: string };
  google: { web: string; ios: string };
  email: boolean;
};

export type HistoryGame = {
  id: number;
  game: string;
  name: string;
  mode: string;
  at: number;
  mins: number;
  players: number;
  rounds: number;
  winners: string[];
  stopped: boolean;
  top: { n: string; s: number; w: number }[];
};
export type History = { games: HistoryGame[]; total: number; board: { name: string; wins: number; games: number }[] };

export type PackSummary = { id: number; game: string; name: string; count: number; updated: number };
export type PackQuestion = { level: 'easy' | 'medium' | 'hard'; cat: string; q: string; right: string; wrong: string[] };
export type Pack = { id: number; game: string; name: string; count: number; max: number; questions: PackQuestion[] };

type SignedIn = { session: string; user: User };

export const accountApi = {
  config: () => call<AuthConfig>('GET', '/auth/config'),
  apple: (body: { id_token: string; code?: string | null; name?: string; device?: string }) => call<SignedIn>('POST', '/auth/apple', body),
  google: (body: { id_token: string; device?: string }) => call<SignedIn>('POST', '/auth/google', body),
  emailStart: (email: string) => call<{ ok: boolean; minutes: number }>('POST', '/auth/email/start', { email }),
  emailVerify: (email: string, code: string, device?: string) => call<SignedIn>('POST', '/auth/email/verify', { email, code, device }),
  logout: (all = false) => call<{ ok: boolean }>('POST', '/auth/logout', { all }),
  me: () => call<{ user: User | null }>('GET', '/me'),
  update: (p: { name?: string; news?: boolean }) => call<{ user: User }>('POST', '/me', p),
  remove: () => call<{ ok: boolean }>('POST', '/me/delete', {}),
  prefs: <T>(game: string) => call<{ settings: T | null }>('GET', `/me/prefs?game=${encodeURIComponent(game)}`),
  savePrefs: (game: string, settings: object) => call<{ settings: object }>('POST', '/me/prefs', { game, settings }),
  history: () => call<History>('GET', '/me/history'),
  packs: () => call<{ packs: PackSummary[]; max: number; maxQuestions: number }>('GET', '/me/packs'),
  pack: (id: number) => call<{ pack: Pack }>('GET', `/me/packs/${id}`),
  newPack: (name: string) => call<{ pack: Pack }>('POST', '/me/packs', { name }),
  savePack: (id: number, p: { name?: string; questions?: PackQuestion[] }) => call<{ pack: Pack }>('POST', `/me/packs/${id}`, p),
  deletePack: (id: number) => call<{ ok: boolean }>('POST', `/me/packs/${id}/delete`, {}),
  claim: (code: string, host: string) => call<{ ok: boolean; games: number }>('POST', `/rooms/${code}/claim`, {}, { 'X-Party-Host': host }),
};

export const api = {
  games: () => call<{ games: GameInfo[]; tv: string }>('GET', '/games'),

  createRoom: (game: string, settings: object) =>
    call<{ code: string; host: string; game: string }>('POST', '/rooms', { game, ...settings }),

  state: (code: string, host?: string | null, token?: string | null) => {
    const h: Headers = {};
    if (host) h['X-Party-Host'] = host;
    if (token) h['X-Party-Token'] = token;
    return call<RoomState & { gone?: boolean }>('GET', `/rooms/${code}`, undefined, h);
  },

  host: (code: string, host: string, action: string, extra: object = {}) =>
    call<RoomState>('POST', `/rooms/${code}/host`, { action, ...extra }, { 'X-Party-Host': host }),

  answer: (code: string, token: string, round: number, choice: number) =>
    call<{ ok: boolean }>('POST', `/rooms/${code}/action`, { type: 'answer', round, choice }, { 'X-Party-Token': token }),

  join: (code: string, name: string) =>
    call<{ token: string; name: string; late?: boolean }>('POST', `/rooms/${code}/join`, { name }),
};
