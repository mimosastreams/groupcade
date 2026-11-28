// Pieces every game's host controller shares: the join card, the players grid, "play too",
// the navy host-controls bar, the round clock, the results header and the points standings.
import * as Haptics from 'expo-haptics';
import * as Clipboard from 'expo-clipboard';
import { useEffect, useState, type ReactNode } from 'react';
import { Pressable, Text, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import Svg, { Path } from 'react-native-svg';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { links } from '../../lib/api';
import { Buddy } from '../../ui/Buddy';
import { dialog } from '../../ui/Dialog';
import { Banner, Button, C, Card, F, H2, P, Screen, Tag, TopBar, buddyFor, shadow } from '../../ui/kit';
import { Logo } from '../../ui/Logo';

export const MAX_PLAYERS = 50;

export function kick(pl: { id?: number; name: string }, run: (a: string, x?: object) => Promise<void>) {
  dialog.alert(`Remove ${pl.name}?`, 'They can join again with a new name.', [
    { text: 'Cancel', style: 'cancel' },
    { text: 'Remove', style: 'destructive', onPress: () => run('kick', { player: pl.id }) },
  ]);
}

export function HostControls({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={{ backgroundColor: C.ink, borderTopLeftRadius: 28, borderTopRightRadius: 28, paddingHorizontal: 20, paddingTop: 14, paddingBottom: insets.bottom + 14, gap: 10 }}>{children}</View>
  );
}

/** Buttons on the navy host-controls bar: white outlines instead of navy. */
export function DarkButton({ title, onPress, kind, busy, disabled }: { title: string; onPress: () => void; kind: 'go' | 'danger'; busy?: boolean; disabled?: boolean }) {
  const go = kind === 'go';
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!(disabled || busy), busy: !!busy }}
      disabled={disabled || busy}
      onPress={() => {
        Haptics.selectionAsync().catch(() => {});
        onPress();
      }}
      style={({ pressed }) => ({
        height: 50,
        paddingHorizontal: 16,
        borderRadius: 999,
        borderWidth: 3,
        borderColor: go ? C.white : '#FFB3A3',
        backgroundColor: go ? C.accent : 'transparent',
        alignItems: 'center',
        justifyContent: 'center',
        opacity: disabled ? 0.4 : pressed ? 0.75 : 1,
      })}
    >
      <Text style={{ fontFamily: F.heavy, fontSize: 16, color: go ? C.white : '#FFB3A3' }}>{busy ? '…' : title}</Text>
    </Pressable>
  );
}

export function JoinCard({ code, path }: { code: string; path: string }) {
  const url = links.game(path, code);
  const [qr, setQr] = useState(false);
  const [copied, setCopied] = useState(false);
  // Copy rather than the share sheet: with the TV on, iOS can try to show the sheet on the TV.
  const copy = () => {
    Clipboard.setStringAsync(url).catch(() => {});
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };
  return (
    <View style={[{ backgroundColor: C.accent, borderWidth: 3, borderColor: C.ink, borderRadius: 26, padding: 16, paddingHorizontal: 18, gap: 12 }, shadow(5)]}>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
        <View style={{ flexShrink: 1 }}>
          <Text style={{ fontFamily: F.bold, fontSize: 14, color: '#DCD8FF' }}>Join on your phone at</Text>
          <Text style={{ fontFamily: F.heavy, fontSize: 17, color: C.white }}>{links.short(path)}</Text>
          <Text style={{ fontFamily: F.display, fontSize: 52, lineHeight: 58, letterSpacing: 4, color: C.white, marginTop: 2 }} accessibilityLabel={`Room code ${code.split('').join(' ')}`}>
            {code}
          </Text>
        </View>
        <View style={{ gap: 8 }}>
          <Button small title={copied ? 'Copied!' : 'Copy link'} onPress={copy} />
          <Button small title={qr ? 'Hide QR' : 'Show QR'} onPress={() => setQr(!qr)} />
        </View>
      </View>
      {qr ? (
        <View style={{ alignSelf: 'center', backgroundColor: C.white, padding: 12, borderRadius: 18, borderWidth: 3, borderColor: C.ink }}>
          <QRCode value={url} size={180} backgroundColor="#ffffff" color={C.ink} />
        </View>
      ) : null}
    </View>
  );
}

