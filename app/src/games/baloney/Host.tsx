// The host's controller for a Phony Baloney room: lobby, then each round (prompt, writing fakes,
// voting, the reveal, scores) as the TV shows it, then winners. The host can play too: they write
// a fake, vote and give LOL stickers right here.
import * as Haptics from 'expo-haptics';
import { useEffect, useState } from 'react';
import { Image, Pressable, Text, View } from 'react-native';
import { api, type BaloneyConfig, type BaloneyReveal, type BaloneyState } from '../../lib/api';
import { useGameConfig } from '../../lib/gameConfig';
import { Field } from '../../ui/Field';
import { Banner, Button, C, Card, F, H2, P, Row, Screen, Tag } from '../../ui/kit';
import { TvCard } from '../../ui/TvCard';
import { GameFrame, LobbyFrame, PointsStandings, ResultsHeader, SaveGames, kick, useHostRun, useTick } from '../shared/HostParts';
import { BaloneySettingsForm, type BaloneySettings } from './Settings';

type Props = {
  st: BaloneyState;
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

const L = 'ABCDEFGHIJ';
const COLS = ['#FF6B4A', '#FFC93C', '#3DD68C', '#5BB8F5', '#FF7FB6', '#8C7CFF'];
// The TV turns over one reveal card every 2.6 s after a short pause; the phone follows along.
const STEP0 = 1200;
const STEP_MS = 2600;
const fmt = (n: number) => n.toLocaleString('en-US');

export function BaloneyHost(p: Props) {
  const { st } = p;
  const { busy, err, setErr, run, confirmEnd } = useHostRun(p.act);
  const { cfg } = useGameConfig<BaloneyConfig>('baloney');
  const order = new Map(st.players.map((x, i) => [x.name, i]));
  const buddyOf = (name: string) => order.get(name) ?? 0;
  const me = p.myToken && st.me ? st.me : null;

  // The host playing too: one call for fakes, votes and stickers.
  const [sending, setSending] = useState('');
  const send = async (type: string, payload: object) => {
    if (!p.myToken) return false;
    setSending(type);
    setErr('');
    try {
      await api.play(st.code, p.myToken, type, { round: st.round, ...payload });
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
      p.onAnswered();
      return true;
    } catch (e) {
      setErr((e as Error).message);
      return false;
    } finally {
      setSending('');
    }
  };

  // ------------------------------------------------------------------ lobby
  if (st.phase === 'lobby') {
    const value: BaloneySettings = { rounds: st.rounds, secs: st.secs, cats: st.only };
    return (
      <LobbyFrame
        title="Phony Baloney"
        code={st.code}
        path={st.game}
        players={st.players}
        myName={p.myName}
        playLine="Play too (write fakes on this phone)"
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
            <BaloneySettingsForm
              value={value}
              cfg={cfg || { cats: [], rounds: [5, 7, 10], secs: [45, 60, 90] }}
              onChange={(x) => {
                // The server wants the full list for "all".
                const out: Record<string, unknown> = { ...x };
                if (x.cats && !x.cats.length && cfg) out.cats = cfg.cats.map((c) => c.id);
                run('settings', out);
              }}
            />
            {st.players.length > 0 && st.players.length < 3 ? <P muted small>Best with 3 or more. The game adds a few house fakes so there's always something to vote on.</P> : null}
          </>
        }
      />
    );
  }

  // ------------------------------------------------------------------ results
  if (st.phase === 'end') {
    const sub = st.round ? `Most points after ${st.round} round${st.round === 1 ? '' : 's'}` : '';
    return (
      <View style={{ flex: 1, backgroundColor: C.bg }}>
        <ResultsHeader winners={st.winners || []} buddyOf={buddyOf} sub={sub} stopped={st.stopped} />
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
          {st.awards?.length ? (
            <Row style={{ gap: 8 }}>
              {st.awards.map((a) => (
                <Tag key={a.k} bg={a.k === 'lol' ? C.pinkTint : a.k === 'fool' ? C.goldTint : C.mintTint} text={`${a.k === 'lol' ? '😂 Funniest' : a.k === 'fool' ? '🦊 Sneakiest' : '🔎 Truth finder'}: ${a.name} (${a.n})`} />
              ))}
            </Row>
          ) : null}
          <SaveGames st={st} />
          <H2>Standings</H2>
          <PointsStandings players={st.players} winners={st.winners} myName={p.myName} buddyOf={buddyOf} />
          <TvCard code={st.code} compact />
        </Screen>
      </View>
    );
  }

  // ------------------------------------------------------------------ cat, write, vote, rev, board
  const timed = st.phase === 'write' || st.phase === 'vote';
  const status =
    st.phase === 'write'
      ? `${st.locked} of ${st.alive} wrote a fake`
      : st.phase === 'vote'
        ? `${st.locked} of ${st.alive} voted`
        : st.phase === 'cat'
          ? 'New prompt on the TV'
          : st.phase === 'rev'
            ? 'The reveal is on the TV'
            : 'Scores on the TV';
  const last = st.round >= st.rounds && (st.phase === 'rev' || st.phase === 'board');
  const nextTitle = timed ? 'Skip ahead →' : last ? 'See the winner →' : st.phase === 'cat' ? 'Go →' : 'Next →';
  return (
    <GameFrame
      round={st.round}
      rounds={st.rounds}
      clock={timed ? st : null}
      now={p.now}
      right={st.mult > 1 ? <Tag text={`×${st.mult} points`} bg={C.gold} style={{ alignSelf: 'center' }} /> : null}
      offline={p.offline}
      err={err}
      status={status}
      next={{ title: nextTitle, busy: busy === 'next', onPress: () => run('next') }}
      onEnd={confirmEnd}
    >
      {st.p ? <PromptCard p={st.p} mult={st.mult} /> : null}
      {st.phase === 'cat' ? <P muted>Get ready… everyone writes a fake answer that sounds real.</P> : null}
      {st.phase === 'write' ? <WriteBox st={st} me={me} busy={sending === 'fake'} onSend={(text) => send('fake', { text })} /> : null}
      {st.phase === 'vote' ? <VoteList st={st} me={me} busy={!!sending} onVote={(c) => send('vote', { choice: c })} /> : null}
      {st.phase === 'rev' || st.phase === 'board' ? <Reveal st={st} now={p.now} me={me} busy={!!sending} onLol={(i) => send('lol', { choice: i })} /> : null}
      <View style={{ gap: 10 }}>
        <H2 style={{ fontSize: 22 }}>Scores</H2>
        <PointsStandings players={st.players} myName={p.myName} buddyOf={buddyOf} showGain={st.phase === 'rev' || st.phase === 'board'} max={8} />
      </View>
      <TvCard code={st.code} compact />
    </GameFrame>
  );
}

function PromptCard({ p, mult }: { p: NonNullable<BaloneyState['p']>; mult: number }) {
  const [ratio, setRatio] = useState(4 / 3);
  useEffect(() => {
    if (p.img) Image.getSize(p.img, (w, h) => h && setRatio(w / h), () => {});
  }, [p.img]);
  return (
    <Card lifted tilt={-0.8} style={{ borderRadius: 26, padding: 18, gap: 8 }}>
      <Row style={{ gap: 8 }}>
        <Tag text={p.catN} bg={C.mint} />
        {mult > 1 ? <Tag text={`Final round · ×${mult}`} bg={C.gold} /> : null}
      </Row>
      {p.img ? (
        <Image source={{ uri: p.img }} accessibilityLabel={p.title || 'Picture prompt'} style={{ width: '100%', aspectRatio: ratio, maxHeight: 260, borderRadius: 16, borderWidth: 3, borderColor: C.ink, backgroundColor: C.sky }} resizeMode="contain" />
      ) : null}
      {p.title ? <Text style={{ fontFamily: F.display, fontSize: 28, lineHeight: 34, color: C.ink }}>{p.title}</Text> : null}
      {p.sub ? <Text style={{ fontFamily: F.bold, fontSize: 15, color: C.mist }}>{p.sub}</Text> : null}
      <Text style={{ fontFamily: F.heavy, fontSize: 19, lineHeight: 25, color: C.ink }}>{p.q}</Text>
    </Card>
  );
}

function WriteBox({ st, me, busy, onSend }: { st: BaloneyState; me: BaloneyState['me'] | null; busy: boolean; onSend: (text: string) => Promise<boolean> }) {
  const [text, setText] = useState('');
  const [editing, setEditing] = useState(false);
  useEffect(() => {
    setText('');
    setEditing(false);
  }, [st.round]);
  if (!me) return <P muted>Everyone is writing a fake answer on their phone.</P>;
  if (me.fake && !editing) {
    return (
      <Card tint={C.mintTint} style={{ gap: 6 }}>
        <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>Your fake is in</Text>
        <Text style={{ fontFamily: F.display, fontSize: 22, lineHeight: 28, color: C.ink }}>“{me.fake}”</Text>
        <Button small title="Change it" onPress={() => (setText(me.fake), setEditing(true))} />
      </Card>
    );
  }
  const submit = async () => {
    const t = text.trim();
    if (!t) return;
    if (await onSend(t)) setEditing(false);
  };
  return (
    <Card style={{ gap: 10 }}>
      <Field label="Your fake answer" hint="Make it sound real" value={text} onChangeText={setText} maxLength={60} autoCapitalize="sentences" returnKeyType="send" onSubmitEditing={submit} placeholder="Something believable…" />
      <Button kind="go" title="Lock it in" busy={busy} disabled={!text.trim()} onPress={submit} />
    </Card>
  );
}

function VoteList({ st, me, busy, onVote }: { st: BaloneyState; me: BaloneyState['me'] | null; busy: boolean; onVote: (c: number) => void }) {
  const own = me?.own || [];
  return (
    <View style={{ gap: 10 }}>
      <Text style={{ fontFamily: F.heavy, fontSize: 14, color: me ? C.accent : C.mist }}>
        {me ? (me.vote ? 'Tap another one to change your vote.' : 'Which one is real? Tap it.') : 'Everyone is voting for the real answer.'}
      </Text>
      {(st.opts || []).map((t, i) => {
        const c = i + 1;
        const mine = own.includes(c);
        const pick = me?.vote === c;
        return (
          <Pressable
            key={i}
            accessibilityRole="button"
            accessibilityState={{ disabled: !me || mine || busy, selected: pick }}
            accessibilityLabel={`${L[i]}: ${t}${mine ? ', yours' : pick ? ', your vote' : ''}`}
            disabled={!me || mine || busy}
            onPress={() => onVote(c)}
            style={({ pressed }) => ({
              flexDirection: 'row',
              alignItems: 'center',
              gap: 12,
              minHeight: 60,
              backgroundColor: pick ? C.goldTint : C.white,
              borderWidth: 3,
              borderColor: C.ink,
              borderRadius: 20,
              paddingVertical: 8,
              paddingLeft: 8,
              paddingRight: 12,
              opacity: mine ? 0.55 : pressed ? 0.75 : 1,
            })}
          >
            <View style={{ width: 40, height: 40, borderRadius: 14, borderWidth: 3, borderColor: C.ink, backgroundColor: COLS[i % COLS.length], alignItems: 'center', justifyContent: 'center' }}>
              <Text style={{ fontFamily: F.display, fontSize: 19, lineHeight: 25, color: C.ink }}>{L[i]}</Text>
            </View>
            <Text style={{ fontFamily: F.heavy, fontSize: 17, color: C.ink, flex: 1 }}>{t}</Text>
            {mine ? <Text style={{ fontFamily: F.heavy, fontSize: 13, color: C.mist }}>Yours</Text> : pick ? <Text style={{ fontFamily: F.heavy, fontSize: 13, color: C.accent }}>Your vote</Text> : null}
          </Pressable>
        );
      })}
    </View>
  );
}

/** Follows the TV: "the truth is coming…" while the cards turn over, then every choice with who wrote it and who fell for it. */
function Reveal({ st, now, me, busy, onLol }: { st: BaloneyState; now: () => number; me: BaloneyState['me'] | null; busy: boolean; onLol: (i: number) => void }) {
  useTick(400);
  const res = st.res;
  if (!res) return null;
  const shown = res.rev.filter((o) => o.truth || o.votes.length);
  const step = Math.min(shown.length - 1, Math.floor((now() - st.t0 - STEP0) / STEP_MS));
  const done = st.phase === 'board' || step >= shown.length - 1;
  const truth = res.rev.find((o) => o.truth);
  return (
    <View style={{ gap: 12 }}>
      {!done ? (
        <Card tint={C.goldTint} style={{ alignItems: 'center' }}>
          <H2 style={{ fontSize: 22 }}>Eyes on the TV!</H2>
          <P muted>The truth is coming…</P>
        </Card>
      ) : (
        <>
          {me ? <MyRound res={res} me={me} /> : null}
          <Card tint={C.mintTint} style={{ gap: 4 }}>
            <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.right }}>The truth · {truth?.votes.length || 0} found it</Text>
            <Text style={{ fontFamily: F.display, fontSize: 24, lineHeight: 30, color: C.ink }}>{res.answer}</Text>
          </Card>
          {res.rev
            .filter((o) => !o.truth)
            .slice()
            .reverse()
            .map((o) => (
              <FakeRow key={o.i} o={o} />
            ))}
        </>
      )}
      {me ? <LolBox res={res} me={me} busy={busy} onLol={onLol} /> : null}
    </View>
  );
}

