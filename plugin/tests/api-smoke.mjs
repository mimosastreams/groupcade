// End-to-end smoke test of the Groupcade REST API against a running site.
//   node plugin/tests/api-smoke.mjs http://127.0.0.1:8899
// Plays a short Survival game and a short Points game with three fake players.
// Rooms it makes expire on their own after 2 hours.

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
async function waitPhase(code, phase, hdr, ms = 9000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    const st = await state(code, hdr);
    if (st.phase === phase) return st;
    await sleep(300);
  }
  throw new Error(`timed out waiting for ${phase}`);
}

async function game(mode) {
  console.log(`\n== ${mode} ==`);
  const g = await call('GET', '/games');
  ok(g.j.games && g.j.games[0].slug === 'trivia', 'GET /games lists Trivia');

  const bad = await call('POST', '/rooms', { game: 'trivia' }, { 'X-Groupcade': '0' });
  ok(bad.status === 401 || bad.status === 403, 'POST without the X-Groupcade header is refused');

  const made = await call('POST', '/rooms', { game: 'trivia', mode, rounds: 10, secs: 15, cats: ['Movies', 'Music'] });
  ok(made.status === 200 && /^[A-Z]{4}$/.test(made.j.code) && made.j.host, `made room ${made.j.code}`);
  const code = made.j.code;
  const H = { 'X-Party-Host': made.j.host };

  const names = ['Ana', 'Ben', 'Cy'];
  const toks = [];
  for (const n of names) {
    const r = await call('POST', `/rooms/${code}/join`, { name: n });
    ok(r.status === 200 && r.j.token, `${n} joined`);
    toks.push({ 'X-Party-Token': r.j.token });
  }
  const dup = await call('POST', `/rooms/${code}/join`, { name: 'ana' });
  ok(dup.j.name === 'ana 2', 'duplicate name gets a number');
  const lobby = await state(code, H);
  ok(lobby.game === 'trivia' && lobby.phase === 'lobby' && lobby.players.length === 4, 'host sees 4 players in the lobby');
  ok(JSON.stringify(lobby.only) === JSON.stringify(['Movies', 'Music']), 'category choice kept');
  const kick = lobby.players.find((p) => p.name === 'ana 2');
  await call('POST', `/rooms/${code}/host`, { action: 'kick', player: kick.id }, H);
  ok((await state(code, H)).players.length === 3, 'kick removes a player');

  const notHost = await call('POST', `/rooms/${code}/host`, { action: 'start' }, toks[0]);
  ok(notHost.status === 403, 'a player cannot start the game');
  const st0 = await call('POST', `/rooms/${code}/host`, { action: 'start' }, H);
  ok(st0.j.phase === 'cat', 'start goes to the category card');

  for (let round = 1; round <= 2; round++) {
    const q = await waitPhase(code, 'q', toks[0]);
    ok(q.round === round && q.choices.length === 4 && ['Movies', 'Music'].includes(q.cat), `round ${round}: question in ${q.cat}`);
    ok(!('right' in q) && !q.last, 'the right answer is hidden during the question');
    if (mode === 'points') {
      const wait = q.t0 - q.now;
      ok(wait > 3000, 'points mode has a read period');
    }
    // Ana answers 1, Ben answers 2, Cy answers 3. Exactly one of them can be right.
    for (let i = 0; i < 3; i++) {
      if (mode === 'survive' && round === 2 && !(await state(code, toks[i])).me.alive) continue;
      const a = await call('POST', `/rooms/${code}/action`, { type: 'answer', round, choice: i + 1 }, toks[i]);
      ok(a.status === 200, `${names[i]} locked in`);
    }
    const again = await call('POST', `/rooms/${code}/action`, { type: 'answer', round, choice: 4 }, toks[0]);
    ok(again.status === 409, 'a second answer is refused');
    const rev = await waitPhase(code, 'rev', toks[0], 4000);
    ok(rev.last && rev.last.round === round, `reveal: right answer is ${'ABCD'[rev.last.right]}`);
    if (mode === 'survive') {
      const right = rev.last.right; // 0-3
      if (right <= 2 && round === 1) {
        ok(rev.last.outN === 2 && rev.alive === 1, 'two wrong answers are out, one is standing');
      } else if (round === 1) {
        ok(rev.last.revived && rev.alive === 3, 'everyone missed, so nobody is out');
      }
    } else {
      const sum = rev.players.reduce((n, p) => n + p.score, 0);
      ok(rev.last.right > 2 ? sum === 0 : sum >= 500, 'points awarded for the right answer only');
    }
    if (rev.phase === 'rev' && mode === 'survive' && rev.alive <= 1) break; // Game over.
    await call('POST', `/rooms/${code}/host`, { action: 'next' }, H);
  }
  const end = await call('POST', `/rooms/${code}/host`, { action: 'end' }, H);
  ok(end.j.phase === 'end', 'host can end the game');
  const ag = await call('POST', `/rooms/${code}/host`, { action: 'again' }, H);
  ok(ag.j.phase === 'lobby' && ag.j.players.every((p) => p.alive && !p.score), 'play again resets players');
  const set = await call('POST', `/rooms/${code}/host`, { action: 'settings', mode: 'points', rounds: 0 }, H);
  ok(set.j.mode === 'points' && set.j.rounds === 15, 'points mode always has a set number of rounds');

  const chat = await call('POST', `/rooms/${code}/chat`, { msg: 'Too easy' }, toks[1]);
  ok(chat.status === 200, 'chat message sent');
  const seen = await state(code, toks[0]);
  ok(seen.chat.some((m) => m.msg === 'Too easy' && m.name === 'Ben'), 'chat message shows up in the poll');

  await call('POST', `/rooms/${code}/leave`, null, toks[2]);
  const gone = await state(code, toks[2]);
  ok(gone.gone === true, 'a player who left gets gone: true');
  const none = await call('GET', '/rooms/QQQQ');
  ok(none.status === 404, 'unknown code is a 404');
}

await game('survive');
await game('points');
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed');
process.exit(failures ? 1 : 0);
