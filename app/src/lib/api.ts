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

async function call<T>(method: 'GET' | 'POST', path: string, body?: object, headers: Headers = {}): Promise<T> {
  const h: Headers = { 'X-Groupcade': '1', Accept: 'application/json', ...headers };
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
  /** The caller's own player, when the request carried their player token. ans: 1-4 locked in, 0 = not yet. */
  me?: { name: string; alive: boolean; ans: number; score: number; gain: number };
};

// ------------------------------------------------------------------ calls

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
