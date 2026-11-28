// The host's controller for a Trivia room: lobby with code, players and settings; then the
// game as it runs (the TV shows the big version); then winners. All native, no web view.
import * as Haptics from 'expo-haptics';
import { useEffect, useState, type ReactNode } from 'react';
import * as Clipboard from 'expo-clipboard';
import { Pressable, Text, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import Svg, { Path } from 'react-native-svg';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { api, links, type Player, type RoomState } from '../../lib/api';
import { Buddy } from '../../ui/Buddy';
import { dialog } from '../../ui/Dialog';
import { Banner, Button, C, Card, F, H2, P, Row, Screen, TopBar, Tag, buddyFor, shadow } from '../../ui/kit';
import { Logo } from '../../ui/Logo';
import { SwipeAnswer } from '../../ui/SwipeAnswer';
import { TvCard } from '../../ui/TvCard';
import { NO_CATS, noCats, TriviaSettingsForm, type TriviaSettings } from './Settings';

type Props = {
  st: RoomState;
  act: (action: string, extra?: object) => Promise<unknown>;
  now: () => number;
  offline: boolean;
  myName: string | null; // The host's own player name, if they joined to play.
  myToken: string | null; // Their player token, so they can answer right here.
  onAnswered: () => void;
  onPlayToo: () => void;
  onLeave: () => void; // Back to home; the room stays open.
  onClose: () => void; // Close the room (asks first).
  onNewRoom: () => void; // Close this room and set up a new one (asks first).
};

const L = ['A', 'B', 'C', 'D'];
const MAX_PLAYERS = 50;
const modeName = (m: RoomState['mode']) => (m === 'points' ? 'Points' : 'Survival');

export function TriviaHost(p: Props) {
  const { st } = p;
  const insets = useSafeAreaInsets();
  const [busy, setBusy] = useState('');
  const [err, setErr] = useState('');
  const [answering, setAnswering] = useState(false);
  const [sent, setSent] = useState(0); // Answer just locked in, shown until the next poll confirms it.
  const [hint, setHint] = useState(false); // A tap lights up the "slide to lock in" hint.
  useEffect(() => {
    setSent(0);
    setHint(false);
  }, [st.round]);

  // Buddy colors follow join order, the same on every screen.
  const order = new Map(st.players.map((x, i) => [x.name, i]));
  const buddyOf = (name: string) => order.get(name) ?? 0;

  const answer = async (choice: number) => {
    if (!p.myToken || answering) return;
    setAnswering(true);
    setErr('');
    try {
      await api.answer(st.code, p.myToken, st.round, choice);
      setSent(choice);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
      p.onAnswered();
    } catch (e) {
      setErr((e as Error).message);
    } finally {
      setAnswering(false);
    }
  };

  const run = async (action: string, extra?: object) => {
    setBusy(action);
    setErr('');
    try {
      await p.act(action, extra);
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

  const banners = (
    <>
      {p.offline ? <Banner>Reconnecting…</Banner> : null}
      {err ? <Banner tone="bad">{err}</Banner> : null}
    </>
  );

  // ------------------------------------------------------------------ lobby
  if (st.phase === 'lobby') {
    const n = st.players.length;
    return (
      <Screen
        top={<TopBar onBack={p.onLeave} backLabel="Back to home" title={`Trivia · ${modeName(st.mode)}`} right={<Logo size={44} />} />}
        footer={
          <>
            <Button kind="hot" big title={n ? 'Start the game' : 'Waiting for players'} disabled={!n} busy={busy === 'start'} onPress={() => run('start')} />
            <Pressable accessibilityRole="button" onPress={p.onClose} style={{ alignSelf: 'center', minHeight: 44, justifyContent: 'center', paddingHorizontal: 12 }}>
              <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.out }}>Close room</Text>
            </Pressable>
          </>
        }
      >
        {banners}
        <JoinCard code={st.code} path={st.game} />
        <TvCard code={st.code} />
        <PlayersGrid st={st} myName={p.myName} onKick={(pl) => kick(pl, run)} />
        <PlayToo on={!!p.myName} name={p.myName} onPress={p.onPlayToo} />
        <Card tint={C.white} style={{ gap: 14 }}>
          <H2 style={{ fontSize: 22 }}>Game settings</H2>
          <LobbySettings st={st} onChange={(x) => run('settings', x)} />
          {n === 1 && st.mode === 'survive' ? <P muted small>With one player it's practice: one miss ends it.</P> : null}
        </Card>
      </Screen>
    );
  }

  // ------------------------------------------------------------------ results
  if (st.phase === 'end') {
    const w = st.winners || [];
    const headline = w.length > 1 ? `${w.length} winners!` : w.length ? `${w[0]} wins!` : st.stopped ? 'Game over' : 'No winner';
    const sub =
      w.length > 1
        ? w.slice(0, 6).join(' · ')
        : st.mode === 'points'
          ? `Most points after ${st.round} round${st.round === 1 ? '' : 's'}`
          : w.length
            ? `Last one standing after ${st.round} round${st.round === 1 ? '' : 's'}`
            : st.round
              ? `After ${st.round} round${st.round === 1 ? '' : 's'}`
              : '';
    return (
      <View style={{ flex: 1, backgroundColor: C.bg }}>
        <View style={{ backgroundColor: C.gold, borderBottomWidth: 3, borderBottomColor: C.ink, paddingTop: insets.top + 12, paddingBottom: 20, paddingHorizontal: 20, alignItems: 'center', gap: 6 }}>
          <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>{w.length > 1 ? 'Winners' : w.length ? 'Winner' : 'Results'}</Text>
          <View style={[{ width: 120, height: 120, borderRadius: 60, backgroundColor: C.white, borderWidth: 3, borderColor: C.ink, alignItems: 'center', justifyContent: 'center', transform: [{ rotate: '-4deg' }] }, shadow(5)]}>
            {w.length ? <Buddy index={buddyOf(w[0])} size={88} cheer /> : <Logo size={80} />}
          </View>
          <Text style={{ fontFamily: F.display, fontSize: 42, lineHeight: 48, color: C.ink, textAlign: 'center', marginTop: 6 }} numberOfLines={2} adjustsFontSizeToFit>
            {headline}
          </Text>
          {sub ? <Text style={{ fontFamily: F.bold, fontSize: 15, color: C.ink, textAlign: 'center' }}>{sub}</Text> : null}
        </View>
        <Screen
          footer={
            <>
              <Button kind="go" title="Play again, same players" busy={busy === 'again'} onPress={() => run('again')} />
              <Button small title="New room" onPress={p.onNewRoom} />
            </>
          }
        >
          {banners}
          <SaveGames st={st} />
          <H2>Standings</H2>
          <Standings st={st} myName={p.myName} buddyOf={buddyOf} />
          <TvCard code={st.code} compact />
        </Screen>
      </View>
    );
  }

  // ------------------------------------------------------------------ cat, q, rev
  // The host can play too: slide an answer to lock it in while they're still in.
  const me = p.myToken && st.me ? st.me : null;
  const myAns = me && (st.phase === 'q' || st.phase === 'rev') ? sent || me.ans : 0;
  const canAnswer = !!me && me.alive && st.phase === 'q' && !myAns && !answering;
  const last = st.phase === 'rev' && st.last && st.last.round === st.round ? st.last : null;
  const finalReveal = last && (st.mode === 'points' ? st.round >= st.rounds : st.alive <= 1 && st.players.length > 1);
  return (
    <View style={{ flex: 1, backgroundColor: C.sky }}>
      <Screen
        bg={C.sky}
        top={
          <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 20, paddingTop: 10, minHeight: 64 }}>
            <Tag text={`Round ${st.round}${st.rounds ? ` of ${st.rounds}` : ''}`} style={{ alignSelf: 'center', paddingHorizontal: 14, paddingVertical: 6 }} />
            {st.phase === 'q' ? <Clock st={st} now={p.now} /> : <Tag text={`${st.alive} ${st.mode === 'points' ? 'playing' : 'still in'}`} style={{ alignSelf: 'center' }} />}
          </View>
        }
      >
        {banners}
        <Row style={{ gap: 8 }}>
          {st.cat ? <Tag text={st.cat} bg={C.mint} /> : null}
          {st.level ? <Tag text={st.level[0].toUpperCase() + st.level.slice(1)} bg={st.level === 'hard' ? C.coralTint : st.level === 'medium' ? C.goldTint : C.white} /> : null}
        </Row>
        <Card lifted tilt={-0.8} style={{ borderRadius: 26, padding: 18 }}>
          {st.phase === 'cat' ? (
            <Text style={{ fontFamily: F.display, fontSize: 26, lineHeight: 32, color: C.ink }}>Get ready… the category is on the TV.</Text>
          ) : (
            <Text style={{ fontFamily: F.display, fontSize: 26, lineHeight: 30, color: C.ink }}>{st.q}</Text>
          )}
          {st.phase === 'q' && p.now() < st.t0 ? (
            <Text style={{ fontFamily: F.heavy, color: C.accent, fontSize: 15 }}>Clock starts in {Math.ceil((st.t0 - p.now()) / 1000)}</Text>
          ) : null}
        </Card>

        {me ? <MyStatus st={st} me={me} myAns={myAns} /> : null}
        {canAnswer ? (
          <Text style={{ fontFamily: F.heavy, fontSize: 14, color: hint ? C.accent : C.mist }}>Slide an answer right to lock it in →</Text>
        ) : null}
        {st.phase !== 'cat' ? (
          <View style={{ gap: 10 }}>
            {(st.choices || []).map((c, i) => {
              const right = last && last.right === i;
              const mine = myAns === i + 1;
              return (
                <SwipeAnswer
                  key={i}
                  enabled={canAnswer}
                  label={`Answer ${L[i]}: ${c}`}
                  onLock={() => answer(i + 1)}
                  onTap={() => setHint(true)}
                  style={[
                    { minHeight: 64, backgroundColor: C.white, borderWidth: 3, borderColor: C.ink, borderRadius: 20, paddingVertical: 8, paddingLeft: 8, paddingRight: 12, gap: 12 },
                    mine && !last && { backgroundColor: C.goldTint },
                    right && { backgroundColor: C.mintTint },
                    last && mine && !right && { backgroundColor: C.coralTint },
                    last && !right && !mine && { opacity: 0.55 },
                    !!myAns && !mine && !last && { opacity: 0.55 },
                  ]}
                >
                  <View style={{ width: 42, height: 42, borderRadius: 14, borderWidth: 3, borderColor: C.ink, backgroundColor: C.answers[i], alignItems: 'center', justifyContent: 'center' }}>
                    <Text style={{ fontFamily: F.display, fontSize: 20, lineHeight: 26, color: C.ink }}>{L[i]}</Text>
                  </View>
                  <Text style={{ fontFamily: F.heavy, fontSize: 18, color: C.ink, flex: 1 }}>{c}</Text>
                  {right ? <Check /> : null}
                  {mine ? <Text style={{ fontFamily: F.heavy, color: C.ink, fontSize: 14 }}>{last ? 'You' : 'Locked in'}</Text> : null}
                  {last ? <Text style={{ fontFamily: F.display, color: C.ink, fontSize: 18, minWidth: 22, textAlign: 'right' }}>{last.counts[i]}</Text> : null}
                </SwipeAnswer>
              );
            })}
          </View>
        ) : null}
        {last ? <Verdict st={st} /> : null}

        <View style={{ gap: 10 }}>
          <H2 style={{ fontSize: 22 }}>{st.mode === 'points' ? 'Scores' : `Still in · ${st.alive}`}</H2>
          {st.mode === 'points' ? (
            <Standings st={st} myName={p.myName} buddyOf={buddyOf} max={8} />
          ) : (
            <Row style={{ gap: 8 }}>
              {st.players
                .filter((x) => x.alive)
                .map((x) => (
                  <NameTag key={x.name} name={x.name} index={buddyOf(x.name)} locked={st.phase === 'q' && x.in} me={x.name === p.myName} />
                ))}
            </Row>
          )}
        </View>
        <TvCard code={st.code} compact />
      </Screen>

      <HostControls>
        <Row style={{ justifyContent: 'space-between' }}>
          <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.white }}>Host controls</Text>
          <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.onDark }}>
            {st.phase === 'q' ? `${st.locked} of ${st.alive} answered` : st.phase === 'cat' ? 'Category on the TV' : 'Answer on the TV'}
          </Text>
        </Row>
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}>
            {st.phase === 'rev' ? (
              <DarkButton kind="go" title={finalReveal ? 'See the winner →' : 'Next round →'} busy={busy === 'next'} onPress={() => run('next')} />
            ) : (
              <DarkButton kind="go" title="Next round →" disabled onPress={() => {}} />
            )}
          </View>
          <DarkButton kind="danger" title="End game" onPress={confirmEnd} />
        </View>
      </HostControls>
    </View>
  );
}