export function PlayersGrid({ st, myName, onKick }: { st: { players: { id?: number; name: string }[] }; myName: string | null; onKick: (p: { id?: number; name: string }) => void }) {
  const n = st.players.length;
  return (
    <View style={{ gap: 10 }}>
      <View style={{ flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between' }}>
        <H2>Players · {n}</H2>
        <Text style={{ fontFamily: F.bold, fontSize: 13, color: C.mist }}>{n ? 'Tap a name to remove them.' : `Room for ${MAX_PLAYERS}`}</Text>
      </View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
        {st.players.map((x, i) => (
          <Pressable
            key={x.id ?? x.name}
            accessibilityRole="button"
            accessibilityLabel={`${x.name}${x.name === myName ? ', you' : ''}. Remove`}
            onPress={() => onKick(x)}
            style={({ pressed }) => ({
              width: '31%',
              backgroundColor: buddyFor(i).card,
              borderWidth: 3,
              borderColor: C.ink,
              borderRadius: 18,
              paddingVertical: 8,
              paddingHorizontal: 4,
              alignItems: 'center',
              gap: 2,
              opacity: pressed ? 0.7 : 1,
            })}
          >
            <Buddy index={i} size={50} />
            <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }} numberOfLines={1}>
              {x.name === myName ? `${x.name} (you)` : x.name}
            </Text>
          </Pressable>
        ))}
        {n < MAX_PLAYERS ? (
          <View style={{ width: '31%', minHeight: 86, borderWidth: 3, borderStyle: 'dashed', borderColor: C.faint, borderRadius: 18, alignItems: 'center', justifyContent: 'center', padding: 6 }}>
            <Text style={{ fontFamily: F.bold, fontSize: 13, color: C.mist, textAlign: 'center' }}>{n ? 'Waiting for more…' : 'Waiting for players…'}</Text>
          </View>
        ) : null}
      </View>
    </View>
  );
}

/** "Play too": the host joins as a player and answers on this phone. */
export function PlayToo({ on, name, onPress, line = 'Play too (answer on this phone)' }: { on: boolean; name: string | null; onPress: () => void; line?: string }) {
  return (
    <Pressable
      accessibilityRole="checkbox"
      accessibilityState={{ checked: on, disabled: on }}
      disabled={on}
      onPress={onPress}
      style={{ flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: C.goldTint, borderWidth: 3, borderColor: C.ink, borderRadius: 18, paddingVertical: 12, paddingHorizontal: 14 }}
    >
      <View style={{ width: 26, height: 26, borderRadius: 7, borderWidth: 3, borderColor: on ? C.accent : C.ink, backgroundColor: on ? C.accent : C.white, alignItems: 'center', justifyContent: 'center' }}>
        {on ? (
          <Svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke={C.white} strokeWidth={4} strokeLinecap="round" strokeLinejoin="round">
            <Path d="M4 12l5 5L20 6" />
          </Svg>
        ) : null}
      </View>
      <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink, flex: 1 }}>{on ? `Playing too as ${name}` : line}</Text>
    </Pressable>
  );
}

export function NameTag({ name, index, locked, me }: { name: string; index: number; locked?: boolean; me?: boolean }) {
  return (
    <View
      style={{ flexDirection: 'row', alignItems: 'center', gap: 4, backgroundColor: locked ? C.mintTint : me ? C.goldTint : C.white, borderWidth: 3, borderColor: C.ink, borderRadius: 999, paddingLeft: 4, paddingRight: 12, paddingVertical: 2 }}
      accessibilityLabel={`${name}${locked ? ', locked in' : ''}`}
    >
      <Buddy index={index} size={30} />
      <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>{name}</Text>
    </View>
  );
}

/** The round timer: a yellow sticker with the seconds left. */
export function Clock({ st, now }: { st: { t0: number; t1: number }; now: () => number }) {
  const [, tick] = useState(0);
  useEffect(() => {
    const t = setInterval(() => tick((n) => n + 1), 250);
    return () => clearInterval(t);
  }, []);
  const t = now();
  const waiting = t < st.t0;
  const left = waiting ? st.t1 - st.t0 : Math.max(0, st.t1 - t);
  const secs = Math.ceil(left / 1000);
  return (
    <View
      accessibilityLabel={`${secs} seconds left`}
      style={{ width: 58, height: 58, borderRadius: 29, borderWidth: 3, borderColor: C.ink, backgroundColor: !waiting && left < 5000 ? C.coral : C.gold, alignItems: 'center', justifyContent: 'center' }}
    >
      <Text style={{ fontFamily: F.display, fontSize: 26, lineHeight: 32, color: C.ink }}>{secs}</Text>
    </View>
  );
}

