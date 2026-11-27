import { useEffect, useState } from 'react';
import { Platform, Text, View } from 'react-native';
import { tvDiagnostics, type TvDiagnostics } from '../../modules/groupcade-tv';
import { links } from '../lib/api';
import { useCast } from '../lib/cast';
import { useTv } from '../lib/useTv';
import { Button, C, Card, F, P } from './kit';
import { Logo } from './Logo';

const ios = Platform.OS === 'ios';

/** How to get the game on the TV, and whether it's there now. Every way the phone can reach a TV:
 * Chromecast (both), AirPlay (iPhone), a cable or wireless display (Android), or any TV browser. */
export function TvCard({ code, compact }: { code: string; compact?: boolean }) {
  const screen = useTv();
  const cast = useCast();
  const [diag, setDiag] = useState<TvDiagnostics | null>(null);
  useEffect(() => {
    // Refresh the TV check every few seconds, so it shows a screen attaching or leaving.
    const read = () => setDiag(tvDiagnostics());
    read();
    const t = setInterval(read, 3000);
    return () => clearInterval(t);
  }, []);
  const castOn = cast.status === 'on';

  if ((screen.supported && screen.connected) || castOn) {
    return (
      <View style={{ backgroundColor: C.ink, borderWidth: 3, borderColor: C.ink, borderRadius: 24, paddingVertical: 12, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 12, transform: [{ rotate: '-1deg' }] }}>
        <Logo size={compact ? 40 : 58} />
        <View style={{ flex: 1, gap: 2 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
            <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: C.mint }} />
            <Text style={{ fontFamily: F.display, fontSize: 19, lineHeight: 24, color: C.white }}>{castOn ? 'Casting to the TV' : 'TV connected'}</Text>
          </View>
          {!compact ? <Text style={{ fontFamily: F.body, fontSize: 13, lineHeight: 18, color: C.onDark }}>The TV shows the game. This phone is your controller.</Text> : null}
        </View>
        {castOn ? <Button small kind="plain" title="Stop" onPress={cast.toggle} /> : null}
      </View>
    );
  }

  const castFound = cast.supported && cast.status !== 'none';
  return (
    <Card tint={C.sky} style={{ borderRadius: 24 }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
        <Logo size={compact ? 32 : 44} />
        <Text style={{ fontFamily: F.display, fontSize: 19, lineHeight: 24, color: C.ink, flex: 1 }}>Put it on the TV</Text>
      </View>
      {castFound ? (
        <Button
          kind="go"
          small
          title={cast.status === 'connecting' ? 'Connecting…' : 'Cast to Chromecast or Google TV'}
          busy={cast.status === 'connecting'}
          onPress={cast.toggle}
        />
      ) : null}
      {screen.supported && ios ? (
        <P muted small>
          Apple TV or AirPlay TV: open Control Center, tap Screen Mirroring and pick it. The TV gets the big game screen, not a copy of your phone.
        </P>
      ) : null}
      {screen.supported && !ios && !compact ? (
        <P muted small>Plugged into the TV with a USB-C to HDMI cable? The TV gets the big game screen on its own.</P>
      ) : null}
      {!compact || !(screen.supported || castFound) ? (
        <View>
          <P muted small>
            {screen.supported || castFound ? 'Or, on' : 'On'} any TV or laptop browser, go to{' '}
            <Text style={{ fontFamily: F.heavy, color: C.ink }}>{links.tvShort()}</Text> and type{' '}
            <Text style={{ fontFamily: F.heavy, color: C.ink, letterSpacing: 2 }}>{code}</Text>.
          </P>
        </View>
      ) : null}
      {screen.supported && ios && !compact && diag ? <DiagLine d={diag} /> : null}
    </Card>
  );
}

/** One small line that says what iOS sees, so a "it still mirrors" report shows why. */
function DiagLine({ d }: { d: TvDiagnostics }) {
  const ok = (b?: boolean) => (b ? '✓' : '✗');
  const tvScenes = (d.scenes || []).filter((r) => r.includes('External')).length;
  return (
    <Text style={{ color: C.mist, fontSize: 11, fontFamily: F.body }}>
      TV check: scenes {ok(d.multipleScenes)} · TV role {ok(d.tvRole)} · TV code {ok(d.delegateLinked)} · screens {d.screens ?? 0} · TV
      scenes {tvScenes} · iOS {d.ios ?? '?'} · accessory {d.accessory ?? '?'}
      {d.accessoryAvailable === undefined ? '' : ` (${d.accessoryAvailable ? 'TV available' : 'no TV yet'})`}
    </Text>
  );
}
