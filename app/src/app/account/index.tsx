// The host's account: sign in (optional), then their games, question packs and account settings.
import * as AppleAuthentication from 'expo-apple-authentication';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, Switch, Text, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { accountApi, type History, type PackSummary } from '../../lib/api';
import { appleAvailable, Cancelled, deleteAccount, googleAvailable, signInWithApple, signInWithGoogle, signOut, updateUser, useAccount } from '../../lib/account';
import { store } from '../../lib/store';
import { dialog } from '../../ui/Dialog';
import { Field } from '../../ui/Field';
import { Banner, Button, C, Card, F, H1, H2, P, Row, Screen, Tag, TopBar } from '../../ui/kit';

export default function Account() {
  const { ready, user } = useAccount();
  const { claim } = useLocalSearchParams<{ claim?: string }>();
  return (
    <Screen top={<TopBar title={user ? 'Your account' : 'Sign in'} onBack={() => router.back()} />}>
      {!ready ? <ActivityIndicator color={C.accent} style={{ marginTop: 40 }} /> : user ? <SignedIn claim={claim} /> : <SignIn claim={claim} />}
    </Screen>
  );
}

// ------------------------------------------------------------------ signed out

function SignIn({ claim }: { claim?: string }) {
  const { config } = useAccount();
  const [apple, setApple] = useState(false);
  const [busy, setBusy] = useState<'' | 'apple' | 'google'>('');
  const [err, setErr] = useState('');
  useEffect(() => {
    appleAvailable().then(setApple);
  }, []);
  const google = googleAvailable(config);

  const run = async (which: 'apple' | 'google') => {
    setErr('');
    setBusy(which);
    try {
      await (which === 'apple' ? signInWithApple() : signInWithGoogle());
    } catch (e) {
      if (!(e instanceof Cancelled)) setErr((e as Error).message);
    } finally {
      setBusy('');
    }
  };

  return (
    <>
      <Card tint={C.mintTint} lifted style={{ gap: 8 }}>
        <H1>Save your games</H1>
        <P>Sign in to keep your setup, see who won past games and write your own question packs. It's optional, and players never need an account.</P>
      </Card>
      {claim ? <Banner>Sign in to save room {claim} and its games to your history.</Banner> : null}
      {err ? <Banner tone="bad">{err}</Banner> : null}
      <View style={{ gap: 12 }}>
        {apple ? (
          busy === 'apple' ? (
            <ActivityIndicator color={C.ink} style={{ height: 54 }} />
          ) : (
            <AppleAuthentication.AppleAuthenticationButton
              buttonType={AppleAuthentication.AppleAuthenticationButtonType.CONTINUE}
              buttonStyle={AppleAuthentication.AppleAuthenticationButtonStyle.BLACK}
              cornerRadius={27}
              style={{ height: 54 }}
              onPress={() => run('apple')}
            />
          )
        ) : null}
        {google ? <ProviderButton title="Continue with Google" icon="google" busy={busy === 'google'} onPress={() => run('google')} /> : null}
        <ProviderButton title="Continue with email" icon="mail" onPress={() => router.push('/account/email')} />
      </View>
      <P small muted center>
        We keep your name, email and what you save, only to show it back to you. You can delete it all any time.
      </P>
    </>
  );
}

function ProviderButton({ title, icon, onPress, busy }: { title: string; icon: 'google' | 'mail'; onPress: () => void; busy?: boolean }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={title}
      onPress={onPress}
      disabled={busy}
      style={({ pressed }) => [
        { height: 54, borderRadius: 27, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10, opacity: pressed ? 0.7 : 1 },
      ]}
    >
      {busy ? (
        <ActivityIndicator color={C.ink} />
      ) : (
        <>
          {icon === 'google' ? (
            <Svg width={20} height={20} viewBox="0 0 48 48">
              <Path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.5l6.7-6.7C35.6 2.4 30.2 0 24 0 14.6 0 6.6 5.4 2.6 13.2l7.8 6.1C12.3 13.6 17.7 9.5 24 9.5z" />
              <Path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 7.1-10 7.1-17.5z" />
              <Path fill="#FBBC05" d="M10.4 28.7c-.5-1.4-.8-3-.8-4.7s.3-3.3.8-4.7l-7.8-6.1C1 16.5 0 20.1 0 24s1 7.5 2.6 10.8l7.8-6.1z" />
              <Path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.1 1.4-4.8 2.3-8.4 2.3-6.3 0-11.7-4.1-13.6-9.8l-7.8 6.1C6.6 42.6 14.6 48 24 48z" />
            </Svg>
          ) : (
            <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke={C.ink} strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round">
              <Path d="M3 6h18v12H3zM3 7l9 6 9-6" />
            </Svg>
          )}
          <Text style={{ fontFamily: F.heavy, fontSize: 17, color: C.ink }}>{title}</Text>
        </>
      )}
    </Pressable>
  );
}