/** After a game: a signed-out host can save it to an account; a signed-in host's games are saved. */
export function SaveGames({ st }: { st: { owned?: boolean; code: string } }) {
  if (st.owned) {
    return (
      <P small muted center>
        Saved to your game history.
      </P>
    );
  }
  return (
    <Card tint={C.mintTint} style={{ gap: 8 }}>
      <H2 style={{ fontSize: 22 }}>Keep this game?</H2>
      <P small>Sign in to save who won, keep your setup for next time and write your own questions. It's optional.</P>
      <Button small kind="dark" title="Save my games" onPress={() => router.push({ pathname: '/account', params: { claim: st.code } })} />
    </Card>
  );
}

/** The gold results header: the winner's buddy, the headline and a line under it. */
export function ResultsHeader({ winners, buddyOf, sub, stopped }: { winners: string[]; buddyOf: (n: string) => number; sub?: string; stopped?: boolean }) {
  const insets = useSafeAreaInsets();
  const w = winners;
  const headline = w.length > 1 ? `${w.length} winners!` : w.length ? `${w[0]} wins!` : stopped ? 'Game over' : 'No winner';
  return (
    <View style={{ backgroundColor: C.gold, borderBottomWidth: 3, borderBottomColor: C.ink, paddingTop: insets.top + 12, paddingBottom: 20, paddingHorizontal: 20, alignItems: 'center', gap: 6 }}>
      <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>{w.length > 1 ? 'Winners' : w.length ? 'Winner' : 'Results'}</Text>
      <View style={[{ width: 120, height: 120, borderRadius: 60, backgroundColor: C.white, borderWidth: 3, borderColor: C.ink, alignItems: 'center', justifyContent: 'center', transform: [{ rotate: '-4deg' }] }, shadow(5)]}>
        {w.length ? <Buddy index={buddyOf(w[0])} size={88} cheer /> : <Logo size={80} />}
      </View>
      <Text style={{ fontFamily: F.display, fontSize: 42, lineHeight: 48, color: C.ink, textAlign: 'center', marginTop: 6 }} numberOfLines={2} adjustsFontSizeToFit>
        {headline}
      </Text>
      {w.length > 1 ? <Text style={{ fontFamily: F.bold, fontSize: 15, color: C.ink, textAlign: 'center' }}>{w.slice(0, 6).join(' · ')}</Text> : sub ? <Text style={{ fontFamily: F.bold, fontSize: 15, color: C.ink, textAlign: 'center' }}>{sub}</Text> : null}
    </View>
  );
}

type Scored = { name: string; score: number; gain: number };

/** Points standings for games that only score points. showGain adds "+500" from the last round. */
export function PointsStandings({ players, winners = [], myName, buddyOf, showGain, max = MAX_PLAYERS }: { players: Scored[]; winners?: string[]; myName: string | null; buddyOf: (n: string) => number; showGain?: boolean; max?: number }) {
  const ps = players
    .slice()
    .sort((a, b) => b.score - a.score || a.name.localeCompare(b.name))
    .slice(0, max);
  return (
    <View style={{ gap: 10 }}>
      {ps.map((x) => {
        const won = winners.includes(x.name);
        const rank = 1 + players.filter((y) => y.score > x.score).length;
        return (
          <View
            key={x.name}
            style={{ flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: won ? C.mintTint : x.name === myName ? C.goldTint : C.white, borderWidth: 3, borderColor: C.ink, borderRadius: 18, paddingVertical: 4, paddingLeft: 8, paddingRight: 14 }}
          >
            <Text style={{ width: 28, textAlign: 'center', fontFamily: F.display, fontSize: 22, color: C.ink }}>{rank}</Text>
            <Buddy index={buddyOf(x.name)} size={38} cheer={won} />
            <Text style={{ flex: 1, fontFamily: F.heavy, fontSize: 17, color: C.ink }} numberOfLines={1}>
              {x.name === myName ? `${x.name} (you)` : x.name}
            </Text>
            <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.mist }}>
              {showGain && x.gain ? `+${x.gain.toLocaleString('en-US')} · ` : ''}
              {x.score.toLocaleString('en-US')}
            </Text>
          </View>
        );
      })}
    </View>
  );
}

