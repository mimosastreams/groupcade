import { useEffect, useState } from 'react';
import { addTvListener, isTvConnected, tvSupported } from '../../modules/groupcade-tv';

/** Whether this build can drive a TV, and whether one is connected right now. */
export function useTv() {
  const [connected, setConnected] = useState(isTvConnected());
  useEffect(() => {
    setConnected(isTvConnected());
    const sub = addTvListener(setConnected);
    return () => sub.remove();
  }, []);
  return { supported: tvSupported, connected };
}
