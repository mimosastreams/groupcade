// The host's controller for a Trivia room: lobby with code, QR, players and settings; then the
// game as it runs (the TV shows the big version); then winners. All native, no web view.
import QRCode from 'react-native-qrcode-svg';
import { useEffect, useState } from 'react';
import * as Haptics from 'expo-haptics';
import { Pressable, Share, Text, View } from 'react-native';
import { dialog } from '../../ui/Dialog';
import { api, links, type Player, type RoomState } from '../../lib/api';
import { Banner, Button, C, Card, Chip, H2, Label, P, Row, Screen, s } from '../../ui/kit';
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
  onNewRoom: () => void;
};

const L = ['A', 'B', 'C', 'D'];
const LEVEL_COLOR = { easy: C.right, medium: C.gold, hard: C.out } as const;

export function TriviaHost(p: Props) {
  const { st } = p;
  const [busy, setBusy] = useState('');
  const [err, setErr] = useState('');
  const [answering, setAnswering] = useState(false);
  const [sent, setSent] = useState(0); // Answer just tapped, shown until the next poll confirms it.
  const [hint, setHint] = useState(false); // A tap lights up the "slide to lock in" hint.
  useEffect(() => {
    setSent(0);
    setHint(false);
  }, [st.round]);

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

  if (st.phase === 'lobby') {
    const n = st.players.length;
    return (
      <Screen
        footer={
          <>
            <Button kind="go" title={n ? `Start game · ${n} player${n === 1 ? '' : 's'}` : 'Waiting for players'} disabled={!n} busy={busy === 'start'} onPress={() => run('start')} />
            {!p.myName ? <Button kind="ghost" small title="Play too (answer on this phone)" onPress={p.onPlayToo} /> : null}
          </>
        }
      >
        {banners}
        <JoinCard code={st.code} path={st.game} />
        <TvCard code={st.code} />
        <PlayersCard st={st} myName={p.myName} onKick={(pl) => kick(pl, run)} />
        <Card>
          <LobbySettings st={st} onChange={(x) => run('settings', x)} />
          {n === 1 && st.mode === 'survive' ? <P muted small>With one player it's practice: one miss ends it.</P> : null}
        </Card>
      </Screen>
    );
  }

  if (st.phase === 'end') {
    const w = st.winners || [];
    return (
      <Screen
        footer={
          <>
            <Button kind="go" title="Play again, same players" busy={busy === 'again'} onPress={() => run('again')} />
            <Button kind="ghost" small title="New room" onPress={p.onNewRoom} />
          </>
        }
      >
        {banners}
        <Card style={{ alignItems: 'center', paddingVertical: 28 }}>
          <Label>{w.length > 1 ? 'Winners' : w.length ? 'Winner' : st.stopped ? 'Game ended' : 'No winner'}</Label>
          <Text style={{ color: C.gold, fontSize: 36, fontWeight: '900', textAlign: 'center' }}>{w.length ? w.slice(0, 6).join(' · ') : '—'}</Text>
          {st.round ? <P muted>After {st.round} round{st.round === 1 ? '' : 's'}</P> : null}
        </Card>
        <Card>
          <H2>Standings</H2>
          <Standings st={st} myName={p.myName} />
        </Card>
        <TvCard code={st.code} compact />
      </Screen>
    );
  }

  // cat, q, rev
  // The host can play too: slide an answer to lock it in when they're still in and haven't locked in.
  const me = p.myToken && st.me ? st.me : null;
  const myAns = me && (st.phase === 'q' || st.phase === 'rev') ? sent || me.ans : 0;
  const canAnswer = !!me && me.alive && st.phase === 'q' && !myAns && !answering;
  const last = st.phase === 'rev' && st.last && st.last.round === st.round ? st.last : null;
  const finalReveal = last && (st.mode === 'points' ? st.round >= st.rounds : st.alive <= 1 && st.players.length > 1);
  return (
    <Screen
      footer={
        <>
          {st.phase === 'rev' ? <Button kind="go" title={finalReveal ? 'See the winner →' : 'Next round →'} busy={busy === 'next'} onPress={() => run('next')} /> : null}
          <Row style={{ justifyContent: 'space-between' }}>
            <Button kind="danger" small title="End game" onPress={confirmEnd} />
          </Row>
        </>
      }
    >
      {banners}
      <Row>
        <Text style={{ color: C.mist, fontWeight: '800' }}>
          Round {st.round}
          {st.rounds ? ` of ${st.rounds}` : ''}
        </Text>
        {st.level ? (
          <Text style={{ color: LEVEL_COLOR[st.level], fontWeight: '900', textTransform: 'uppercase', letterSpacing: 1, fontSize: 13 }}>{st.level}</Text>
        ) : null}
        <View style={{ flex: 1 }} />
        <Text style={{ color: C.mist, fontWeight: '700' }}>
          <Text style={{ color: C.white }}>{st.alive}</Text> {st.mode === 'points' ? 'playing' : 'standing'}
        </Text>
      </Row>

      {me ? <MyStatus st={st} me={me} myAns={myAns} /> : null}
      <Card>
        <Text style={{ color: C.accent, fontWeight: '900', fontSize: 15, textTransform: 'uppercase', letterSpacing: 1 }}>{st.cat}</Text>
        {st.phase === 'cat' ? <P muted>Category card is on the TV…</P> : <Text style={{ color: C.white, fontSize: 22, fontWeight: '800', lineHeight: 29 }}>{st.q}</Text>}
        {st.phase === 'q' ? <Clock st={st} now={p.now} /> : null}
      </Card>

      {canAnswer && st.phase === 'q' ? (
        <Text style={{ color: hint ? C.accent : C.mist, fontWeight: '700', textAlign: 'center' }}>Slide an answer right to lock it in →</Text>
      ) : null}
      {st.phase !== 'cat' ? (
        <View style={{ gap: 8 }}>
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
                  s.card,
                  { padding: 12, gap: 12 },
                  mine && { borderColor: C.accent, backgroundColor: 'rgba(255,79,163,0.14)' },
                  right && { borderColor: C.right, backgroundColor: 'rgba(91,227,139,0.12)' },
                  last && !right && !mine && { opacity: 0.5 },
                  !!myAns && !mine && !last && { opacity: 0.5 },
                ]}
              >
                <Text style={{ color: C.answers[i], fontWeight: '900', fontSize: 18, width: 22 }}>{L[i]}</Text>
                <Text style={{ color: C.white, fontWeight: '700', fontSize: 17, flex: 1 }}>{c}</Text>
                {mine ? <Text style={{ color: C.accent, fontWeight: '800' }}>{last ? 'You' : 'Locked in'}</Text> : null}
                {last ? <Text style={{ color: C.mist, fontWeight: '800' }}>{last.counts[i]}</Text> : null}
              </SwipeAnswer>
            );
          })}
        </View>
      ) : null}

      {st.phase === 'q' ? (
        <P muted center>
          {st.locked} of {st.alive} locked in
        </P>
      ) : null}
      {last ? <Verdict st={st} /> : null}

      <Card>
        <Label>{st.mode === 'points' ? 'Scores' : `Still in · ${st.alive}`}</Label>
        {st.mode === 'points' ? (
          <Standings st={st} myName={p.myName} max={8} />
        ) : (
          <Row style={{ gap: 8 }}>
            {st.players
              .filter((x) => x.alive)
              .map((x) => (
                <Chip key={x.name} text={x.name} tone={st.phase === 'q' && x.in ? 'in' : x.name === p.myName ? 'me' : undefined} />
              ))}
          </Row>
        )}
      </Card>
      <TvCard code={st.code} compact />
    </Screen>
  );
}

