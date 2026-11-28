// End-to-end smoke test of Phony Baloney against a running site.
//   node plugin/tests/baloney-smoke.mjs http://127.0.0.1:8899
// Plays a 5-round game with three fake players: write fakes, vote, LOL stickers, scores.

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

const g = await call('GET', '/games');
ok(g.j.games && g.j.games.some((x) => x.slug === 'baloney'), 'GET /games lists Phony Baloney');

const made = await call('POST', '/rooms', { game: 'baloney', rounds: 5, secs: 45, cats: ['word', 'acronym', 'law'] });
ok(made.status === 200 && /^[A-Z]{4}$/.test(made.j.code), `made room ${made.j.code}`);
const code = made.j.code;
const H = { 'X-Party-Host': made.j.host };
const names = ['Ana', 'Ben', 'Cy'];
const P = [];
for (const n of names) {
  const r = await call('POST', `/rooms/${code}/join`, { name: n });
  ok(r.status === 200 && r.j.token, `${n} joined`);
  P.push({ 'X-Party-Token': r.j.token });
}
let st = await state(code, H);
ok(st.phase === 'lobby' && st.players.length === 3 && st.rounds === 5 && st.secs === 45, 'lobby with settings');
ok(JSON.stringify(st.only) === JSON.stringify(['word', 'acronym', 'law']), 'category choice kept');
const noCats = await call('POST', `/rooms/${code}/host`, { action: 'settings', cats: [] }, H);
ok(noCats.status === 400, 'turning every category off is refused');

await call('POST', `/rooms/${code}/host`, { action: 'start' }, H);
let lastCat = '';
for (let round = 1; round <= 5; round++) {
  st = await waitPhase(code, 'write', H);
  ok(st.round === round && st.p && st.p.q, `round ${round}: ${st.p.catN} prompt "${(st.p.title || st.p.q).slice(0, 40)}"`);
  ok(['word', 'acronym', 'law'].includes(st.p.cat), 'prompt is from a picked category');
  ok(st.p.cat !== lastCat, 'category differs from last round');
  lastCat = st.p.cat;
  ok(!JSON.stringify(st).includes('"answer"'), 'the real answer is not sent while writing');
  // Too-true check: the host's view has no answer, so try each round's answer via a fake later.
  for (let i = 0; i < 3; i++) {
    const r = await call('POST', `/rooms/${code}/action`, { type: 'fake', round, text: `${names[i]}'s sneaky fake number ${round}` }, P[i]);
    ok(r.status === 200, `${names[i]} wrote a fake`);
  }
  st = await waitPhase(code, 'vote', P[0], 5000);
  ok(st.opts.length >= 4, `vote has ${st.opts.length} choices`);
  ok(st.me.own.length === 1, 'Ana sees which choice is hers');
  const mine = await call('POST', `/rooms/${code}/action`, { type: 'vote', round, choice: st.me.own[0] }, P[0]);
  ok(mine.status === 400 && mine.j.code === 'yours', 'cannot vote for your own fake');
  // Ana votes for Ben's fake, Ben and Cy vote for the first choice that isn't theirs.
  const txt = st.opts;
  const benIdx = txt.findIndex((t) => t.startsWith("Ben's")) + 1;
  await call('POST', `/rooms/${code}/action`, { type: 'vote', round, choice: benIdx }, P[0]);
  for (const i of [1, 2]) {
    const s2 = await state(code, P[i]);
    const pick = [1, 2, 3, 4, 5, 6].find((c) => c <= txt.length && !s2.me.own.includes(c));
    await call('POST', `/rooms/${code}/action`, { type: 'vote', round, choice: pick }, P[i]);
  }
  st = await waitPhase(code, 'rev', H, 5000);
  ok(st.res && st.res.answer && st.res.rev.some((o) => o.truth), `reveal shows the truth: "${st.res.answer.slice(0, 40)}"`);
  const benOpt = st.res.rev.find((o) => o.by.includes('Ben'));
  ok(benOpt && benOpt.votes.includes('Ana'), 'reveal shows who fell for Ben');
  // Too true now that we know the answer: can't test mid-round, so check the next prompt later.
  if (round === 1) {
    const sA = await state(code, P[0]);
    const lol = await call('POST', `/rooms/${code}/action`, { type: 'lol', round, choice: benOpt.i }, P[0]);
    ok(lol.status === 200, 'Ana gave Ben a LOL sticker');
    const again = await call('POST', `/rooms/${code}/action`, { type: 'lol', round, choice: benOpt.i }, P[0]);
    ok(again.status === 409, 'only one sticker a round');
    const ben = (await state(code, H)).players.find((p) => p.name === 'Ben');
    ok(ben.score >= 625, `Ben scored ${ben.score} (fooled Ana + LOL)`);
  }
  if (round < 5) {
    await call('POST', `/rooms/${code}/host`, { action: 'next' }, H);
    st = await waitPhase(code, 'board', H, 5000);
    ok(st.players.length === 3, 'scores board');
    await call('POST', `/rooms/${code}/host`, { action: 'next' }, H);
  } else {
    ok(st.mult === 2, 'last round counts double');
    await call('POST', `/rooms/${code}/host`, { action: 'next' }, H);
  }
}
st = await waitPhase(code, 'end', P[1], 6000);
ok(st.winners.length >= 1 && st.awards.length >= 1, `game over, winners ${st.winners.join(', ')}, ${st.awards.length} awards`);
ok(typeof st.me.fooled === 'number' && st.me.lols >= 1, 'Ben sees his LOL count on the end screen');
await call('POST', `/rooms/${code}/host`, { action: 'again' }, H);
st = await state(code, H);
ok(st.phase === 'lobby' && st.players.every((p) => p.score === 0), 'play again resets scores');

