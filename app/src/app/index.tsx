// Home: pick a game to host, get back into your last room, or join someone else's game.
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { setTvRoomUrl } from '../../modules/groupcade-tv';
import { NATIVE_GAMES, type AppGame } from '../games';
import { api } from '../lib/api';
import { store, type SavedRoom } from '../lib/store';
import { Button, C, Card, H2, Label, P, Screen, s } from '../ui/kit';
import { Logo } from '../ui/Logo';

export default function Home() {
  const insets = useSafeAreaInsets();
  const [games, setGames] = useState<AppGame[]>(Object.values(NATIVE_GAMES));
  const [resume, setResume] = useState<SavedRoom | null>(null);
  const [code, setCode] = useState('');

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
  useFocusEffect(
    useCallback(() => {
      setTvRoomUrl(null);
      let live = true;
      store.room().then(async (r) => {
        if (!r) return live && setResume(null);
        try {
          const st = await api.state(r.code, r.host);
          if (live) setResume(st.host ? r : null);
        } catch {
          if (live) setResume(null);
        }
      });
      return () => {
        live = false;
      };
    }, [])
  );

  const host = (g: AppGame) => {
    if (g.newRoute) router.push(g.newRoute as never);
    else router.push({ pathname: '/play/[code]', params: { code: 'new', path: g.path } });
  };

  const join = () => {
    const c = code.toUpperCase().replace(/[^A-Z]/g, '');
    if (c.length === 4) router.push({ pathname: '/play/[code]', params: { code: c } });
  };

  return (
    <View style={{ flex: 1, backgroundColor: C.bg, paddingTop: insets.top }}>
      <Screen>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 14, marginTop: 8 }}>
          <Logo size={56} />
          <View>
            <Text style={{ color: C.white, fontSize: 28, fontWeight: '900' }}>Groupcade</Text>
            <Text style={{ color: C.mist, fontSize: 15, fontWeight: '600' }}>Party games for the big screen</Text>
          </View>
        </View>

        {resume ? (
          <Card style={{ borderColor: C.accent }}>
            <Label>Your room is still open</Label>
            <Text style={[s.code, { fontSize: 40, textAlign: 'left' }]}>{resume.code}</Text>
            <Button kind="go" title="Back to my room" onPress={() => router.push({ pathname: '/room/[code]', params: { code: resume.code } })} />
          </Card>
        ) : null}

        <H2>Host a game</H2>
        {games.map((g) => (
          <Pressable key={g.slug} onPress={() => host(g)} accessibilityRole="button" style={({ pressed }) => [s.card, { opacity: pressed ? 0.8 : 1 }]}>
            <Text style={{ color: C.white, fontSize: 24, fontWeight: '900' }}>{g.name}</Text>
            <P muted small>{g.tagline}</P>
            <Text style={{ color: C.accent, fontWeight: '800', marginTop: 4 }}>Make a room →</Text>
          </Pressable>
        ))}
        <P muted small>
          Only you need the app. Everyone else joins on their phone's browser with a 4-letter code, and the game goes on your TV with AirPlay, Chromecast or any TV browser.
        </P>

        <Card>
          <H2>Join a game</H2>
          <P muted small>Got a code from someone else's game?</P>
          <TextInput
            value={code}
            onChangeText={(t) => setCode(t.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 4))}
            placeholder="ABCD"
            placeholderTextColor={C.mist}
            autoCapitalize="characters"
            autoCorrect={false}
            maxLength={4}
            returnKeyType="go"
            onSubmitEditing={join}
            style={{ height: 56, borderRadius: 12, borderWidth: 1, borderColor: C.line, backgroundColor: C.ink, color: C.white, fontSize: 28, fontWeight: '900', letterSpacing: 10, textAlign: 'center' }}
          />
          <Button title="Join" onPress={join} disabled={code.length !== 4} />
        </Card>
      </Screen>
    </View>
  );
}