// ------------------------------------------------------------------ signed in

function SignedIn({ claim }: { claim?: string }) {
  const { user } = useAccount();
  const [history, setHistory] = useState<History | null>(null);
  const [packs, setPacks] = useState<PackSummary[] | null>(null);
  const [saved, setSaved] = useState('');
  const [err, setErr] = useState('');
  const [name, setName] = useState(user?.name || '');
  const claimed = useRef(false);

  // A host who signed in from the end of a game: save that room to their history.
  useEffect(() => {
    if (!claim || claimed.current) return;
    claimed.current = true;
    store.room().then(async (r) => {
      if (!r || r.code !== claim) return;
      try {
        const res = await accountApi.claim(r.code, r.host);
        setSaved(`Saved! Room ${r.code}${res.games ? ` and its ${res.games === 1 ? 'game are' : `${res.games} games are`}` : ' is'} in your history now, and the next games will save too.`);
        load();
      } catch (e) {
        setErr((e as Error).message);
      }
    });
  }, [claim]);

  const load = useCallback(() => {
    accountApi.history().then(setHistory).catch(() => {});
    accountApi.packs().then((r) => setPacks(r.packs)).catch(() => {});
  }, []);
  useFocusEffect(load);

  if (!user) return null;

  const saveName = async () => {
    const n = name.trim();
    if (!n || n === user.name) return;
    try {
      await updateUser({ name: n });
    } catch (e) {
      setErr((e as Error).message);
    }
  };

  const newPack = () =>
    dialog.prompt('New question pack', 'Give it a name, like "Sam\'s 30th" or "Office party".', async (n) => {
      try {
        const r = await accountApi.newPack(n);
        router.push({ pathname: '/account/pack/[id]', params: { id: String(r.pack.id) } });
      } catch (e) {
        setErr((e as Error).message);
      }
    });

  const confirmDelete = () =>
    dialog.alert('Delete your account?', 'This deletes your saved settings, game history and question packs right away. It can’t be undone.', [
      { text: 'Keep it', style: 'cancel' },
      {
        text: 'Delete account',
        style: 'destructive',
        onPress: async () => {
          try {
            await deleteAccount();
            dialog.alert('Your account is deleted', 'You can still host and play without an account any time.');
            router.back();
          } catch (e) {
            setErr((e as Error).message);
          }
        },
      },
    ]);

  return (
    <>
      {saved ? <Banner tone="good">{saved}</Banner> : null}
      {saved && claim ? <Button kind="hot" title="Back to your game" onPress={() => router.back()} /> : null}
      {err ? <Banner tone="bad">{err}</Banner> : null}

      <Card lifted style={{ gap: 12 }}>
        <Row style={{ justifyContent: 'space-between' }}>
          <H2 style={{ flex: 1 }}>Hi, {user.name}</H2>
          {user.founding ? <Tag text="Founding member" bg={C.gold} /> : null}
        </Row>
        <P small muted>
          {user.email && !user.relay ? `${user.email} · ` : ''}Signed in with {user.via.map((v) => ({ apple: 'Apple', google: 'Google', email: 'email code' })[v] || v).join(', ')}
        </P>
        <Field label="Your name" value={name} onChangeText={setName} onEndEditing={saveName} onSubmitEditing={saveName} maxLength={40} returnKeyType="done" />
        <Row style={{ justifyContent: 'space-between', flexWrap: 'nowrap' }}>
          <Text style={{ flex: 1, fontFamily: F.bold, fontSize: 15, color: user.email ? C.ink : C.faint }}>Email me when a new game comes out</Text>
          <Switch
            value={user.news}
            disabled={!user.email}
            onValueChange={(v) => updateUser({ news: v }).catch((e) => setErr((e as Error).message))}
            trackColor={{ true: C.accent, false: C.soft }}
            accessibilityLabel="Email me when a new game comes out"
          />
        </Row>
      </Card>

      <Games history={history} />

      <Card style={{ gap: 12 }}>
        <H2>Your question packs</H2>
        {!packs ? (
          <ActivityIndicator color={C.accent} />
        ) : packs.length ? (
          packs.map((k) => (
            <Pressable
              key={k.id}
              accessibilityRole="button"
              accessibilityLabel={`Edit ${k.name}, ${k.count} questions`}
              onPress={() => router.push({ pathname: '/account/pack/[id]', params: { id: String(k.id) } })}
              style={({ pressed }) => [{ borderWidth: 2, borderColor: C.ink, borderRadius: 14, padding: 12, flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: C.white, opacity: pressed ? 0.7 : 1 }]}
            >
              <Text style={{ flex: 1, fontFamily: F.heavy, fontSize: 16, color: C.ink }}>{k.name}</Text>
              <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.mist }}>
                {k.count} question{k.count === 1 ? '' : 's'}
              </Text>
            </Pressable>
          ))
        ) : (
          <P muted>Write your own Trivia questions: inside jokes, a birthday quiz, an office party. Pick the pack when you make a room.</P>
        )}
        <Button small kind="dark" title="New pack" onPress={newPack} />
      </Card>

      <Card style={{ gap: 10 }}>
        <H2>Account</H2>
        <Button small title="Sign out" onPress={() => signOut()} />
        <Button small kind="ghost" title="Sign out on every device" onPress={() => signOut(true)} />
        <Button small kind="danger" title="Delete account" onPress={confirmDelete} />
      </Card>
    </>
  );
}

