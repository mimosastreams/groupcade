// A game's settings choices from the site (categories, rounds, seconds), fetched once per app run.
import { useEffect, useState } from 'react';
import { api, type GameInfo } from './api';

let cache: Promise<GameInfo[]> | null = null;

export function gameConfig<T>(slug: string): Promise<T | null> {
  if (!cache) {
    cache = api.games().then((r) => r.games);
    cache.catch(() => {
      cache = null; // Try again next time.
    });
  }
  return cache.then((gs) => (gs.find((g) => g.slug === slug)?.config as T) || null);
}

export function useGameConfig<T>(slug: string): { cfg: T | null; err: string } {
  const [cfg, setCfg] = useState<T | null>(null);
  const [err, setErr] = useState('');
  useEffect(() => {
    let live = true;
    gameConfig<T>(slug)
      .then((c) => live && (c ? setCfg(c) : setErr('This game isn’t on the site yet.')))
      .catch((e) => live && setErr((e as Error).message));
    return () => {
      live = false;
    };
  }, [slug]);
  return { cfg, err };
}