function kick(pl: Player, run: (a: string, x?: object) => Promise<void>) {
  dialog.alert(`Remove ${pl.name}?`, 'They can join again with a new name.', [
    { text: 'Cancel', style: 'cancel' },
    { text: 'Remove', style: 'destructive', onPress: () => run('kick', { player: pl.id }) },
  ]);
}

function HostControls({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={{ backgroundColor: C.ink, borderTopLeftRadius: 28, borderTopRightRadius: 28, paddingHorizontal: 20, paddingTop: 14, paddingBottom: insets.bottom + 14, gap: 10 }}>{children}</View>
  );
}

/** Buttons on the navy host-controls bar: white outlines instead of navy. */
function DarkButton({ title, onPress, kind, busy, disabled }: { title: string; onPress: () => void; kind: 'go' | 'danger'; busy?: boolean; disabled?: boolean }) {
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

function Check() {
  return (
    <Svg width={22} height={22} viewBox="0 0 24 24" fill="none" stroke={C.right} strokeWidth={4} strokeLinecap="round" strokeLinejoin="round" accessibilityLabel="Right answer">
      <Path d="M4 12l5 5L20 6" />
    </Svg>
  );
}

function JoinCard({ code, path }: { code: string; path: string }) {
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

function PlayersGrid({ st, myName, onKick }: { st: RoomState; myName: string | null; onKick: (p: Player) => void }) {
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
function PlayToo({ on, name, onPress }: { on: boolean; name: string | null; onPress: () => void }) {
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
      <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink, flex: 1 }}>{on ? `Playing too as ${name}` : 'Play too (answer on this phone)'}</Text>
    </Pressable>
  );
}

