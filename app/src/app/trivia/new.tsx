// New Trivia room: pick settings, make the room, go to the host screen.
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator } from 'react-native';
import { DEFAULT_SETTINGS, TriviaSettingsForm, type TriviaSettings } from '../../games/trivia/Settings';
import { api, type TriviaConfig } from '../../lib/api';
import { store } from '../../lib/store';
import { Banner, Button, C, Card, Screen } from '../../ui/kit';

export default function NewTrivia() {
  const [cfg, setCfg] = useState<TriviaConfig | null>(null);
  const [settings, setSettings] = useState<TriviaSettings>(DEFAULT_SETTINGS);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  useEffect(() => {
    store.settings<TriviaSettings>('trivia').then((s) => s && setSettings({ ...DEFAULT_SETTINGS, ...s }));
    api
      .games()
      .then((r) => {
        const g = r.games.find((x) => x.slug === 'trivia');
        if (g) setCfg(g.config as TriviaConfig);
      })
      .catch((e) => setErr(e.message));
  }, []);

  const change = (p: Partial<TriviaSettings>) => {
    const next = { ...settings, ...p };
    setSettings(next);
    store.saveSettings('trivia', next);
  };

  const make = async () => {
    setBusy(true);
    setErr('');
    try {
      // Drop categories this site no longer has.
      const known = cfg ? [...cfg.cats, ...cfg.specCats] : [];
      const cats = settings.cats.filter((c) => known.includes(c));
      const r = await api.createRoom('trivia', { mode: settings.mode, rounds: settings.rounds, secs: settings.secs, cats: cats.length ? cats : undefined });
      await store.saveRoom({ code: r.code, host: r.host, game: 'trivia', path: 'trivia', made: Date.now() });
      router.replace({ pathname: '/room/[code]', params: { code: r.code } });
    } catch (e) {
      setErr((e as Error).message);
      setBusy(false);
    }
  };

  return (
    <Screen footer={<Button kind="go" title="Make a room" onPress={make} busy={busy} disabled={!cfg} />}>
      {err ? <Banner tone="bad">{err}</Banner> : null}
      <Card>
        {cfg ? (
          <TriviaSettingsForm value={settings} onChange={change} allCats={cfg.cats} specCats={cfg.specCats} />
        ) : !err ? (
          <ActivityIndicator color={C.accent} />
        ) : null}
      </Card>
    </Screen>
  );
}
