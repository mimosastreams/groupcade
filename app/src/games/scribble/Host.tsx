// The host's controller for a Scribble Squad room: lobby, then each round (everyone draws, the TV
// shows the sketches one at a time while players guess, scores), Best in show, then winners. The
// host can play too: they draw on a pad and type guesses right here.
import * as Haptics from 'expo-haptics';
import { useEffect, useRef, useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import { api, type ScribbleConfig, type ScribbleState } from '../../lib/api';
import { useGameConfig } from '../../lib/gameConfig';
import { Field } from '../../ui/Field';
import { Banner, Button, C, Card, F, H2, P, Row, Screen, Tag } from '../../ui/kit';
import { DrawPad, Sketch } from '../../ui/Sketch';
import { TvCard } from '../../ui/TvCard';
import { GameFrame, LobbyFrame, PointsStandings, ResultsHeader, SaveGames, kick, useHostRun } from '../shared/HostParts';
import { ScribbleSettingsForm } from './Settings';

type Props = {
  st: ScribbleState;
  act: (action: string, extra?: object) => Promise<unknown>;
  now: () => number;
  offline: boolean;
  myName: string | null;
  myToken: string | null;
  onAnswered: () => void;
  onPlayToo: () => void;
  onLeave: () => void;
  onClose: () => void;
  onNewRoom: () => void;
};

const FALLBACK: Pick<ScribbleConfig, 'colors' | 'widths' | 'maxPoints' | 'rounds' | 'secs'> = {
  colors: ['#1B1F3B', '#FF6B4A', '#FFC93C', '#3DD68C', '#5BB8F5', '#FF7FB6', '#8C7CFF', '#8B5A2B', '#FFFFFF'],
  widths: [4, 10, 20, 40],
  maxPoints: 1500,
  rounds: [1, 2, 3],
  secs: [60, 75, 90],
};
const fmt = (n: number) => n.toLocaleString('en-US');


export function ScribbleHost(p: Props) {
  const { st } = p;
  const { busy, err, setErr, run, confirmEnd } = useHostRun(p.act);
  const { cfg: got } = useGameConfig<ScribbleConfig>('scribble');
  const cfg = got || FALLBACK;
  const order = new Map(st.players.map((x, i) => [x.name, i]));
  const buddyOf = (name: string) => order.get(name) ?? 0;
  const me = p.myToken && st.me ? st.me : null;

  const send = async (type: string, payload: object, quiet = false) => {
    if (!p.myToken) return { ok: false, code: '' };
    if (!quiet) setErr('');
    try {
      await api.play(st.code, p.myToken, type, payload);
      if (!quiet) Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
      p.onAnswered();
      return { ok: true, code: '' };
    } catch (e) {
      const x = e as Error & { code?: string };
      if (!quiet && x.code !== 'close') setErr(x.message);
      return { ok: false, code: x.code || '', msg: x.message };
    }
  };

  // ------------------------------------------------------------------ lobby
  if (st.phase === 'lobby') {
    return (
      <LobbyFrame
        title="Scribble Squad"
        code={st.code}
        path={st.game}
        players={st.players}
        myName={p.myName}
        playLine="Play too (draw and guess on this phone)"
        onPlayToo={p.onPlayToo}
        onKick={(pl) => kick(pl, run)}
        onLeave={p.onLeave}
        onClose={p.onClose}
        onStart={() => run('start')}
        starting={busy === 'start'}
        offline={p.offline}
        err={err}
        tv={<TvCard code={st.code} />}
        settings={
          <>
            <ScribbleSettingsForm value={{ rounds: st.rounds, secs: st.secs }} cfg={cfg} onChange={(x) => run('settings', x)} />
            <P muted small>Everyone draws their own secret prompt each round, so more players means more sketches to guess.</P>
          </>
        }
      />
    );
  }

  // ------------------------------------------------------------------ results
  if (st.phase === 'end') {
    const best = st.gallery || [];
    return (
      <View style={{ flex: 1, backgroundColor: C.bg }}>
        <ResultsHeader winners={st.winners || []} buddyOf={buddyOf} sub={st.round ? `Most points after ${st.round} round${st.round === 1 ? '' : 's'}` : ''} stopped={st.stopped} />
        <Screen
          footer={
            <>
              <Button kind="go" title="Play again, same players" busy={busy === 'again'} onPress={() => run('again')} />
              <Button small title="New room" onPress={p.onNewRoom} />
            </>
          }
        >
          {p.offline ? <Banner>Reconnecting…</Banner> : null}
          {err ? <Banner tone="bad">{err}</Banner> : null}
          {best.length ? (
            <View style={{ gap: 10 }}>
              <H2>Best in show</H2>
              {best.map((g, i) => (
                <Card key={i} style={{ gap: 6 }}>
                  <Sketch strokes={g.sketch} label={`${g.artist}'s drawing of ${g.word}`} />
                  <Text style={{ fontFamily: F.heavy, fontSize: 16, color: C.ink }}>
                    “{g.word}” by {g.artist} · {g.v} vote{g.v === 1 ? '' : 's'}
                  </Text>
                </Card>
              ))}
            </View>
          ) : null}
          {st.awards?.length ? <Row>{st.awards.map((a) => <Tag key={a.k} bg={C.mintTint} text={`👁 Sharpest eye: ${a.name} (${a.n} right)`} />)}</Row> : null}
          <SaveGames st={st} />
          <H2>Standings</H2>
          <PointsStandings players={st.players} winners={st.winners} myName={p.myName} buddyOf={buddyOf} />
          <TvCard code={st.code} compact />
        </Screen>
      </View>
    );
  }

  // ------------------------------------------------------------------ draw, show, srev, board, best
  const cur = st.cur;
  const status =
    st.phase === 'draw'
      ? `${st.locked} of ${st.drawers ?? st.alive} done drawing`
      : st.phase === 'show'
        ? `${cur ? cur.got.length : 0} got it · sketch ${cur ? cur.k + 1 : 1} of ${cur?.n ?? 1}`
        : st.phase === 'srev'
          ? 'The answer is on the TV'
          : st.phase === 'best'
            ? `${st.locked} of ${st.alive} voted`
            : 'Scores on the TV';
  const lastSketch = !!cur && cur.k + 1 >= cur.n;
  const nextTitle =
    st.phase === 'draw' ? 'Skip ahead →' : st.phase === 'show' ? 'Show the answer →' : st.phase === 'best' ? 'See the winner →' : st.phase === 'srev' && !lastSketch ? 'Next sketch →' : 'Next →';
  const timed = st.phase === 'draw' || st.phase === 'show' || st.phase === 'best';
  return (
    <GameFrame
      round={st.round}
      rounds={st.rounds}
      clock={timed ? st : null}
      now={p.now}
      offline={p.offline}
      err={err}
      status={status}
      next={{ title: nextTitle, busy: busy === 'next', onPress: () => run('next') }}
      onEnd={confirmEnd}
    >
      {st.phase === 'draw' ? <DrawStep st={st} me={me} cfg={cfg} now={p.now} send={send} /> : null}
      {(st.phase === 'show' || st.phase === 'srev') && cur ? <ShowStep st={st} me={me} send={send} /> : null}
      {st.phase === 'best' ? <BestStep st={st} me={me} send={send} /> : null}
      {st.phase === 'board' ? <P muted>Scores after round {st.round}. The next round starts in a moment.</P> : null}
      <View style={{ gap: 10 }}>
        <H2 style={{ fontSize: 22 }}>Scores</H2>
        <PointsStandings players={st.players} myName={p.myName} buddyOf={buddyOf} showGain={st.phase === 'srev' || st.phase === 'board'} max={8} />
      </View>
      <TvCard code={st.code} compact />
    </GameFrame>
  );
}

type Send = (type: string, payload: object, quiet?: boolean) => Promise<{ ok: boolean; code: string; msg?: string }>;
type Live = { c: string; w: number; p: number[] };
const toWire = (list: Live[]) => list.map((s) => ({ c: s.c, w: s.w, p: s.p.join(',') }));

/** Drawing time. Playing: the secret prompt and a pad, saved as you go and sent when the clock runs out. */
function DrawStep({ st, me, cfg, now, send }: { st: ScribbleState; me: ScribbleState['me'] | null; cfg: Pick<ScribbleConfig, 'colors' | 'widths' | 'maxPoints'>; now: () => number; send: Send }) {
  const [strokes, setStrokes] = useState<Live[]>([]);
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const pad = useRef({ dirty: false, sentAt: 0, auto: false, strokes: [] as Live[] });
  pad.current.strokes = strokes;
  useEffect(() => {
    setStrokes([]);
    setSent(false);
    pad.current = { dirty: false, sentAt: Date.now(), auto: false, strokes: [] };
  }, [st.round]);

  // Save the sketch every few seconds, and once more just before time is up, like the web pad.
  const playing = !!me?.word && !sent && !me.done;
  useEffect(() => {
    if (!playing) return;
    const t = setInterval(() => {
      const x = pad.current;
      if (!x.strokes.length) return;
      const left = st.t1 - now();
      if (left < 900 && !x.auto) {
        x.auto = true;
        send('draw', { round: st.round, strokes: toWire(x.strokes), done: false }, true);
      } else if (x.dirty && Date.now() - x.sentAt > 8000) {
        x.dirty = false;
        x.sentAt = Date.now();
        send('draw', { round: st.round, strokes: toWire(x.strokes), done: false }, true);
      }
    }, 400);
    return () => clearInterval(t);
  }, [playing, st.round, st.t1]);

  if (!me) return <P muted>Everyone is drawing their secret prompt. Sketches go up on the TV when time is up.</P>;
  if (!me.word) return <Banner>You'll draw next round. Guess this round's sketches!</Banner>;
  if (sent || me.done) {
    return (
      <Card tint={C.mintTint} style={{ gap: 8 }}>
        <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>Sent! Your drawing of “{me.word}”</Text>
        {strokes.length ? <Sketch strokes={strokes} label={`Your drawing of ${me.word}`} /> : null}
        <P small muted>
          Waiting for the others to finish…
        </P>
      </Card>
    );
  }
  const done = async () => {
    if (!strokes.length) return;
    setSending(true);
    const r = await send('draw', { round: st.round, strokes: toWire(strokes), done: true });
    setSending(false);
    if (r.ok) setSent(true);
  };
  return (
    <View style={{ gap: 10 }}>
      <Card tint={C.goldTint} style={{ gap: 2 }}>
        <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.mist }}>Your secret prompt (don't let anyone see!)</Text>
        <Text style={{ fontFamily: F.display, fontSize: 28, lineHeight: 34, color: C.ink }}>{me.word}</Text>
      </Card>
      <DrawPad
        colors={cfg.colors}
        widths={cfg.widths}
        maxPoints={cfg.maxPoints}
        strokes={strokes}
        onChange={(s) => {
          pad.current.dirty = true;
          setStrokes(s);
        }}
      />
      <Button kind="go" title="I'm done" busy={sending} disabled={!strokes.length} onPress={done} />
    </View>
  );
}

