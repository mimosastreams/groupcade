// Web view of the game's player page. Used when the host plays on their own phone (signed in with
// the tokens the app holds), when joining someone else's game, and for games without native host
// screens yet. The page talks back through window.ReactNativeWebView (see the app bridge in
// plugin/groupcade/games/trivia/assets/app.html).
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, BackHandler, Platform, View } from 'react-native';
import { WebView, type WebViewMessageEvent } from 'react-native-webview';
import { API_BASE, links } from '../../lib/api';
import { store } from '../../lib/store';
import { useCast } from '../../lib/cast';
import { useTv } from '../../lib/useTv';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { C, TopBar } from '../../ui/kit';
import { dialog } from '../../ui/Dialog';

type Boot = { uri: string; inject: string } | null;

// react-native-webview's typings default the class's extra props to `undefined`, which newer
// TypeScript turns into `never`. Pin them to an empty object.
// eslint-disable-next-line @typescript-eslint/no-empty-object-type
const Web = WebView<{}>;
// eslint-disable-next-line @typescript-eslint/no-empty-object-type
type Web = WebView<{}>;

export default function Play() {
  const { code, path } = useLocalSearchParams<{ code: string; path?: string }>();
  const [boot, setBoot] = useState<Boot>(null);
  const [hosting, setHosting] = useState(false);
  const web = useRef<Web>(null);
  const insets = useSafeAreaInsets();
  const tvScreen = useTv();
  const cast = useCast();
  const tv = { connected: tvScreen.connected || cast.status === 'on' };

  useEffect(() => {
    (async () => {
      if (code === 'new') {
        // A game the app has no native screens for yet: its web host screen.
        setBoot({ uri: `${API_BASE}/${path || 'trivia'}/`, inject: appFlag(false, false) });
        return;
      }
      const room = await store.room();
      const mine = room && room.code === code ? room : null;
      const tok = await store.playerToken(code);
      const name = await store.name();
      setHosting(!!mine);
      const ls: Record<string, string> = {};
      if (tok) ls[`gc_tok_${code}`] = tok;
      if (mine) ls[`gc_host_${code}`] = mine.host;
      if (name) ls.gc_name = name;
      const uri = links.game(mine ? mine.path : 'join', code) + (tok ? '?play=1' : '');
      const seed = Object.entries(ls)
        .map(([k, v]) => `localStorage.setItem(${JSON.stringify(k)}, ${JSON.stringify(v)});`)
        .join('');
      setBoot({ uri, inject: `try{${seed}}catch(e){}` + appFlag(!!mine, tv.connected) });
    })();
    // Boot once; TV changes are pushed in below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code, path]);

  useEffect(() => {
    if (hosting) web.current?.injectJavaScript(`window.groupcadeTv && window.groupcadeTv(${tv.connected}); true;`);
  }, [tv.connected, hosting]);

  // Leaving a game you joined asks first, so a stray tap or Android back swipe mid-drawing doesn't
  // throw you out. The host just goes back to their host screen.
  const leave = useCallback(() => {
    if (hosting || code === 'new') return router.back();
    dialog.alert('Leave this game?', 'You can rejoin with the same code while the room is open.', [
      { text: 'Stay', style: 'cancel' },
      { text: 'Leave', onPress: () => router.back() },
    ]);
  }, [hosting, code]);
  useFocusEffect(
    useCallback(() => {
      const sub = BackHandler.addEventListener('hardwareBackPress', () => {
        leave();
        return true;
      });
      return () => sub.remove();
    }, [leave])
  );

  const onMessage = (e: WebViewMessageEvent) => {
    let m: { groupcade?: number; type?: string; code?: string };
    try {
      m = JSON.parse(e.nativeEvent.data);
    } catch {
      return;
    }
    if (!m.groupcade) return;
    if (m.type === 'hostscreen' && hosting) router.back();
  };

  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      <View style={{ paddingTop: insets.top, backgroundColor: C.bg, borderBottomWidth: 3, borderBottomColor: C.ink }}>
        <TopBar onBack={leave} backLabel={hosting ? 'Back to host screen' : 'Back'} title={hosting ? 'Playing' : code === 'new' ? 'Groupcade' : `Room ${code}`} />
      </View>
      {boot ? (
        <Web
          ref={web}
          source={{ uri: boot.uri }}
          injectedJavaScriptBeforeContentLoaded={boot.inject}
          onMessage={onMessage}
          style={{ backgroundColor: C.bg }}
          containerStyle={{ backgroundColor: C.bg }}
          allowsBackForwardNavigationGestures={false}
          bounces={false}
          sharedCookiesEnabled
          startInLoadingState
          renderLoading={() => (
            <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: C.bg }}>
              <ActivityIndicator color={C.accent} />
            </View>
          )}
          onShouldStartLoadWithRequest={(r: { url: string }) => r.url.startsWith(API_BASE) || r.url.startsWith('about:')}
        />
      ) : null}
    </View>
  );
}

/** window.GROUPCADE_APP tells the page it is inside the app. */
function appFlag(hosting: boolean, tvOn: boolean) {
  return `window.GROUPCADE_APP=${JSON.stringify({ tv: hosting, tvOn, nativeHost: hosting, os: Platform.OS })};true;`;
}
