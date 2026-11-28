// Home: join someone's game first, then host one. A still-open room shows on top of the host
// list, with a way back in and a way to close it.
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { setTvRoomUrl } from '../../modules/groupcade-tv';
import { NATIVE_GAMES, type AppGame } from '../games';
import { api } from '../lib/api';
import { useAccount } from '../lib/account';
import { closeRoom } from '../lib/closeRoom';
import { store, type SavedRoom } from '../lib/store';
import { Buddy } from '../ui/Buddy';
import { dialog } from '../ui/Dialog';
import { Button, C, Card, F, H1, H2, P, Screen, shadow } from '../ui/kit';
import { Logo, LogoWide } from '../ui/Logo';

type Open = SavedRoom & { players: number; name: string };

export default function Home() {
  const [games, setGames] = useState<AppGame[]>(Object.values(NATIVE_GAMES));
  const [open, setOpen] = useState<Open | null>(null);
  const [code, setCode] = useState('');
  const [closing, setClosing] = useState(false);
  const { user } = useAccount();

  useEffect(() => {
    api
      .games()
      .then((r) =>
        setGames(
          r.games.map((g) => ({
            ...(NATIVE_GAMES[g.slug] || {}),
            slug: g.slug,
            name: g.name,
            tagline: g.tagline,
            path: g.url.replace(/^https?:\/\/[^/]+\//, '').replace(/\/$/, ''),
          }))
        )
      )
      .catch(() => {}); // Offline: the built-in list is fine.
  }, []);

  // Back on the home screen: the TV goes to its idle screen, and offer the last room if it's still live.
  const check = useCallback(() => {
    let live = true;
    store.room().then(async (r) => {
      if (!r) return live && setOpen(null);
      try {
        const st = await api.state(r.code, r.host);
        const name = NATIVE_GAMES[r.game]?.name || r.game;
        if (live) setOpen(st.host ? { ...r, players: st.players.length, name } : null);
      } catch {
        if (live) setOpen(null);
      }
    });
    return () => {
      live = false;
    };
  }, []);
  useFocusEffect(
    useCallback(() => {
      setTvRoomUrl(null);
      return check();
    }, [check])
  );

  const startNew = (g: AppGame) => {
    if (g.newRoute) router.push(g.newRoute as never);
    else router.push({ pathname: '/play/[code]', params: { code: 'new', path: g.path } });
  };

  const close = async (then?: () => void) => {
    if (!open) return then?.();
    setClosing(true);
    await closeRoom(open);
    setOpen(null);
    setClosing(false);
    then?.();
  };

  const confirmClose = () =>
    dialog.alert(`Close room ${open?.code}?`, 'Anyone still in it will need a new code to play again.', [
      { text: 'Keep it', style: 'cancel' },
      { text: 'Close room', style: 'destructive', onPress: () => close() },
    ]);

  const host = (g: AppGame) => {
    if (!open) return startNew(g);
    dialog.alert(`Host ${g.name}?`, `This closes your open room ${open.code} and starts a new one.`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Close and host', onPress: () => close(() => startNew(g)) },
    ]);
  };

  const join = () => {
    const c = code.toUpperCase().replace(/[^A-Z]/g, '');
    if (c.length === 4) router.push({ pathname: '/play/[code]', params: { code: c } });
  };

  return (
    <Screen
      top={
        <View style={{ paddingHorizontal: 20, paddingTop: 10, paddingBottom: 4, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
          <LogoWide height={40} />
          <AccountButton signedIn={!!user} />
        </View>
      }
    >
      <Card tint={C.mintTint} lifted style={{ borderRadius: 28, padding: 20, gap: 14 }}>
        <View style={{ gap: 4 }}>
          <H1>Join a game</H1>
          <Text nativeID="codeLabel" style={{ fontFamily: F.bold, fontSize: 15, color: C.mist }}>
            Type the 4-letter code from the TV.
          </Text>
        </View>
        <TextInput
          value={code}
          onChangeText={(t) => setCode(t.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 4))}
          placeholder="ABCD"
          placeholderTextColor={C.faint}
          autoCapitalize="characters"
          autoCorrect={false}
          maxLength={4}
          returnKeyType="go"
          onSubmitEditing={join}
          accessibilityLabelledBy="codeLabel"
          accessibilityLabel="Room code"
          style={{ height: 64, borderRadius: 18, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, color: C.ink, fontSize: 32, fontFamily: F.display, letterSpacing: 8, textAlign: 'center', paddingTop: 6 }}
        />
        <Button kind="hot" title="Join" onPress={join} disabled={code.length !== 4} />
      </Card>

      <View style={{ gap: 14, marginTop: 8 }}>
        <H2 style={{ fontSize: 26 }}>Or host a game</H2>

        {open ? (
          <Card tint={C.gold} style={{ gap: 12, padding: 14 }}>
            <View>
              <Text style={{ fontFamily: F.display, fontSize: 20, lineHeight: 24, color: C.ink }}>Your room is still open</Text>
              <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.ink }}>
                {open.name} · Room {open.code} · {open.players} player{open.players === 1 ? '' : 's'}
              </Text>
            </View>
            <View style={{ flexDirection: 'row', gap: 10 }}>
              <View style={{ flex: 1 }}>
                <Button small kind="dark" title="Back to my room" onPress={() => router.push({ pathname: '/room/[code]', params: { code: open.code } })} />
              </View>
              <Button small title="Close room" busy={closing} onPress={confirmClose} />
            </View>
          </Card>
        ) : null}

        {games.map((g, i) => (
          <Pressable
            key={g.slug}
            onPress={() => host(g)}
            accessibilityRole="button"
            accessibilityLabel={`Host ${g.name}`}
            accessibilityHint={open ? 'Closes your open room first' : g.tagline}
            style={({ pressed }) => [
              { backgroundColor: C.coralTint, borderWidth: 3, borderColor: C.ink, borderRadius: 22, padding: 14, flexDirection: 'row', alignItems: 'center', gap: 14 },
              pressed ? { transform: [{ translateX: 2 }, { translateY: 2 }] } : shadow(4),
            ]}
          >
            <View style={{ width: 84, height: 84, borderRadius: 18, borderWidth: 3, borderColor: C.ink, backgroundColor: C.sky, alignItems: 'center', justifyContent: 'center' }}>
              {g.slug === 'trivia' ? <Logo size={60} /> : <Buddy index={i} size={60} />}
            </View>
            <View style={{ flex: 1, gap: 2 }}>
              <Text style={{ fontFamily: F.display, fontSize: 26, lineHeight: 30, color: C.ink }}>Host {g.name}</Text>
              <Text style={{ fontFamily: F.body, fontSize: 14, lineHeight: 19, color: C.mist }}>
                {open ? 'Starts a new room. Your open room closes first.' : g.tagline || 'Starts a new room.'}
              </Text>
            </View>
            <View style={{ width: 48, height: 48, borderRadius: 24, borderWidth: 3, borderColor: C.ink, backgroundColor: C.accent, alignItems: 'center', justifyContent: 'center' }}>
              <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke={C.white} strokeWidth={3} strokeLinecap="round" strokeLinejoin="round">
                <Path d="M5 12h14M13 6l6 6-6 6" />
              </Svg>
            </View>
          </Pressable>
        ))}

        <P muted small center style={{ fontFamily: F.bold }}>
          More games are on the way.
        </P>
        <P muted small center>
          Only you need the app. Everyone else joins in their phone's browser with the code, and the game goes on your TV with AirPlay, Chromecast or any TV browser.
        </P>
      </View>
    </Screen>
  );
}

/** Top-right: the host's account (optional). */
function AccountButton({ signedIn }: { signedIn: boolean }) {
  return (
    <Pressable
      onPress={() => router.push('/account')}
      accessibilityRole="button"
      accessibilityLabel={signedIn ? 'Your account' : 'Sign in to save your games'}
      hitSlop={8}
      style={({ pressed }) => [
        { height: 44, borderRadius: 22, borderWidth: 3, borderColor: C.ink, backgroundColor: signedIn ? C.gold : C.white, flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: signedIn ? 10 : 14, opacity: pressed ? 0.7 : 1 },
      ]}
    >
      <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke={C.ink} strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round">
        <Path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 4-6 8-6s8 2 8 6" />
      </Svg>
      {signedIn ? null : <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>Sign in</Text>}
    </Pressable>
  );
}