function FakeRow({ o }: { o: BaloneyReveal }) {
  return (
    <View style={{ backgroundColor: C.white, borderWidth: 3, borderColor: C.ink, borderRadius: 18, paddingVertical: 8, paddingHorizontal: 12, gap: 2, opacity: o.votes.length ? 1 : 0.6 }}>
      <Row style={{ justifyContent: 'space-between', flexWrap: 'nowrap' }}>
        <Text style={{ fontFamily: F.heavy, fontSize: 16, color: C.ink, flex: 1 }}>“{o.t}”</Text>
        {o.pts ? <Text style={{ fontFamily: F.display, fontSize: 17, color: C.right }}>+{fmt(o.pts)}</Text> : null}
      </Row>
      <Text style={{ fontFamily: F.bold, fontSize: 13, color: C.mist }}>
        {o.house ? 'House fake' : `By ${o.by.join(' & ')}`}
        {o.votes.length ? ` · fooled ${o.votes.join(', ')}` : ' · fooled nobody'}
      </Text>
    </View>
  );
}

function MyRound({ res, me }: { res: NonNullable<BaloneyState['res']>; me: NonNullable<BaloneyState['me']> }) {
  const truth = res.rev.find((o) => o.truth);
  const found = !!truth?.votes.includes(me.name);
  const fell = res.rev.find((o) => !o.truth && o.votes.includes(me.name));
  const mine = res.rev.find((o) => o.by.includes(me.name));
  return (
    <View style={{ gap: 8 }}>
      <Banner tone={found ? 'good' : 'bad'}>
        {found ? `You found the truth! +${fmt(1000 * (res.mult || 1))}` : fell ? `You fell for “${fell.t}”` : 'No vote this round.'}
      </Banner>
      {mine ? <Banner tone={mine.votes.length ? 'good' : 'info'}>{mine.votes.length ? `Your fake fooled ${mine.votes.length}! +${fmt(mine.pts)}` : "Your fake didn't fool anyone this time."}</Banner> : null}
      <P small muted>
        +{fmt(me.gain)} this round · {fmt(me.score)} total
      </P>
    </View>
  );
}

