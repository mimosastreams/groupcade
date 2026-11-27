// Google Cast (Chromecast, Google TV, TVs with Cast built in), on Android and iPhone.
// The Chromecast runs the site's Cast receiver (the same one the web host page uses, set up in
// Settings > Groupcade), and the app tells it which room to show over the com.groupcade channel.
// Needs GROUPCADE_CAST_APP_ID at build time (see app.config.js); without it, or in Expo Go, Cast
// is hidden and everything here does nothing.
import Constants from 'expo-constants';
import { useEffect, useState } from 'react';
import { NativeModules } from 'react-native';

type GoogleCast = typeof import('react-native-google-cast');
type Session = import('react-native-google-cast').CastSession;
type Channel = import('react-native-google-cast').CastChannel;

const NS = 'urn:x-cast:com.groupcade';
const appId = String(Constants.expoConfig?.extra?.castAppId || '');

let GC: GoogleCast | null = null;
if (appId && NativeModules.RNGCCastContext) {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    GC = require('react-native-google-cast') as GoogleCast;
  } catch {
    GC = null;
  }
}

/** True when this build can cast (a Cast app ID was set and the native library is there). */
export const castSupported = !!GC;

export type CastStatus = 'none' | 'idle' | 'connecting' | 'on';

let code: string | null = null;
let channel: Channel | null = null;
let status: CastStatus = 'none';
const listeners = new Set<(s: CastStatus) => void>();

function setStatus(s: CastStatus) {
  if (s === status) return;
  status = s;
  listeners.forEach((fn) => fn(s));
}

function send() {
  if (channel && code) channel.sendMessage({ code }).catch(() => {});
}

async function open(session: Session) {
  try {
    channel?.remove();
    channel = await session.addChannel(NS);
    setStatus('on');
    send();
  } catch {
    channel = null;
  }
}

if (GC) {
  const { CastContext, CastState } = GC;
  const sm = CastContext.getSessionManager();
  sm.onSessionStarted(open);
  sm.onSessionResumed(open);
  sm.onSessionEnded(() => {
    channel = null;
    setStatus('idle');
  });
  const fromState = (s: string | null) =>
    s === CastState.CONNECTED ? 'on' : s === CastState.CONNECTING ? 'connecting' : s === CastState.NOT_CONNECTED ? 'idle' : 'none';
  CastContext.onCastStateChanged((s) => {
    const next = fromState(s);
    // 'on' is set once the channel is open, so the room code goes over first.
    if (next !== 'on') setStatus(next);
  });
  CastContext.getCastState()
    .then((s) => {
      if (s === CastState.CONNECTED) sm.getCurrentCastSession().then((x) => x && open(x));
      else setStatus(fromState(s));
    })
    .catch(() => {});
}

/** Show this room on the Chromecast (if one is casting), or null when no room is open. */
export function setCastRoom(next: string | null) {
  code = next;
  send();
}

/** Opens the system Cast picker, or ends casting if already on. */
export function toggleCast() {
  if (!GC) return;
  if (status === 'on' || status === 'connecting') GC.CastContext.getSessionManager().endCurrentSession(true).catch(() => {});
  else GC.CastContext.showCastDialog().catch(() => {});
}

/** 'none' = no Cast devices on this Wi-Fi (or no Cast in this build), 'idle' = devices found. */
export function useCast() {
  const [s, setS] = useState(status);
  useEffect(() => {
    setS(status);
    listeners.add(setS);
    return () => {
      listeners.delete(setS);
    };
  }, []);
  return { supported: castSupported, status: s, toggle: toggleCast };
}