/** A sketch on the TV: the masked word, who got it, the guesses. Playing: a guess box. */
function ShowStep({ st, me, send }: { st: ScribbleState; me: ScribbleState['me'] | null; send: Send }) {
  const cur = st.cur!;
  const [text, setText] = useState('');
  const [note, setNote] = useState('');
  const [sending, setSending] = useState(false);
  useEffect(() => {
    setText('');
    setNote('');
  }, [st.round, cur.k]);
  const guess = async () => {
    const t = text.trim();
    if (!t || sending) return;
    setSending(true);
    const r = await send('guess', { round: st.round, k: cur.k, text: t }, true);
    setSending(false);
    if (r.ok) {
      setText('');
      setNote('');
    } else if (r.code === 'close') {
      setNote(`“${t}” is so close! Keep trying.`);
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => {});
    } else {
      setNote(r.msg || '');
    }
  };
  const reveal = st.phase === 'srev';
  const feed = (st.feed || []).slice(-6).reverse();
  return (
    <View style={{ gap: 10 }}>
      <Row style={{ gap: 8 }}>
        <Tag text={`Sketch ${cur.k + 1} of ${cur.n}`} bg={C.white} />
        <Tag text={`By ${cur.artist}`} bg={C.goldTint} />
      </Row>
      <Sketch strokes={cur.sketch} label={`${cur.artist}'s sketch`} />
      <Card tint={reveal ? C.mintTint : C.white} style={{ alignItems: 'center', gap: 2, paddingVertical: 10 }}>
        {reveal || cur.word ? (
          <>
            <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.right }}>{reveal ? 'It was' : 'The answer'}</Text>
            <Text style={{ fontFamily: F.display, fontSize: 28, lineHeight: 34, color: C.ink }}>{cur.word}</Text>
          </>
        ) : (
          <Mask mask={cur.mask} />
        )}
        <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.mist }}>
          {cur.got.length ? `Got it: ${cur.got.map((g) => g.n).join(', ')}` : reveal ? 'Nobody got it' : 'Nobody has it yet'}
        </Text>
      </Card>
      {me && !reveal ? (
        me.artist ? (
          <Banner>That's your drawing! Watch them guess.</Banner>
        ) : me.got ? (
          <Banner tone="good">You got it! +{fmt(me.got)}</Banner>
        ) : (
          <Card style={{ gap: 8 }}>
            <Field label="Your guess" value={text} onChangeText={setText} maxLength={40} autoCorrect={false} returnKeyType="send" onSubmitEditing={guess} blurOnSubmit={false} placeholder="What is it?" />
            {note ? <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.accent }}>{note}</Text> : null}
            <Button kind="go" title="Guess" busy={sending} disabled={!text.trim()} onPress={guess} />
          </Card>
        )
      ) : null}
      {feed.length && !reveal ? (
        <View style={{ gap: 4 }}>
          {feed.map((f, i) => (
            <Text key={i} style={{ fontFamily: F.bold, fontSize: 14, color: 'ok' in f ? C.right : C.mist }}>
              {'ok' in f ? `✓ ${f.n} got it!` : `${f.n}: ${f.t}`}
            </Text>
          ))}
        </View>
      ) : null}
    </View>
  );
}

