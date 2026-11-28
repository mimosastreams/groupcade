// End-to-end test of host accounts against a LOCAL test site that has
// plugin/tests/gc-test-helpers.php in wp-content/mu-plugins/ (never on the real site).
//   node plugin/tests/accounts-smoke.mjs http://127.0.0.1:8899 /path/to/wp-content
// Covers email codes, Apple and Google tokens (signed with a throwaway test key), sessions,
// saved settings, packs in a game, game history, claiming a guest room, sign out and delete.

import { generateKeyPairSync, createSign } from 'node:crypto';
import { writeFileSync } from 'node:fs';

const site = (process.argv[2] || 'http://127.0.0.1:8899').replace(/\/$/, '');
const content = process.argv[3];
if (!content) throw new Error('Pass the test site wp-content folder as the second argument.');
const base = site + '/wp-json/groupcade/v1';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failures = 0;
const ok = (cond, msg) => { console.log((cond ? '  ok   ' : '  FAIL ') + msg); if (!cond) failures++; };

async function call(method, path, body, headers = {}, root = base) {
  const h = { 'X-Groupcade': '1', ...headers };
  if (body) h['Content-Type'] = 'application/json';
  const r = await fetch(root + path, { method, headers: h, body: body ? JSON.stringify(body) : undefined });
  const j = await r.json().catch(() => ({}));
  return { status: r.status, j, r };
}

// A throwaway RSA key stands in for Apple's and Google's.
const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
const jwk = publicKey.export({ format: 'jwk' });
writeFileSync(content + '/gc-test-jwks.json', JSON.stringify({ keys: [{ ...jwk, kid: 'test1', alg: 'RS256', use: 'sig' }] }));
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
function token(claims, kid = 'test1', key = privateKey) {
  const head = b64({ alg: 'RS256', kid, typ: 'JWT' });
  const body = b64({ iat: Math.floor(Date.now() / 1000), exp: Math.floor(Date.now() / 1000) + 600, ...claims });
  const s = createSign('RSA-SHA256').update(head + '.' + body).sign(key).toString('base64url');
  return `${head}.${body}.${s}`;
}
const S = (tok) => ({ 'X-Groupcade-Session': tok });

async function waitPhase(code, phase, hdr, ms = 12000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    const st = (await call('GET', `/rooms/${code}`, null, hdr)).j;
    if (st.phase === phase) return st;
    await sleep(300);
  }
  throw new Error(`timed out waiting for ${phase}`);
}

const email = `host${Date.now()}@example.com`;

console.log('\n== sign in with an email code ==');
let r = await call('GET', '/me');
ok(r.status === 200 && r.j.user === null, 'signed out: /me says user null');
r = await call('POST', '/auth/email/start', { email: 'not-an-email' });
ok(r.status === 400, 'a bad address is refused');
r = await call('POST', '/auth/email/start', { email });
ok(r.status === 200, 'code emailed');
const code = (await call('GET', `/code?email=${encodeURIComponent(email)}`, null, {}, site + '/wp-json/gctest/v1')).j.code;
ok(/^\d{6}$/.test(code), 'test site saw the 6-digit code');
r = await call('POST', '/auth/email/verify', { email, code: code === '000000' ? '111111' : '000000' });
ok(r.status === 400, 'a wrong code is refused');
r = await call('POST', '/auth/email/verify', { email, code, device: 'Test' });
ok(r.status === 200 && /^[a-f0-9]{64}$/.test(r.j.session), 'right code signs in and gives a session');
const tokA = r.j.session;
const uidA = r.j.user.id;
ok(r.j.user.name === email.split('@')[0].replace(/^./, (c) => c.toUpperCase()), 'name starts from the email');
ok(r.j.user.founding === true && r.j.user.plan === 'founding', 'new host is a founding member');
r = await call('POST', '/auth/email/verify', { email, code });
ok(r.status === 400, 'a code works only once');

