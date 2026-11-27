import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { api, ApiError, type RoomState } from './api';

/**
 * Polls a room as its host, about once a second while a game runs (the same pace as the web
 * client), slower in the lobby, paused while the app is in the background.
 */
export function useRoom(code: string, host: string | null) {
  const [st, setSt] = useState<RoomState | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [offline, setOffline] = useState(false);
  const offset = useRef(0); // Server clock minus phone clock.
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const seq = useRef(0);
  const active = useRef(AppState.currentState === 'active');
  const latest = useRef<RoomState | null>(null);

  const delay = (s: RoomState | null) => {
    if (!s) return 2000;
    const base = { lobby: 2000, cat: 1000, q: 1000, rev: 1200, end: 3000 }[s.phase] ?? 2000;
    let left = s.t1 ? s.t1 - (Date.now() + offset.current) : 99999;
    if (s.phase === 'q') left += s.grace;
    return left > 0 && left < base ? Math.max(150, left + 150) : base;
  };

  const poll = useCallback(async () => {
    if (timer.current) clearTimeout(timer.current);
    if (!host || !active.current) return;
    const my = ++seq.current;
    try {
      const s = await api.state(code, host);
      if (my !== seq.current) return;
      offset.current = s.now - Date.now();
      latest.current = s;
      setSt(s);
      setError(null);
      setOffline(false);
      timer.current = setTimeout(poll, delay(s));
    } catch (e) {
      if (my !== seq.current) return;
      const err = e as ApiError;
      if (err.status === 404) {
        setError(err);
        return; // Room is gone; stop polling.
      }
      setOffline(true);
      timer.current = setTimeout(poll, 3000);
    }
  }, [code, host]);

  useEffect(() => {
    poll();
    const sub = AppState.addEventListener('change', (s) => {
      active.current = s === 'active';
      if (active.current) poll();
      else if (timer.current) clearTimeout(timer.current);
    });
    return () => {
      sub.remove();
      seq.current++;
      if (timer.current) clearTimeout(timer.current);
    };
  }, [poll]);

  /** Send a host action, show the result at once, then keep polling. */
  const act = useCallback(
    async (action: string, extra: object = {}) => {
      if (!host) return;
      const s = await api.host(code, host, action, extra);
      offset.current = s.now - Date.now();
      seq.current++;
      latest.current = s;
      setSt(s);
      poll();
      return s;
    },
    [code, host, poll]
  );

  const now = useCallback(() => Date.now() + offset.current, []);

  return { st, error, offline, act, now, refresh: poll };
}