// Too true: a one-category room so we can read the answer from the reveal and try it next time.
const m2 = await call('POST', '/rooms', { game: 'baloney', rounds: 5, secs: 45, cats: ['acronym'] });
const H2 = { 'X-Party-Host': m2.j.host };
const solo = await call('POST', `/rooms/${m2.j.code}/join`, { name: 'Solo' });
const S = { 'X-Party-Token': solo.j.token };
await call('POST', `/rooms/${m2.j.code}/host`, { action: 'start' }, H2);
st = await waitPhase(m2.j.code, 'write', S);
const title = st.p.title;
const answers = { TASER: 'Thomas A. Swift Electric Rifle', SCUBA: 'Self-Contained Underwater Breathing Apparatus', LASER: 'Light Amplification by Stimulated Emission of Radiation', RADAR: 'Radio Detection And Ranging', JPEG: 'Joint Photographic Experts Group', GIF: 'Graphics Interchange Format', CAPTCHA: 'Completely Automated Public Turing test to tell Computers and Humans Apart' };
if (answers[title]) {
  const tt = await call('POST', `/rooms/${m2.j.code}/action`, { type: 'fake', round: 1, text: answers[title].toLowerCase() }, S);
  ok(tt.status === 400 && tt.j.code === 'tootrue', `typing the real answer for ${title} says "Too true!"`);
} else {
  console.log(`  skip too-true check (${title})`);
}
const sw = await call('POST', `/rooms/${m2.j.code}/action`, { type: 'fake', round: 1, text: 'Silly Cats Under Big Apples' }, S);
ok(sw.status === 200, 'solo player writes a fake');
st = await waitPhase(m2.j.code, 'vote', S, 5000);
ok(st.opts.length >= 4, `solo game fills in house fakes (${st.opts.length} choices)`);
await call('POST', `/rooms/${m2.j.code}/host`, { action: 'end' }, H2);
ok((await state(m2.j.code, H2)).phase === 'end', 'host can end the game early');

console.log(failures ? `\n${failures} FAILED` : '\nall ok');
process.exit(failures ? 1 : 0);