r = await call('GET', '/me', null, S(tokA));
ok(r.j.user && r.j.user.id === uidA, '/me with the session header');
r = await call('GET', '/me', null, S('f'.repeat(64)));
ok(r.j.user === null, 'an unknown session is signed out');
r = await call('POST', '/me', { name: 'Dana', news: true }, S(tokA));
ok(r.j.user.name === 'Dana' && r.j.user.news === true, 'name and news opt-in saved');
r = await call('POST', '/me', { name: 'x' }, { 'X-Groupcade': '0', ...S(tokA) });
ok(r.status === 401 || r.status === 403, 'POST without X-Groupcade is refused');

console.log('\n== Google and Apple ==');
r = await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'test-ios.apps.googleusercontent.com', sub: 'g-123', email, email_verified: true, given_name: 'Dana G' }) });
ok(r.status === 200 && r.j.user.id === uidA, 'Google with the same verified email joins the same account');
ok(r.j.user.via.includes('google') && r.j.user.via.includes('email'), 'account lists both sign-in methods');
r = await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'someone-else', sub: 'g-9', email: 'a@b.co', email_verified: true }) });
ok(r.status === 401, 'a token for another app is refused');
const other = generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey;
r = await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'test-web.apps.googleusercontent.com', sub: 'g-9' }, 'test1', other) });
ok(r.status === 401, 'a token with a bad signature is refused');
r = await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'test-web.apps.googleusercontent.com', sub: 'g-9', exp: Math.floor(Date.now() / 1000) - 3600 }) });
ok(r.status === 401, 'an expired token is refused');
r = await call('POST', '/auth/apple', { id_token: token({ iss: 'https://appleid.apple.com', aud: 'com.groupcade.app', sub: 'apple.001', email: 'xyz@privaterelay.appleid.com', email_verified: 'true' }), name: 'Robin Apple', web: true });
ok(r.status === 200 && r.j.user.id !== uidA && r.j.user.name === 'Robin Apple' && r.j.user.relay, 'Apple with a relay email makes a new host, with the name the app passed');
const tokB = r.j.session;
const uidB = r.j.user.id;
ok(/gc_session=[a-f0-9]{64}.*HttpOnly/i.test(r.r.headers.get('set-cookie') || ''), 'web: true sets the http-only cookie');
r = await call('GET', '/me', null, { Cookie: `gc_session=${tokB}` });
ok(r.j.user && r.j.user.id === uidB, 'the cookie signs the website in');
r = await call('POST', '/auth/apple', { id_token: token({ iss: 'https://appleid.apple.com', aud: 'com.groupcade.web', sub: 'apple.001' }) });
ok(r.status === 200 && r.j.user.id === uidB && r.j.user.name === 'Robin Apple', 'Apple from the website (Services ID) finds the same host');

console.log('\n== saved settings ==');
r = await call('POST', '/me/prefs', { game: 'trivia', settings: { mode: 'points', rounds: 10, secs: 15, cats: ['Movies', 'Music'], '<b>': 'x' } }, S(tokA));
ok(r.status === 200 && r.j.settings.rounds === 10, 'settings saved');
r = await call('GET', '/me/prefs?game=trivia', null, S(tokA));
ok(r.j.settings && r.j.settings.mode === 'points' && r.j.settings.cats.length === 2, 'settings come back');
r = await call('GET', '/me/prefs?game=trivia', null, S(tokB));
ok(r.j.settings === null, 'another host has none');
r = await call('GET', '/me/prefs?game=trivia');
ok(r.status === 401, 'signed out: no settings');

