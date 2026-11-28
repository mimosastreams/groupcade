// Host screen for a room. Points the TV at the room's /tv/CODE page and shows the native
// controller for the room's game.
import { useKeepAwake } from 'expo-keep-awake';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, BackHandler, View } from 'react-native';
import { setTvRoomUrl } from '../../../modules/groupcade-tv';
import { setCastRoom } from '../../lib/cast';
import { BaloneyHost } from '../../games/baloney/Host';
import { NATIVE_GAMES } from '../../games';
import { ScribbleHost } from '../../games/scribble/Host';
import { TriviaHost } from '../../games/trivia/Host';
import { dialog } from '../../ui/Dialog';
import { api, links, type BaloneyState, type BaseState, type RoomState, type ScribbleState } from '../../lib/api';
import { store, type SavedRoom } from '../../lib/store';
import { useRoom } from '../../lib/useRoom';
import { closeRoom } from '../../lib/closeRoom';
import { Button, C, Card, H2, P, Screen, TopBar } from '../../ui/kit';
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

  const { st, error, offline, act, now, refresh } = useRoom<BaseState & { me?: unknown }>(code, saved ? saved.host : null, myTok);

  const leave = () =>
    dialog.alert('Leave this room?', 'The room stays open for 2 hours, and you can come back to it from the home screen.', [
      { text: 'Stay', style: 'cancel' },
      { text: 'Leave', onPress: () => router.replace('/') },
    ]);

  // Android's back gesture or button asks first too, instead of dropping the host on the home screen.
  useFocusEffect(
    useCallback(() => {
      const sub = BackHandler.addEventListener('hardwareBackPress', () => {
        leave();
        return true;
      });
      return () => sub.remove();
    }, [])
  );

  const close = (then: () => void, title: string, ok: string) =>
    dialog.alert(title, 'Anyone still in it will need a new code to play again.', [
      { text: 'Keep it', style: 'cancel' },
      {
        text: ok,
        style: 'destructive',
        onPress: async () => {
          if (saved) await closeRoom(saved);
          then();
        },
      },
    ]);
  const closeAndHome = () => close(() => router.replace('/'), `Close room ${code}?`, 'Close room');
  // A new room for the same game, set up natively when the app knows the game.
  const newRoute = NATIVE_GAMES[saved?.game || 'trivia']?.newRoute || '/';
  const newRoom = () => close(() => router.replace(newRoute as never), 'Close this room and make a new one?', 'New room');

  const playToo = async () => {
    if (myName) return; // Already playing: answers go right on this screen.
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
    } catch (e) {
      dialog.alert('Could not join', (e as Error).message);
    }
  };

  const header = <TopBar onBack={leave} backLabel="Back to home" title={`Room ${code}`} />;

  if (saved === null || (error && error.status === 404)) {
    return (
      <Screen top={header}>
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
      <View style={{ flex: 1, backgroundColor: C.bg }}>
        <Screen top={header}>
          <ActivityIndicator color={C.accent} style={{ marginTop: 60 }} />
        </Screen>
      </View>
    );
  }

  const game = st.game || saved?.game;
  const common = { act, now, offline, myName, myToken: myTok, onAnswered: refresh, onPlayToo: playToo, onLeave: leave, onClose: closeAndHome, onNewRoom: newRoom };
  return (
    <>
      {game === 'baloney' ? (
        <BaloneyHost st={st as unknown as BaloneyState} {...common} />
      ) : game === 'scribble' ? (
        <ScribbleHost st={st as unknown as ScribbleState} {...common} />
      ) : (
        <TriviaHost st={st as unknown as RoomState} {...common} />
      )}
      <NamePrompt
        open={askName !== null}
        title="Play too"
        message={game === 'scribble' ? 'Your name on the scoreboard. You draw and guess right here on the host screen, like everyone else.' : game === 'baloney' ? 'Your name on the scoreboard. You write fakes and vote right here on the host screen, like everyone else.' : 'Your name on the scoreboard. You answer right here on the host screen, like everyone else.'}
        initial={askName || ''}
        onCancel={() => setAskName(null)}
        onSubmit={joinAs}
      />
    </>
  );
}
