// End-to-end smoke test of Scribble Squad against a running site.
//   node plugin/tests/scribble-smoke.mjs http://127.0.0.1:8899
// Plays a 1-round game with three fake players: secret prompts, sketches, guesses, Best in show.

const base = (process.argv[2] || 'http://127.0.0.1:8899').replace(/\/$/, '') + '/wp-json/groupcade/v1';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failures = 0;
const ok = (cond, msg) => { console.log((cond ? '  ok   ' : '  FAIL ') + msg); if (!cond) failures++; };

async function call(method, path, body, headers = {}) {
  const h = { 'X-Groupcade': '1', ...headers };
  if (body) h['Content-Type'] = 'application/json';
  const r = await fetch(base + path, { method, headers: h, body: body ? JSON.stringify(body) : undefined });
  const j = await r.json().catch(() => ({}));
  return { status: r.status, j };
}
const state = (code, hdr = {}) => call('GET', `/rooms/${code}`, null, hdr).then((r) => r.j);
async function waitPhase(code, phase, hdr, ms = 12000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    const st = await state(code, hdr);
    if (st.phase === phase) return st;
    await sleep(250);
  }
  throw new Error(`timed out waiting for ${phase}`);
}
const square = [{ c: '#1B1F3B', w: 10, p: '100,100,900,100,900,700,100,700,100,100' }, { c: '#FF6B4A', w: 40, p: '500,375' }];

