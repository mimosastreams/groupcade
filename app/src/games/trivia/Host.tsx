// The host's controller for a Trivia room: lobby with code, QR, players and settings; then the
// game as it runs (the TV shows the big version); then winners. All native, no web view.
import QRCode from 'react-native-qrcode-svg';
import { useEffect, useState } from 'react';
import { Alert, Share, Text, View } from 'react-native';
import { links, type Player, type RoomState } from '../../lib/api';
import { Banner, Button, C, Card, Chip, H2, Label, P, Row, Screen, s } from '../../ui/kit';
import { TvCard } from '../../ui/TvCard';
import { TriviaSettingsForm, type TriviaSettings } from './Settings';

type Props = {
  st: RoomState;
  act: (action: string, extra?: object) => Promise<unknown>;
  now: () => number;
  offline: boolean;
  myName: string | null; // The host's own player name, if they joined to play.
  onPlayToo: () => void;
  onNewRoom: () => void;
};

const L = ['A', 'B', 'C', 'D'];
const LEVEL_COLOR = { easy: C.right, medium: C.gold, hard: C.out } as const;

export function TriviaHost(p: Props) {
  const { st } = p;
  const [busy, setBusy] = useState('');
  const [err, setErr] = useState('');

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
    Alert.alert('End this game?', 'Everyone goes to the results screen.', [
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
            {!p.myName ? <Button kind="ghost" small title="Play on this phone too" onPress={p.onPlayToo} /> : null}
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
  const last = st.phase === 'rev' && st.last && st.last.round === st.round ? st.last : null;
  const finalReveal = last && (st.mode === 'points' ? st.round >= st.rounds : st.alive <= 1 && st.players.length > 1);
  return (
    <Screen
      footer={
        <>
          {st.phase === 'rev' ? <Button kind="go" title={finalReveal ? 'See the winner →' : 'Next round →'} busy={busy === 'next'} onPress={() => run('next')} /> : null}
          <Row style={{ justifyContent: 'space-between' }}>
            <Button kind="danger" small title="End game" onPress={confirmEnd} />
            {p.myName ? <Button kind="ghost" small title="My answers →" onPress={p.onPlayToo} /> : null}
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

      <Card>
        <Text style={{ color: C.accent, fontWeight: '900', fontSize: 15, textTransform: 'uppercase', letterSpacing: 1 }}>{st.cat}</Text>
        {st.phase === 'cat' ? <P muted>Category card is on the TV…</P> : <Text style={{ color: C.white, fontSize: 22, fontWeight: '800', lineHeight: 29 }}>{st.q}</Text>}
        {st.phase === 'q' ? <Clock st={st} now={p.now} /> : null}
      </Card>

      {st.phase !== 'cat' ? (
        <View style={{ gap: 8 }}>
          {(st.choices || []).map((c, i) => {
            const right = last && last.right === i;
            return (
              <View key={i} style={[s.card, { flexDirection: 'row', alignItems: 'center', padding: 12, gap: 12 }, right && { borderColor: C.right, backgroundColor: 'rgba(91,227,139,0.12)' }, last && !right && { opacity: 0.5 }]}>
                <Text style={{ color: C.answers[i], fontWeight: '900', fontSize: 18, width: 22 }}>{L[i]}</Text>
                <Text style={{ color: C.white, fontWeight: '700', fontSize: 17, flex: 1 }}>{c}</Text>
                {last ? <Text style={{ color: C.mist, fontWeight: '800' }}>{last.counts[i]}</Text> : null}
              </View>
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
  Alert.alert(`Remove ${pl.name}?`, 'They can join again with a new name.', [
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
          <Text style={{ color: C.white }}>{st.players.length}</Text> / 50
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
  const value: TriviaSettings = { mode: st.mode, rounds: st.rounds, secs: st.secs, cats: st.only };
  return (
    <TriviaSettingsForm
      value={value}
      allCats={all}
      specCats={st.specCats || []}
      onChange={(x) => {
        const out: Record<string, unknown> = { ...x };
        if (x.cats) out.cats = x.cats.length ? x.cats : all; // The server wants the full list for "all".
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