/** Run a host action with a busy flag and an error line. Shared by every game's controller. */
export function useHostRun(act: (action: string, extra?: object) => Promise<unknown>) {
  const [busy, setBusy] = useState('');
  const [err, setErr] = useState('');
  const run = async (action: string, extra?: object) => {
    setBusy(action);
    setErr('');
    try {
      await act(action, extra);
    } catch (e) {
      setErr((e as Error).message);
    } finally {
      setBusy('');
    }
  };
  const confirmEnd = () =>
    dialog.alert('End this game?', 'Everyone goes to the results screen.', [
      { text: 'Keep playing', style: 'cancel' },
      { text: 'End game', style: 'destructive', onPress: () => run('end') },
    ]);
  return { busy, err, setErr, run, confirmEnd };
}

/** Ticks a screen a few times a second, for countdowns. */
export function useTick(ms = 250) {
  const [, tick] = useState(0);
  useEffect(() => {
    const t = setInterval(() => tick((n) => n + 1), ms);
    return () => clearInterval(t);
  }, [ms]);
}


/**
 * The in-game host screen every game shares: round tag and clock on top, the game in the middle,
 * the navy host-controls bar at the bottom with a status line, a "next" button and End game.
 */
export function GameFrame(p: {
  round: number;
  rounds: number;
  clock?: { t0: number; t1: number } | null;
  now: () => number;
  right?: ReactNode;
  offline: boolean;
  err: string;
  status: string;
  next: { title: string; onPress: () => void; busy?: boolean; disabled?: boolean };
  onEnd: () => void;
  children: ReactNode;
}) {
  return (
    <View style={{ flex: 1, backgroundColor: C.sky }}>
      <Screen
        bg={C.sky}
        top={
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 20, paddingTop: 10, minHeight: 64 }}>
            <Tag text={`Round ${p.round}${p.rounds ? ` of ${p.rounds}` : ''}`} style={{ alignSelf: 'center', paddingHorizontal: 14, paddingVertical: 6 }} />
            {p.clock ? <Clock st={p.clock} now={p.now} /> : p.right || null}
          </View>
        }
      >
        {p.offline ? <Banner>Reconnecting…</Banner> : null}
        {p.err ? <Banner tone="bad">{p.err}</Banner> : null}
        {p.children}
      </Screen>
      <HostControls>
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
          <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.white }}>Host controls</Text>
          <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.onDark, flexShrink: 1, textAlign: 'right' }}>{p.status}</Text>
        </View>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}>
            <DarkButton kind="go" title={p.next.title} busy={p.next.busy} disabled={p.next.disabled} onPress={p.next.onPress} />
          </View>
          <DarkButton kind="danger" title="End game" onPress={p.onEnd} />
        </View>
      </HostControls>
    </View>
  );
}

/** The lobby every game shares: join card, TV card, players, "play too", the game's settings. */
export function LobbyFrame(p: {
  title: string;
  code: string;
  path: string;
  players: { id?: number; name: string }[];
  myName: string | null;
  playLine: string;
  onPlayToo: () => void;
  onKick: (pl: { id?: number; name: string }) => void;
  onLeave: () => void;
  onClose: () => void;
  onStart: () => void;
  starting: boolean;
  offline: boolean;
  err: string;
  tv: ReactNode;
  settings: ReactNode;
}) {
  const n = p.players.length;
  return (
    <Screen
      top={<TopBar onBack={p.onLeave} backLabel="Back to home" title={p.title} right={<Logo size={44} />} />}
      footer={
        <>
          <Button kind="hot" big title={n ? 'Start the game' : 'Waiting for players'} disabled={!n} busy={p.starting} onPress={p.onStart} />
          <Pressable accessibilityRole="button" onPress={p.onClose} style={{ alignSelf: 'center', minHeight: 44, justifyContent: 'center', paddingHorizontal: 12 }}>
            <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.out }}>Close room</Text>
          </Pressable>
        </>
      }
    >
      {p.offline ? <Banner>Reconnecting…</Banner> : null}
      {p.err ? <Banner tone="bad">{p.err}</Banner> : null}
      <JoinCard code={p.code} path={p.path} />
      {p.tv}
      <PlayersGrid st={{ players: p.players }} myName={p.myName} onKick={p.onKick} />
      <PlayToo on={!!p.myName} name={p.myName} onPress={p.onPlayToo} line={p.playLine} />
      <Card tint={C.white} style={{ gap: 14 }}>
        <H2 style={{ fontSize: 22 }}>Game settings</H2>
        {p.settings}
      </Card>
    </Screen>
  );
}