function NameTag({ name, index, locked, me }: { name: string; index: number; locked?: boolean; me?: boolean }) {
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

function LobbySettings({ st, onChange }: { st: RoomState; onChange: (x: object) => void }) {
  const all = st.allCats || [];
  // The server always has at least one category on, so "cleared, nothing picked yet" lives here
  // until the host taps one.
  const [cleared, setCleared] = useState(false);
  const value: TriviaSettings = { mode: st.mode, rounds: st.rounds, secs: st.secs, cats: cleared ? [NO_CATS] : st.only };
  return (
    <TriviaSettingsForm
      value={value}
      allCats={all}
      specCats={st.specCats || []}
      onChange={(x) => {
        const out: Record<string, unknown> = { ...x };
        if (x.cats) {
          if (noCats({ cats: x.cats })) {
            setCleared(true);
            delete out.cats;
            if (!Object.keys(out).length) return;
          } else {
            setCleared(false);
            out.cats = x.cats.length ? x.cats : all; // The server wants the full list for "all".
          }
        }
        onChange(out);
      }}
    />
  );
}

/** The round timer: a yellow sticker with the seconds left. */
function Clock({ st, now }: { st: RoomState; now: () => number }) {
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

/** The host's own line as a player: in or out, their score, and how their answer went. */
function MyStatus({ st, me, myAns }: { st: RoomState; me: NonNullable<RoomState['me']>; myAns: number }) {
  const last = st.phase === 'rev' && st.last && st.last.round === st.round ? st.last : null;
  const pts = st.mode === 'points';
  let line: string;
  let tone: 'info' | 'good' | 'bad' | 'plain' = 'plain';
  if (!pts && !me.alive && !(last && myAns)) {
    line = "You're out. Keep hosting, and you're back in next game.";
    tone = 'bad';
  } else if (last) {
    const right = myAns === last.right + 1;
    if (!myAns) line = pts ? 'No answer this round.' : "You didn't answer.";
    else line = right ? (pts ? `Right! +${me.gain}` : "Right! You're still in.") : pts ? 'Wrong this time.' : "Wrong, you're out.";
    tone = right ? 'good' : 'bad';
  } else if (st.phase === 'q') {
    line = myAns ? 'Locked in! Waiting for the others…' : 'Your turn to answer.';
    tone = myAns ? 'good' : 'plain';
  } else {
    line = pts ? 'Get ready…' : "You're in. Get ready…";
  }
  const bg = tone === 'good' ? C.mintTint : tone === 'bad' ? C.coralTint : C.white;
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: bg, borderWidth: 3, borderColor: C.ink, borderRadius: 18, paddingVertical: 8, paddingHorizontal: 12 }}>
      <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink, flex: 1 }}>
        {me.name}: {line}
      </Text>
      {pts ? <Text style={{ fontFamily: F.display, fontSize: 20, color: C.ink }}>{me.score.toLocaleString('en-US')}</Text> : null}
    </View>
  );
}

