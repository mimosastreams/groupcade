// Optional host account. Hosts can sign in with Apple, Google or an emailed code to save their
// setup, game history and question packs. Players never sign in, and hosting works without it.
// The session token lives in the phone's secure storage and goes with every API call.
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';
import { useEffect, useState } from 'react';
import { Platform } from 'react-native';
import { accountApi, ApiError, setSession, type AuthConfig, type User } from './api';

const KEY = 'gc.session';

type State = { ready: boolean; user: User | null; config: AuthConfig | null };
let state: State = { ready: false, user: null, config: null };
const listeners = new Set<(s: State) => void>();
const set = (p: Partial<State>) => {
  state = { ...state, ...p };
  listeners.forEach((l) => l(state));
};

const device = () => `${Platform.OS === 'ios' ? 'iPhone' : 'Android'} app`;

async function keep(session: string | null) {
  setSession(session);
  try {
    if (session) await SecureStore.setItemAsync(KEY, session);
    else await SecureStore.deleteItemAsync(KEY);
  } catch {
    // Secure storage can fail on a locked device; the session still works until the app closes.
  }
}

let started = false;
/** Load the saved session once, at app start. Safe to call again. */
export async function initAccount() {
  if (started) return;
  started = true;
  accountApi
    .config()
    .then((config) => set({ config }))
    .catch(() => {});
  let s: string | null = null;
  try {
    s = await SecureStore.getItemAsync(KEY);
  } catch {}
  if (!s) return set({ ready: true });
  setSession(s);
  try {
    const r = await accountApi.me();
    if (!r.user) await keep(null);
    set({ ready: true, user: r.user });
  } catch {
    // Offline: stay signed in, try again next time.
    set({ ready: true });
  }
}

export function useAccount() {
  const [s, setS] = useState(state);
  useEffect(() => {
    listeners.add(setS);
    setS(state);
    return () => {
      listeners.delete(setS);
    };
  }, []);
  return s;
}

export const currentUser = () => state.user;

async function signedIn(r: { session: string; user: User }) {
  await keep(r.session);
  set({ user: r.user, ready: true });
  return r.user;
}

export class Cancelled extends Error {}

// ------------------------------------------------------------------ providers

/** Apple sign-in shows on iPhones (Apple requires it next to Google there). */
export async function appleAvailable() {
  if (Platform.OS !== 'ios') return false;
  try {
    const A = await import('expo-apple-authentication');
    return await A.isAvailableAsync();
  } catch {
    return false;
  }
}

export async function signInWithApple() {
  const A = await import('expo-apple-authentication');
  let cred;
  try {
    cred = await A.signInAsync({ requestedScopes: [A.AppleAuthenticationScope.FULL_NAME, A.AppleAuthenticationScope.EMAIL] });
  } catch (e) {
    if ((e as { code?: string }).code === 'ERR_REQUEST_CANCELED') throw new Cancelled();
    throw new Error('Apple sign-in didn’t finish. Try again.');
  }
  if (!cred.identityToken) throw new Error('Apple sign-in didn’t finish. Try again.');
  // Apple only shares the name the first time someone signs in to the app.
  const name = [cred.fullName?.givenName, cred.fullName?.familyName].filter(Boolean).join(' ');
  return signedIn(await accountApi.apple({ id_token: cred.identityToken, code: cred.authorizationCode, name, device: device() }));
}

/** Google needs its client IDs from the site, and on iPhone a URL scheme baked into the build. */
export function googleAvailable(c: AuthConfig | null) {
  if (!c) return false;
  if (Platform.OS === 'ios') return !!c.google.ios && !!Constants.expoConfig?.extra?.googleIosClientId;
  return !!c.google.web;
}

export async function signInWithGoogle() {
  const c = state.config || (await accountApi.config());
  const G = await import('@react-native-google-signin/google-signin');
  G.GoogleSignin.configure({ webClientId: c.google.web || undefined, iosClientId: c.google.ios || undefined });
  let token: string | null = null;
  try {
    if (Platform.OS === 'android') await G.GoogleSignin.hasPlayServices({ showPlayServicesUpdateDialog: true });
    const res = await G.GoogleSignin.signIn();
    if (!G.isSuccessResponse(res)) throw new Cancelled();
    token = res.data.idToken;
  } catch (e) {
    if (e instanceof Cancelled) throw e;
    if (G.isErrorWithCode(e) && e.code === G.statusCodes.SIGN_IN_CANCELLED) throw new Cancelled();
    throw new Error('Google sign-in didn’t finish. Try again.');
  }
  if (!token) throw new Error('Google sign-in didn’t finish. Try again.');
  // We keep our own session; Google's isn't needed after this.
  G.GoogleSignin.signOut().catch(() => {});
  return signedIn(await accountApi.google({ id_token: token, device: device() }));
}

export const emailStart = (email: string) => accountApi.emailStart(email.trim());
export const emailVerify = async (email: string, code: string) => signedIn(await accountApi.emailVerify(email.trim(), code.trim(), device()));

// ------------------------------------------------------------------ account

export async function refreshUser() {
  try {
    const r = await accountApi.me();
    if (!r.user) await keep(null);
    set({ user: r.user });
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) await signOutLocal();
  }
}

export async function updateUser(p: { name?: string; news?: boolean }) {
  const r = await accountApi.update(p);
  set({ user: r.user });
}

async function signOutLocal() {
  await keep(null);
  set({ user: null });
}

export async function signOut(all = false) {
  await accountApi.logout(all).catch(() => {});
  await signOutLocal();
}

export async function deleteAccount() {
  await accountApi.remove();
  await signOutLocal();
}
