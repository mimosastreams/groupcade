// AirPlay second screen. On iOS (AirPlay or a cable) and Android (a USB-C/HDMI adapter or a wireless display) with a dev or store build, the TV shows the room's /tv/CODE page
// in its own window while the phone keeps the host controls. In Expo Go or on web
// the native module is missing, and everything here quietly does nothing.
import { requireOptionalNativeModule, type EventSubscription } from 'expo';

type TvEvents = { onTvChange: (e: { connected: boolean }) => void };
type TvModule = {
  isConnected(): boolean;
  setRoomUrl(url: string | null): void;
  addListener<K extends keyof TvEvents>(name: K, fn: TvEvents[K]): EventSubscription;
};

const native = requireOptionalNativeModule<TvModule>('GroupcadeTv');

/** True when this build can drive a TV window (a dev or store build). */
export const tvSupported = !!native;

export function isTvConnected(): boolean {
  return native ? native.isConnected() : false;
}

/** Show this page on the TV, or null for the idle Groupcade screen. */
export function setTvRoomUrl(url: string | null): void {
  native?.setRoomUrl(url);
}

export function addTvListener(fn: (connected: boolean) => void): { remove(): void } {
  if (!native) return { remove() {} };
  return native.addListener('onTvChange', (e) => fn(!!e.connected));
}
