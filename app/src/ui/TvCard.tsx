import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { tvDiagnostics, type TvDiagnostics } from '../../modules/groupcade-tv';
import { links } from '../lib/api';
import { useTv } from '../lib/useTv';
import { C, Card, P } from './kit';

/** How to get the game on the TV, and whether it's there now. */
export function TvCard({ code, compact }: { code: string; compact?: boolean }) {
  const { supported, connected } = useTv();
  const [diag, setDiag] = useState<TvDiagnostics | null>(null);
  useEffect(() => {
    // Refresh the TV check every few seconds, so it shows a screen attaching or leaving.
    const read = () => setDiag(tvDiagnostics());
    read();
    const t = setInterval(read, 3000);
    return () => clearInterval(t);
  }, []);
  if (supported && connected) {
    return (
      <Card style={{ borderColor: 'rgba(91,227,139,0.5)' }}>
        <Text style={{ color: C.right, fontWeight: '800', fontSize: 16 }}>● TV connected</Text>
        {!compact && <P muted small>The TV shows the game. This phone is your controller, so nobody sees your screen.</P>}
      </Card>
    );
  }
  return (
    <Card>
      <Text style={{ color: C.white, fontWeight: '800', fontSize: 16 }}>Put it on the TV</Text>
      {supported ? (
        <P muted small>
          Open Control Center, tap Screen Mirroring and pick your Apple TV or AirPlay TV. The TV gets the big game screen, not a copy of your phone.
        </P>
      ) : null}
      {!compact || !supported ? (
        <View>
          <P muted small>
            {supported ? 'No AirPlay? ' : ''}On any TV or laptop browser, go to{' '}
            <Text style={{ color: C.white, fontWeight: '800' }}>{links.tvShort()}</Text> and type{' '}
            <Text style={{ color: C.white, fontWeight: '800', letterSpacing: 2 }}>{code}</Text>.
          </P>
        </View>
      ) : null}
      {supported && !compact && diag ? <DiagLine d={diag} /> : null}
    </Card>
  );
}

/** One small line that says what iOS sees, so a "it still mirrors" report shows why. */
function DiagLine({ d }: { d: TvDiagnostics }) {
  const ok = (b?: boolean) => (b ? '✓' : '✗');
  const tvScenes = (d.scenes || []).filter((r) => r.includes('External')).length;
  return (
    <Text style={{ color: C.mist, fontSize: 11, opacity: 0.8 }}>
      TV check: scenes {ok(d.multipleScenes)} · TV role {ok(d.tvRole)} · TV code {ok(d.delegateLinked)} · screens {d.screens ?? 0} · TV
      scenes {tvScenes} · iOS {d.ios ?? '?'} · accessory {d.accessory ?? '?'}
      {d.accessoryAvailable === undefined ? '' : ` (${d.accessoryAvailable ? 'TV available' : 'no TV yet'})`}
    </Text>
  );
}