function Verdict({ st }: { st: RoomState }) {
  const x = st.last!;
  let line: string;
  let tone: 'good' | 'bad' | 'info' = 'info';
  if (st.mode === 'points') {
    line = `${x.nright || 0} of ${x.left} got it right`;
    tone = 'good';
  } else if (x.revived) {
    line = `Everyone missed, so nobody is out! ${x.left} still standing.`;
    tone = 'good';
  } else if (x.outN) {
    line = `Out: ${x.out.join(', ')}${x.outN > x.out.length ? ` +${x.outN - x.out.length} more` : ''}. ${x.left} still standing.`;
    tone = 'bad';
  } else {
    line = `Nobody out! ${x.left} still standing.`;
    tone = 'good';
  }
  return (
    <View style={{ gap: 6 }}>
      <Banner tone={tone}>{line}</Banner>
      {x.fast ? (
        <P muted small>
          Fastest right answer: {x.fast.name} ({(x.fast.ms / 1000).toFixed(1)}s)
        </P>
      ) : null}
    </View>
  );
}

function Standings({ st, myName, buddyOf, max = MAX_PLAYERS }: { st: RoomState; myName: string | null; buddyOf: (n: string) => number; max?: number }) {
  const pts = st.mode === 'points';
  const ps = st.players
    .slice()
    .sort((a, b) => (pts ? b.score - a.score : Number(b.alive) - Number(a.alive) || b.best - a.best) || a.name.localeCompare(b.name))
    .slice(0, max);
  return (
    <View style={{ gap: 10 }}>
      {ps.map((x, i) => {
        const won = (st.winners || []).includes(x.name) && (pts || x.alive);
        const rank = pts ? 1 + st.players.filter((y) => y.score > x.score).length : i + 1;
        const note = pts
          ? `${x.gain && st.phase === 'rev' ? `+${x.gain} · ` : ''}${x.score.toLocaleString('en-US')}`
          : won
            ? 'Winner'
            : x.ko
              ? `Out in round ${x.ko}`
              : x.best
                ? `Survived ${x.best}`
                : st.phase === 'end'
                  ? 'Watched'
                  : 'In';
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
            <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.mist }}>{note}</Text>
          </View>
        );
      })}
    </View>
  );
}

/** After a game: a signed-out host can save it to an account; a signed-in host's games are saved. */
function SaveGames({ st }: { st: RoomState }) {
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
