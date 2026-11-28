// The "new room" screen for games with simple settings (Phony Baloney, Scribble Squad): pick
// settings, make the room, go to the host screen. Trivia has its own (modes, question packs).
import { router } from 'expo-router';
import { useEffect, useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAccount } from '../../lib/account';
import { accountApi, api } from '../../lib/api';
import { useGameConfig } from '../../lib/gameConfig';
import { store } from '../../lib/store';
import { Banner, Button, C, F, P, Screen, TopBar } from '../../ui/kit';

export function NewRoomScreen<S extends object, Cfg>(p: {
  slug: string;
  title: string;
  defaults: S;
  intro?: string;
  /** Settings to send, checked against what the site offers now. */
  clean: (s: S, cfg: Cfg) => S;
  form: (value: S, onChange: (next: Partial<S>) => void, cfg: Cfg) => ReactNode;
}) {
  const insets = useSafeAreaInsets();
  const { cfg, err: cfgErr } = useGameConfig<Cfg>(p.slug);
  const [settings, setSettings] = useState<S>(p.defaults);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const { user } = useAccount();
  const key = `${p.slug}-v1`;

  useEffect(() => {
    store.settings<S>(key).then((s) => s && setSettings({ ...p.defaults, ...s }));
  }, []);
  // Signed-in hosts get their saved setup (from the app or the website).
  useEffect(() => {
    if (!user) return;
    accountApi
      .prefs<S>(p.slug)
      .then((r) => r.settings && setSettings((s) => ({ ...s, ...r.settings })))
      .catch(() => {});
  }, [user?.id]);

  const change = (x: Partial<S>) => {
    const next = { ...settings, ...x };
    setSettings(next);
    store.saveSettings(key, next);
  };

  const make = async () => {
    if (!cfg) return;
    setBusy(true);
    setErr('');
    try {
      const s = p.clean(settings, cfg);
      const r = await api.createRoom(p.slug, s);
      if (user) accountApi.savePrefs(p.slug, settings).catch(() => {});
      await store.saveRoom({ code: r.code, host: r.host, game: p.slug, path: p.slug, made: Date.now() });
      router.replace({ pathname: '/room/[code]', params: { code: r.code } });
    } catch (e) {
      setErr((e as Error).message);
      setBusy(false);
    }
  };

  const shownErr = err || cfgErr;
  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      <Screen top={<TopBar title={p.title} onBack={() => router.back()} />}>
        {shownErr ? <Banner tone="bad">{shownErr}</Banner> : null}
        {p.intro ? <P muted>{p.intro}</P> : null}
        {cfg ? p.form(settings, change, cfg) : !shownErr ? <ActivityIndicator color={C.accent} style={{ marginTop: 40 }} /> : null}
        {!user ? (
          <Pressable onPress={() => router.push('/account')} accessibilityRole="link">
            <P small muted center style={{ marginTop: 6 }}>
              <Text style={{ fontFamily: F.heavy, color: C.ink, textDecorationLine: 'underline' }}>Sign in</Text> to save your setup and keep your game history. Optional.
            </P>
          </Pressable>
        ) : null}
      </Screen>
      <View style={{ borderTopWidth: 3, borderTopColor: C.ink, backgroundColor: C.sky, paddingHorizontal: 20, paddingTop: 16, paddingBottom: insets.bottom + 16 }}>
        <Button kind="go" big title="Make a room" onPress={make} busy={busy} disabled={!cfg} />
      </View>
    </View>
  );
}