console.log('\n== question packs ==');
r = await call('POST', '/me/packs', { name: '' }, S(tokA));
ok(r.status === 400, 'a pack needs a name');
r = await call('POST', '/me/packs', { name: "Sam's 30th" }, S(tokA));
ok(r.status === 200 && r.j.pack.count === 0, 'pack made');
const pid = r.j.pack.id;
r = await call('POST', `/me/packs/${pid}`, { questions: [{ q: 'Bad', right: 'A', wrong: ['B', 'C'] }] }, S(tokA));
ok(r.status === 400 && /three wrong/.test(r.j.message), 'a question without 3 wrong answers is refused');
r = await call('POST', `/me/packs/${pid}`, { questions: [{ q: 'Same', right: 'A', wrong: ['a', 'C', 'D'] }] }, S(tokA));
ok(r.status === 400 && /same answer/.test(r.j.message), 'repeated answers are refused');
const qs = [
  { level: 'easy', q: 'Where did Sam grow up?', right: 'Tulsa', wrong: ['Omaha', 'Wichita', 'Dayton'] },
  { level: 'medium', cat: 'Sam trivia', q: "Sam's first car?", right: 'Civic', wrong: ['Corolla', 'Focus', 'Jetta'] },
  { level: 'hard', q: 'Sam <b>owns</b> how many cats?', right: '3', wrong: ['1', '2', '4'] },
];
r = await call('POST', `/me/packs/${pid}`, { name: 'Sam turns 30', questions: qs }, S(tokA));
ok(r.status === 200 && r.j.pack.count === 3 && r.j.pack.name === 'Sam turns 30', 'pack saved with 3 questions');
ok(r.j.pack.questions[2].q === 'Sam owns how many cats?', 'tags are stripped from questions');
r = await call('GET', `/me/packs/${pid}`, null, S(tokB));
ok(r.status === 404, "another host can't read the pack");
r = await call('GET', '/me/packs', null, S(tokA));
ok(r.j.packs.length === 1 && r.j.packs[0].count === 3, 'pack listed');

console.log('\n== a signed-in host plays their pack ==');
r = await call('POST', '/rooms', { game: 'trivia', mode: 'points', rounds: 10, secs: 15, pack: pid }, S(tokA));
ok(r.status === 200, `made room ${r.j.code}`);
let room = r.j.code;
const H = { 'X-Party-Host': r.j.host };
const p1 = (await call('POST', `/rooms/${room}/join`, { name: 'Ana' })).j.token;
const p2 = (await call('POST', `/rooms/${room}/join`, { name: 'Ben' })).j.token;
let st = (await call('GET', `/rooms/${room}`, null, H)).j;
ok(st.pack && st.pack.name === 'Sam turns 30' && st.owned === true, 'room shows the pack and that it is saved');
await call('POST', `/rooms/${room}/host`, { action: 'start' }, H);
st = await waitPhase(room, 'q', H);
ok(qs.some((q) => q.q.replace(/<\/?b>/g, '') === st.q), `round 1 asks a pack question ("${st.q}")`);
ok(st.cat === 'Sam turns 30' || st.cat === 'Sam trivia', 'its category is the pack name or its own');
const right = (await waitPhase(room, 'q', { 'X-Party-Token': p1 })).choices;
// Ana answers right, Ben wrong.
const correct = qs.find((q) => q.q.replace(/<\/?b>/g, '') === st.q).right;
await call('POST', `/rooms/${room}/action`, { type: 'answer', round: 1, choice: right.indexOf(correct) + 1 }, { 'X-Party-Token': p1 });
await call('POST', `/rooms/${room}/action`, { type: 'answer', round: 1, choice: ((right.indexOf(correct) + 1) % 4) + 1 }, { 'X-Party-Token': p2 });
await waitPhase(room, 'rev', H);
await call('POST', `/rooms/${room}/host`, { action: 'end' }, H);
st = await waitPhase(room, 'end', H);
ok(JSON.stringify(st.winners) === '["Ana"]', 'host ends the game, Ana wins');

r = await call('GET', '/me/history', null, S(tokA));
ok(r.j.total === 1 && r.j.games[0].winners[0] === 'Ana' && r.j.games[0].players === 2, 'the game is in the host history');
ok(r.j.games[0].top.length === 2 && r.j.games[0].top[0].n === 'Ana' && r.j.games[0].top[0].w === 1, 'with final places');
ok(r.j.board.length === 1 && r.j.board[0].name === 'Ana' && r.j.board[0].wins === 1, 'most-wins board has Ana');

