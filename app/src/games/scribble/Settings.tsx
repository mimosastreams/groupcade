// Scribble Squad settings: rounds (everyone draws once a round) and seconds to draw.
import { View } from 'react-native';
import type { ScribbleConfig } from '../../lib/api';
import { Stepper } from '../../ui/Settings';

export type ScribbleSettings = { rounds: number; secs: number };

export const SCRIBBLE_DEFAULTS: ScribbleSettings = { rounds: 2, secs: 75 };

export function ScribbleSettingsForm({ value, onChange, cfg }: { value: ScribbleSettings; onChange: (next: Partial<ScribbleSettings>) => void; cfg: Pick<ScribbleConfig, 'rounds' | 'secs'> }) {
  const rounds = cfg.rounds.length ? cfg.rounds : [1, 2, 3];
  const secs = cfg.secs.length ? cfg.secs : [60, 75, 90];
  return (
    <View style={{ flexDirection: 'row', gap: 12 }}>
      <Stepper label="Rounds" values={rounds} value={rounds.includes(value.rounds) ? value.rounds : rounds[0]} show={String} say={(n) => `${n} round${n === 1 ? '' : 's'}, everyone draws each round`} onChange={(r) => onChange({ rounds: r })} />
      <Stepper label="Seconds to draw" values={secs} value={secs.includes(value.secs) ? value.secs : secs[Math.min(1, secs.length - 1)]} show={String} say={(n) => `${n} seconds`} onChange={(s) => onChange({ secs: s })} />
    </View>
  );
}