/** Best in show: the round's best sketches. Playing: vote for one that isn't yours. */
function BestStep({ st, me, send }: { st: ScribbleState; me: ScribbleState['me'] | null; send: Send }) {
  const [busy, setBusy] = useState(false);
  const own = me?.own || [];
  const vote = async (c: number) => {
    setBusy(true);
    await send('best', { choice: c });
    setBusy(false);
  };
  return (
    <View style={{ gap: 10 }}>
      <H2 style={{ fontSize: 22 }}>Best in show</H2>
      <P small muted>
        {me ? (me.bv ? 'Voted! Tap another to change it.' : 'Vote for your favorite. It wins +500.') : 'Everyone votes for their favorite. It wins +500.'}
      </P>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
        {(st.gallery || []).map((g, i) => {
          const c = i + 1;
          const mine = own.includes(c);
          const pick = me?.bv === c;
          return (
            <Pressable
              key={i}
              accessibilityRole="button"
              accessibilityLabel={`${g.artist}'s drawing of ${g.word}${mine ? ', yours' : pick ? ', your vote' : ''}`}
              accessibilityState={{ disabled: !me || mine || busy, selected: pick }}
              disabled={!me || mine || busy}
              onPress={() => vote(c)}
              style={({ pressed }) => ({ width: '48%', gap: 4, padding: 6, borderRadius: 20, borderWidth: 3, borderColor: pick ? C.accent : 'transparent', backgroundColor: pick ? C.goldTint : 'transparent', opacity: mine ? 0.6 : pressed ? 0.75 : 1 })}
            >
              <Sketch strokes={g.sketch} />
              <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.ink }} numberOfLines={1}>
                {g.word}
              </Text>
              <Text style={{ fontFamily: F.bold, fontSize: 13, color: C.mist }} numberOfLines={1}>
                {mine ? 'Yours' : pick ? 'Your vote' : `by ${g.artist}`}
              </Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

/** The word as blanks: one short line per letter (first letters filled in halfway through), words wrapping as units. */
function Mask({ mask }: { mask: string }) {
  const n = mask.replace(/[^_]/g, '').length;
  return (
    <View accessible accessibilityLabel={`${n} letters`} style={{ flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', columnGap: 18, rowGap: 8, paddingVertical: 4 }}>
      {mask.split(' ').map((w, i) => (
        <View key={i} style={{ flexDirection: 'row', gap: 8, alignItems: 'flex-end' }}>
          {w.split('').map((ch, j) =>
            ch === '_' ? (
              <View key={j} style={{ width: 16, height: 30, borderBottomWidth: 4, borderBottomColor: C.ink }} />
            ) : (
              <Text key={j} style={{ fontFamily: F.display, fontSize: 26, lineHeight: 32, color: C.ink, minWidth: 16, textAlign: 'center' }}>
                {ch}
              </Text>
            )
          )}
        </View>
      ))}
    </View>
  );
}
