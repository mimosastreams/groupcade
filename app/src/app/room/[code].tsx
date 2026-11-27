// Host screen for a room. Points the TV at the room's /tv/CODE page and shows the native
// controller for the room's game.
import { useKeepAwake } from 'expo-keep-awake';
import { router, Stack, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, Text, View } from 'react-native';
import { setTvRoomUrl } from '../../../modules/groupcade-tv';
import { setCastRoom } from '../../lib/cast';
import { TriviaHost } from '../../games/trivia/Host';
import { api, links } from '../../lib/api';
import { store, type SavedRoom } from '../../lib/store';
import { useRoom } from '../../lib/useRoom';
import { Button, C, Card, H2, P, Screen } from '../../ui/kit';
import { NamePrompt } from '../../ui/NamePrompt';

export default function Room() {
  const { code } = useLocalSearchParams<{ code: string }>();
  const [saved, setSaved] = useState<SavedRoom | null | undefined>(undefined);
  const [myName, setMyName] = useState<string | null>(null);
  const [askName, setAskName] = useState<string | null>(null);
  const [myTok, setMyTok] = useState<string | null>(null);
  useKeepAwake();

  useEffect(() => {
    store.room().then((r) => setSaved(r && r.code === code ? r : null));
  }, [code]);

  // The TV shows this room whenever this screen is in front (and stays on it while the host plays).
  useFocusEffect(
    useCallback(() => {
      setTvRoomUrl(links.tv(code));
      setCastRoom(code);
      // Did the host join to play? Check the saved token is still in the room.
      store.playerToken(code).then(async (tok) => {
        setMyTok(tok);
        if (!tok) return setMyName(null);
        try {
          const st = await api.state(code, null, tok);
          if (st.gone || !('me' in st)) {
            await store.savePlayerToken(code, null);
            setMyName(null);
            setMyTok(null);
          } else {
            setMyName((st as unknown as { me: { name: string } }).me.name);
          }
        } catch {
          // Offline: leave it as it is.
        }
      });
    }, [code])
  );

  const { st, error, offline, act, now, refresh } = useRoom(code, saved ? saved.host : null, myTok);

  const leave = () =>
    Alert.alert('Leave this room?', 'The room stays open for 2 hours, and you can come back to it from the home screen.', [
      { text: 'Stay', style: 'cancel' },
      { text: 'Leave', onPress: () => router.replace('/') },
    ]);

  const newRoom = () =>
    Alert.alert('Close this room and make a new one?', 'Players will need the new code.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'New room',
        onPress: async () => {
          await store.clearRoom();
          setTvRoomUrl(null);
          setCastRoom(null);
          router.replace('/');
        },
      },
    ]);

  const playToo = async () => {
    if (myName) {
      router.push({ pathname: '/play/[code]', params: { code } });
      return;
    }
    setAskName((await store.name()) || '');
  };

  const joinAs = async (n: string) => {
    setAskName(null);
    try {
      const r = await api.join(code, n);
      await store.savePlayerToken(code, r.token);
      await store.saveName(n);
      setMyName(r.name);
      setMyTok(r.token);
      router.push({ pathname: '/play/[code]', params: { code } });
    } catch (e) {
      Alert.alert('Could not join', (e as Error).message);
    }
  };

  const header = (
    <Stack.Screen
      options={{
        title: `Room ${code}`,
        headerLeft: () => (
          <Pressable onPress={leave} hitSlop={12} accessibilityRole="button">
            <Text style={{ color: C.white, fontWeight: '700', fontSize: 16 }}>Home</Text>
          </Pressable>
        ),
      }}
    />
  );

  if (saved === null || (error && error.status === 404)) {
    return (
      <Screen>
        {header}
        <Card>
          <H2>{saved === null ? 'This isn’t your room on this phone' : 'This room has ended'}</H2>
          <P muted>{saved === null ? 'Only the phone that made a room can host it.' : 'Rooms close 2 hours after the last activity.'}</P>
          <Button
            kind="go"
            title="Back to home"
            onPress={async () => {
              if (saved) await store.clearRoom();
              router.replace('/');
            }}
          />
        </Card>
      </Screen>
    );
  }

  if (!st) {
    return (
      <View style={{ flex: 1, backgroundColor: C.bg, alignItems: 'center', justifyContent: 'center' }}>
        {header}
        <ActivityIndicator color={C.accent} />
      </View>
    );
  }

  return (
    <>
      {header}
      <TriviaHost st={st} act={act} now={now} offline={offline} myName={myName} myToken={myTok} onAnswered={refresh} onPlayToo={playToo} onNewRoom={newRoom} />
      <NamePrompt
        open={askName !== null}
        title="Play too"
        message="Your name on the scoreboard. You can switch back to these host controls any time."
        initial={askName || ''}
        onCancel={() => setAskName(null)}
        onSubmit={joinAs}
      />
    </>
  );
}
