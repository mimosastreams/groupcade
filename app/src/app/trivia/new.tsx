// New Trivia room: pick settings, make the room, go to the host screen.
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { DEFAULT_SETTINGS, noCats, TriviaSettingsForm, type TriviaSettings } from '../../games/trivia/Settings';
import { useAccount } from '../../lib/account';
import { accountApi, api, type PackSummary, type TriviaConfig } from '../../lib/api';
import { store } from '../../lib/store';
import { Banner, Button, C, Card, Chip, F, P, Screen, TopBar } from '../../ui/kit';

export default function NewTrivia() {
  const insets = useSafeAreaInsets();
  const [cfg, setCfg] = useState<TriviaConfig | null>(null);
  const [settings, setSettings] = useState<TriviaSettings>(DEFAULT_SETTINGS);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const { user } = useAccount();
  const [packs, setPacks] = useState<PackSummary[]>([]);
  const [pack, setPack] = useState(0);

  // Signed-in hosts get their saved setup (from the app or the website) and their question packs.
  useEffect(() => {
    if (!user) return;
    accountApi
      .prefs<TriviaSettings & { pack?: number }>('trivia')
      .then((r) => {
        if (!r.settings) return;
        const { pack: p, ...rest } = r.settings;
        setSettings((s) => ({ ...s, ...rest }));
        setPack(p || 0);
      })
      .catch(() => {});
    accountApi
      .packs()
      .then((r) => setPacks(r.packs.filter((k) => k.count > 0)))
      .catch(() => {});
  }, [user?.id]);

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
    if (noCats(settings)) return;
    setBusy(true);
    setErr('');
    try {
      // Drop categories this site no longer has.
      const known = cfg ? [...cfg.cats, ...cfg.specCats] : [];
      const cats = settings.cats.filter((c) => known.includes(c));
      const usePack = packs.some((k) => k.id === pack) ? pack : 0;
      const r = await api.createRoom('trivia', { mode: settings.mode, rounds: settings.rounds, secs: settings.secs, cats: cats.length ? cats : undefined, pack: usePack || undefined });
      if (user) accountApi.savePrefs('trivia', { mode: settings.mode, rounds: settings.rounds, secs: settings.secs, cats, pack: usePack }).catch(() => {});
      await store.saveRoom({ code: r.code, host: r.host, game: 'trivia', path: 'trivia', made: Date.now() });
      router.replace({ pathname: '/room/[code]', params: { code: r.code } });
    } catch (e) {
      setErr((e as Error).message);
      setBusy(false);
    }
  };

  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      <Screen top={<TopBar title="New Trivia room" onBack={() => router.back()} />}>
        {err ? <Banner tone="bad">{err}</Banner> : null}
        {cfg ? (
          <>
            <TriviaSettingsForm value={settings} onChange={change} allCats={cfg.cats} specCats={cfg.specCats} />
            <PackPicker signedIn={!!user} packs={packs} value={pack} onChange={setPack} />
          </>
        ) : !err ? (
          <ActivityIndicator color={C.accent} style={{ marginTop: 40 }} />
        ) : null}
      </Screen>
      <View style={{ borderTopWidth: 3, borderTopColor: C.ink, backgroundColor: C.sky, paddingHorizontal: 20, paddingTop: 16, paddingBottom: insets.bottom + 16 }}>
        <Button kind="go" big title="Make a room" onPress={make} busy={busy} disabled={!cfg || noCats(settings)} />
      </View>
    </View>
  );
}

/** Signed-in hosts can play their own question pack. Signed-out hosts get a gentle pointer. */
function PackPicker({ signedIn, packs, value, onChange }: { signedIn: boolean; packs: PackSummary[]; value: number; onChange: (id: number) => void }) {
  if (!signedIn) {
    return (
      <Pressable onPress={() => router.push('/account')} accessibilityRole="link">
        <P small muted center style={{ marginTop: 6 }}>
          <Text style={{ fontFamily: F.heavy, color: C.ink, textDecorationLine: 'underline' }}>Sign in</Text> to save your setup, keep your game history and play your own questions. Optional.
        </P>
      </Pressable>
    );
  }
  return (
    <Card style={{ gap: 10 }}>
      <Text style={{ fontFamily: F.display, fontSize: 20, color: C.ink }}>Your question pack</Text>
      {packs.length ? (
        <>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            <Chip text="None" on={!value} onPress={() => onChange(0)} />
            {packs.map((k) => (
              <Chip key={k.id} text={`${k.name} (${k.count})`} on={value === k.id} onPress={() => onChange(k.id)} />
            ))}
          </View>
          <P small muted>Your questions come first, then regular ones fill any rounds left.</P>
        </>
      ) : (
        <Pressable onPress={() => router.push('/account')} accessibilityRole="link">
          <P small muted>
            Write your own questions in <Text style={{ fontFamily: F.heavy, color: C.ink, textDecorationLine: 'underline' }}>your account</Text>.
          </P>
        </Pressable>
      )}
    </Card>
  );
}