function LolBox({ res, me, busy, onLol }: { res: NonNullable<BaloneyState['res']>; me: NonNullable<BaloneyState['me']>; busy: boolean; onLol: (i: number) => void }) {
  const list = res.rev.filter((o) => !o.truth && !o.house && o.by.length && !o.by.includes(me.name));
  if (!list.length) return null;
  if (me.lol >= 0) {
    const g = res.rev.find((o) => o.i === me.lol);
    return <Banner>😂 You gave a LOL sticker{g ? ` to “${g.t}”` : ''}.</Banner>;
  }
  return (
    <Card tint={C.pinkTint} style={{ gap: 8 }}>
      <H2 style={{ fontSize: 20 }}>Funniest fake?</H2>
      <P small muted>
        Give it a LOL sticker. Its writer gets +125.
      </P>
      {list.map((o) => (
        <Pressable
          key={o.i}
          accessibilityRole="button"
          disabled={busy}
          onPress={() => onLol(o.i)}
          style={({ pressed }) => ({ flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: C.white, borderWidth: 3, borderColor: C.ink, borderRadius: 16, paddingVertical: 8, paddingHorizontal: 12, opacity: pressed ? 0.7 : 1 })}
        >
          <Text style={{ fontSize: 20 }}>😂</Text>
          <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink, flex: 1 }}>“{o.t}”</Text>
        </Pressable>
      ))}
    </Card>
  );
}
