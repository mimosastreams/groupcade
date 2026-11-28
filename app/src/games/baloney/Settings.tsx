// Phony Baloney settings: rounds, seconds to write, categories. Used before making a room and in the lobby.
import { View } from 'react-native';
import type { BaloneyConfig } from '../../lib/api';
import { CatPicker, Stepper } from '../../ui/Settings';

export type BaloneySettings = { rounds: number; secs: number; cats: string[] }; // cats empty = all.

export const BALONEY_DEFAULTS: BaloneySettings = { rounds: 5, secs: 60, cats: [] };

export function BaloneySettingsForm({ value, onChange, cfg }: { value: BaloneySettings; onChange: (next: Partial<BaloneySettings>) => void; cfg: Pick<BaloneyConfig, 'cats' | 'rounds' | 'secs'> }) {
  const rounds = cfg.rounds.length ? cfg.rounds : [5, 7, 10];
  const secs = cfg.secs.length ? cfg.secs : [45, 60, 90];
  return (
    <View style={{ gap: 18 }}>
      <View style={{ flexDirection: 'row', gap: 12 }}>
        <Stepper label="Rounds" values={rounds} value={rounds.includes(value.rounds) ? value.rounds : rounds[0]} show={String} say={(n) => `${n} rounds`} onChange={(r) => onChange({ rounds: r })} />
        <Stepper label="Seconds to write" values={secs} value={secs.includes(value.secs) ? value.secs : secs[Math.min(1, secs.length - 1)]} show={String} say={(n) => `${n} seconds`} onChange={(s) => onChange({ secs: s })} />
      </View>
      {cfg.cats.length > 1 ? <CatPicker all={cfg.cats} value={value.cats} onChange={(cats) => onChange({ cats })} /> : null}
    </View>
  );
}