function Games({ history }: { history: History | null }) {
  if (!history) return <ActivityIndicator color={C.accent} />;
  if (!history.games.length) {
    return (
      <Card style={{ gap: 8 }}>
        <H2>Your games</H2>
        <P muted>Games you host while signed in show up here, with who won.</P>
      </Card>
    );
  }
  const when = (t: number) => new Date(t * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  return (
    <Card style={{ gap: 12 }}>
      <View>
        <H2>Your games</H2>
        <P small muted>
          {history.total} game{history.total === 1 ? '' : 's'} hosted
        </P>
      </View>
      {history.board.length ? (
        <View style={{ gap: 6 }}>
          <Text style={{ fontFamily: F.display, fontSize: 19, color: C.ink }}>Most wins</Text>
          {history.board.slice(0, 5).map((b, i) => (
            <Row key={b.name} style={{ flexWrap: 'nowrap' }}>
              <Text style={{ width: 24, fontFamily: F.display, fontSize: 20, color: C.accent }}>{i + 1}</Text>
              <Text style={{ flex: 1, fontFamily: F.heavy, fontSize: 16, color: C.ink }} numberOfLines={1}>
                {b.name}
              </Text>
              <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>
                {b.wins} win{b.wins === 1 ? '' : 's'}
              </Text>
            </Row>
          ))}
        </View>
      ) : null}
      <Text style={{ fontFamily: F.display, fontSize: 19, color: C.ink }}>Recent</Text>
      {history.games.slice(0, 10).map((g) => (
        <View key={g.id} style={{ borderWidth: 2, borderColor: C.ink, borderRadius: 14, padding: 10, gap: 2, backgroundColor: C.white }}>
          <Row style={{ justifyContent: 'space-between' }}>
            <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>
              {g.name}
              {g.mode ? ` · ${g.mode === 'points' ? 'Points' : 'Survival'}` : ''}
            </Text>
            <Text style={{ fontFamily: F.bold, fontSize: 13, color: C.mist }}>{when(g.at)}</Text>
          </Row>
          <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.ink }}>
            {g.winners.length ? `${g.winners.length > 1 ? 'Winners' : 'Winner'}: ${g.winners.join(', ')}` : g.stopped ? 'Ended early' : 'No winner'}
          </Text>
          <Text style={{ fontFamily: F.body, fontSize: 13, color: C.mist }}>
            {g.players} player{g.players === 1 ? '' : 's'} · {g.rounds} round{g.rounds === 1 ? '' : 's'}
          </Text>
        </View>
      ))}
    </Card>
  );
}