const made = await call('POST', '/rooms', { game: 'scribble', rounds: 1, secs: 60 });
ok(made.status === 200 && /^[A-Z]{4}$/.test(made.j.code), `made room ${made.j.code}`);
const code = made.j.code;
const H = { 'X-Party-Host': made.j.host };
const names = ['Ana', 'Ben', 'Cy'];
const P = [];
for (const n of names) {
  const r = await call('POST', `/rooms/${code}/join`, { name: n });
  P.push({ 'X-Party-Token': r.j.token });
}
let st = await state(code, H);
ok(st.phase === 'lobby' && st.rounds === 1 && st.secs === 60, 'lobby with settings');
await call('POST', `/rooms/${code}/host`, { action: 'start' }, H);
st = await waitPhase(code, 'draw', H);
ok(!JSON.stringify(st).includes('"word"'), 'the TV and host do not see anyone\'s prompt');
const words = [];
for (let i = 0; i < 3; i++) {
  const s2 = await state(code, P[i]);
  words.push(s2.me.word);
  ok(s2.me.word && !s2.me.done, `${names[i]} got a secret prompt: ${s2.me.word}`);
}
ok(new Set(words).size === 3, 'every player has a different prompt');
const empty = await call('POST', `/rooms/${code}/action`, { type: 'draw', round: 1, strokes: [] }, P[0]);
ok(empty.status === 400, 'an empty sketch is refused');
const big = await call('POST', `/rooms/${code}/action`, { type: 'draw', round: 1, strokes: [{ c: '#123456', w: 999, p: Array.from({ length: 4000 }, (_, i) => i % 999).join(',') }] }, P[0]);
ok(big.status === 200, 'an oversized sketch is trimmed, not refused');
for (let i = 0; i < 3; i++) {
  const r = await call('POST', `/rooms/${code}/action`, { type: 'draw', round: 1, strokes: square, done: true }, P[i]);
  ok(r.status === 200, `${names[i]} sent a sketch`);
}
st = await waitPhase(code, 'show', H, 6000);
ok(st.cur && st.cur.sketch.length === 2 && st.cur.n === 3, 'everyone done: the first sketch is on the TV');
ok(!st.cur.word && /^[_ \-']+$/.test(st.cur.mask), `TV sees only the mask "${st.cur.mask}"`);
const artist = names.indexOf(st.cur.artist);
const word = words[artist];
const guessers = [0, 1, 2].filter((i) => i !== artist);
const own = await call('POST', `/rooms/${code}/action`, { type: 'guess', round: 1, k: 0, text: word }, P[artist]);
ok(own.status === 409, 'the artist cannot guess');
const wrong = await call('POST', `/rooms/${code}/action`, { type: 'guess', round: 1, k: 0, text: 'banana phone' }, P[guessers[0]]);
ok(wrong.status === 200, 'a wrong guess is accepted');
st = await state(code, H);
ok(st.feed.some((f) => f.t === 'banana phone'), 'wrong guesses show in the feed');
const bare = word.replace(/^(a|an) /, '');
const close = await call('POST', `/rooms/${code}/action`, { type: 'guess', round: 1, k: 0, text: bare.slice(0, -2) + 'zz' }, P[guessers[0]]);
ok(close.status === 400 && close.j.code === 'close', `a near miss gets "So close!" (${bare.slice(0, -2)}zz)`);
const right = await call('POST', `/rooms/${code}/action`, { type: 'guess', round: 1, k: 0, text: bare.toUpperCase() }, P[guessers[0]]);
ok(right.status === 200, `${names[guessers[0]]} guessed it (${bare.toUpperCase()})`);
const again = await call('POST', `/rooms/${code}/action`, { type: 'guess', round: 1, k: 0, text: bare }, P[guessers[0]]);
ok(again.status === 409, 'no second guess after getting it');
st = await state(code, P[guessers[0]]);
ok(st.cur.word === word && st.me.got > 300, `the guesser now sees the word and scored ${st.me.got}`);
await call('POST', `/rooms/${code}/action`, { type: 'guess', round: 1, k: 0, text: word }, P[guessers[1]]);
st = await waitPhase(code, 'srev', H, 4000);
ok(st.cur.word === word && st.cur.got.length === 2, 'everyone got it: straight to the answer');
const art = st.players.find((p) => p.name === names[artist]);
ok(art.score === 400, `the artist scored 2 × 200 (${art.score})`);
// Skip through the other two sketches.
for (let k = 1; k < 3; k++) {
  await call('POST', `/rooms/${code}/host`, { action: 'next' }, H);
  st = await waitPhase(code, 'show', H, 4000);
  ok(st.cur.k === k, `sketch ${k + 1} of 3`);
  await call('POST', `/rooms/${code}/host`, { action: 'next' }, H);
  await waitPhase(code, 'srev', H, 4000);
}
await call('POST', `/rooms/${code}/host`, { action: 'next' }, H);
st = await waitPhase(code, 'best', P[0], 4000);
ok(st.gallery.length === 3 && st.gallery[0].sketch.length, 'Best in show gallery has 3 sketches');
ok(st.me.own.length === 1, 'Ana sees which drawing is hers');
const ownVote = await call('POST', `/rooms/${code}/action`, { type: 'best', choice: st.me.own[0] }, P[0]);
ok(ownVote.status === 400, 'no voting for your own drawing');
const target = st.gallery.findIndex((g) => g.artist === 'Ben') + 1;
await call('POST', `/rooms/${code}/action`, { type: 'best', choice: target }, P[0]);
await call('POST', `/rooms/${code}/action`, { type: 'best', choice: target === 1 ? 2 : 1 }, P[1]);
const cyPick = st.gallery.findIndex((g) => g.artist === 'Ben') + 1;
await call('POST', `/rooms/${code}/action`, { type: 'best', choice: cyPick }, P[2]);
st = await waitPhase(code, 'end', H, 6000);
ok(st.gallery.length >= 1 && st.gallery[0].artist === 'Ben' && st.gallery[0].v === 2, 'Ben wins Best in show with 2 votes');
ok(st.winners.length >= 1, `winner: ${st.winners.join(', ')}`);
await call('POST', `/rooms/${code}/host`, { action: 'again' }, H);
ok((await state(code, H)).phase === 'lobby', 'play again goes back to the lobby');

console.log(failures ? `\n${failures} FAILED` : '\nall ok');
process.exit(failures ? 1 : 0);