r = await call('POST', '/rooms', { game: 'trivia', pack: pid }, S(tokB));
st = (await call('GET', `/rooms/${r.j.code}`, null, { 'X-Party-Host': r.j.host })).j;
ok(st.pack === null, "another host can't play someone else's pack");

console.log('\n== a guest host saves their game after ==');
r = await call('POST', '/rooms', { game: 'trivia', mode: 'points', rounds: 10, secs: 15 });
room = r.j.code;
const G = { 'X-Party-Host': r.j.host };
const g1 = (await call('POST', `/rooms/${room}/join`, { name: 'Cy' })).j.token;
await call('POST', `/rooms/${room}/host`, { action: 'start' }, G);
st = await waitPhase(room, 'q', G);
await call('POST', `/rooms/${room}/action`, { type: 'answer', round: 1, choice: 1 }, { 'X-Party-Token': g1 });
await waitPhase(room, 'rev', G);
await call('POST', `/rooms/${room}/host`, { action: 'end' }, G);
await waitPhase(room, 'end', G);
ok((await call('GET', `/rooms/${room}`, null, G)).j.owned === false, 'guest room is not saved');
r = await call('POST', `/rooms/${room}/claim`, {}, { ...S(tokB), 'X-Party-Host': 'nope' });
ok(r.status === 403, 'only the host can claim the room');
r = await call('POST', `/rooms/${room}/claim`, {}, G);
ok(r.status === 401, 'claiming needs a sign-in');
r = await call('POST', `/rooms/${room}/claim`, {}, { ...S(tokB), ...G });
ok(r.status === 200 && r.j.games === 1, 'signed-in host claims the room and its game');
r = await call('GET', '/me/history', null, S(tokB));
ok(r.j.total === 1 && r.j.games[0].top.length === 1 && r.j.games[0].top[0].n === 'Cy', 'claimed game has its final places');
r = await call('POST', `/rooms/${room}/claim`, {}, { ...S(tokA), ...G });
ok(r.status === 409, 'someone else cannot take a saved room');

console.log('\n== sign out and delete ==');
const tokA2 = (await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'test-ios.apps.googleusercontent.com', sub: 'g-123' }) })).j.session;
r = await call('POST', '/auth/logout', {}, S(tokA));
ok((await call('GET', '/me', null, S(tokA))).j.user === null, 'sign out ends that session');
ok((await call('GET', '/me', null, S(tokA2))).j.user.id === uidA, 'other devices stay signed in');
r = await call('POST', '/auth/logout', { all: true }, S(tokA2));
ok((await call('GET', '/me', null, S(tokA2))).j.user === null, 'sign out everywhere ends them all');

const tokA3 = (await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'test-ios.apps.googleusercontent.com', sub: 'g-123' }) })).j.session;
r = await call('POST', '/me/delete', {}, S(tokA3));
ok(r.status === 200, 'account deleted');
ok((await call('GET', '/me', null, S(tokA3))).j.user === null, 'its sessions are gone');
r = await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'test-ios.apps.googleusercontent.com', sub: 'g-123', email, email_verified: true }) });
ok(r.status === 200 && r.j.user.id !== uidA, 'signing in again starts a fresh account');
r = await call('GET', '/me/history', null, S(r.j.session));
ok(r.j.total === 0, 'with no history');
r = await call('GET', '/me/packs', null, S((await call('POST', '/auth/google', { id_token: token({ iss: 'https://accounts.google.com', aud: 'test-ios.apps.googleusercontent.com', sub: 'g-123' }) })).j.session));
ok(r.j.packs.length === 0, 'and no packs');

console.log(failures ? `\n${failures} check(s) failed` : '\nAll account checks passed');
process.exit(failures ? 1 : 0);