function kick(pl: Player, run: (a: string, x?: object) => Promise<void>) {
  dialog.alert(`Remove ${pl.name}?`, 'They can join again with a new name.', [
    { text: 'Cancel', style: 'cancel' },
    { text: 'Remove', style: 'destructive', onPress: () => run('kick', { player: pl.id }) },
  ]);
}

function JoinCard({ code, path }: { code: string; path: string }) {
  const url = links.game(path, code);
  return (
    <Card style={{ alignItems: 'center' }}>
      <P muted>Join on your phone at</P>
      <Text style={{ color: C.white, fontWeight: '800', fontSize: 18 }}>{links.short(path)}</Text>
      <Text style={s.code} accessibilityLabel={`Room code ${code.split('').join(' ')}`}>
        {code}
      </Text>
      <View style={{ backgroundColor: '#fff', padding: 10, borderRadius: 10 }}>
        <QRCode value={url} size={170} backgroundColor="#ffffff" color="#000000" />
      </View>
      <Button small title="Share link" onPress={() => Share.share({ message: `Join my Groupcade game! Code ${code}: ${url}` })} />
    </Card>
  );
}

function PlayersCard({ st, myName, onKick }: { st: RoomState; myName: string | null; onKick: (p: Player) => void }) {
  return (
    <Card>
      <Row style={{ justifyContent: 'space-between' }}>
        <H2>Players</H2>
        <Text style={{ color: C.mist, fontWeight: '800' }}>
          <Text style={{ color: C.white }}>{st.players.length}</Text> / 30
        </Text>
      </Row>
      {st.players.length ? (
        <>
          <Row style={{ gap: 8 }}>
            {st.players.map((x) => (
              <Chip key={x.id ?? x.name} text={x.name === myName ? `${x.name} (you)` : x.name} tone={x.name === myName ? 'me' : undefined} onPress={() => onKick(x)} />
            ))}
          </Row>
          <P muted small>Tap a name to remove them.</P>
        </>
      ) : (
        <P muted>Waiting for players to join…</P>
      )}
    </Card>
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

function Clock({ st, now }: { st: RoomState; now: () => number }) {
  const [, tick] = useState(0);
  useEffect(() => {
    const t = setInterval(() => tick((n) => n + 1), 250);
    return () => clearInterval(t);
  }, []);
  const t = now();
  if (t < st.t0) {
    return <Text style={{ color: C.accent, fontWeight: '800' }}>Clock starts in {Math.ceil((st.t0 - t) / 1000)}</Text>;
  }
  const total = Math.max(1, st.t1 - st.t0);
  const left = Math.max(0, st.t1 - t);
  return (
    <Row style={{ flexWrap: 'nowrap' }}>
      <View style={{ flex: 1, height: 8, borderRadius: 4, backgroundColor: C.ink, overflow: 'hidden' }}>
        <View style={{ width: `${(left / total) * 100}%`, height: '100%', backgroundColor: left < 5000 ? C.out : C.accent }} />
      </View>
      <Text style={{ color: C.white, fontWeight: '900', width: 36, textAlign: 'right' }}>{Math.ceil(left / 1000)}s</Text>
    </Row>
  );
}

/** The host's own line as a player: in or out, their score, and how their answer went. */
function MyStatus({ st, me, myAns }: { st: RoomState; me: NonNullable<RoomState['me']>; myAns: number }) {
  const last = st.phase === 'rev' && st.last && st.last.round === st.round ? st.last : null;
  const pts = st.mode === 'points';
  let line: string;
  let color: string = C.mist;
  if (!pts && !me.alive && !(last && myAns)) {
    line = "You're out. Keep hosting, and you're back in next game.";
    color = C.out;
  } else if (last) {
    const right = myAns === last.right + 1;
    if (!myAns) line = pts ? 'No answer this round.' : "You didn't answer.";
    else line = right ? (pts ? `Right! +${me.gain}` : 'Right! You\'re still in.') : pts ? 'Wrong this time.' : "Wrong, you're out.";
    color = right ? C.right : C.out;
  } else if (st.phase === 'q') {
    line = myAns ? 'Locked in. Waiting for the others…' : 'Your turn: tap an answer below.';
    color = myAns ? C.mist : C.accent;
  } else {
    line = pts ? 'Get ready…' : "You're in. Get ready…";
  }
  return (
    <Row style={{ justifyContent: 'space-between' }}>
      <Text style={{ color, fontWeight: '800', flex: 1 }}>
        {me.name}: {line}
      </Text>
      {pts ? <Text style={{ color: C.white, fontWeight: '900' }}>{me.score}</Text> : null}
    </Row>
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

function Standings({ st, myName, max = 30 }: { st: RoomState; myName: string | null; max?: number }) {
  const pts = st.mode === 'points';
  const ps = st.players
    .slice()
    .sort((a, b) => (pts ? b.score - a.score : Number(b.alive) - Number(a.alive) || b.best - a.best) || a.name.localeCompare(b.name))
    .slice(0, max);
  return (
    <View style={{ gap: 6 }}>
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
          <Row key={x.name} style={{ backgroundColor: C.ink, borderRadius: 8, padding: 10, flexWrap: 'nowrap' }}>
            <Text style={{ color: won ? C.ink : C.accent, backgroundColor: won ? C.accent : 'rgba(255,79,163,0.16)', borderRadius: 6, width: 30, textAlign: 'center', fontWeight: '900', overflow: 'hidden' }}>
              {won ? '★' : rank}
            </Text>
            <Text style={{ color: x.name === myName ? C.accent : C.white, fontWeight: '700', flex: 1 }} numberOfLines={1}>
              {x.name}
            </Text>
            <Text style={{ color: C.mist, fontWeight: '700' }}>{note}</Text>
          </Row>
        );
      })}
    </View>
  );
}
