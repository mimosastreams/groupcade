// New Phony Baloney room: rounds, seconds to write, categories.
import { BaloneySettingsForm, BALONEY_DEFAULTS, type BaloneySettings } from '../../games/baloney/Settings';
import { NewRoomScreen } from '../../games/shared/NewRoom';
import type { BaloneyConfig } from '../../lib/api';

export default function NewBaloney() {
  return (
    <NewRoomScreen<BaloneySettings, BaloneyConfig>
      slug="baloney"
      title="Phony Baloney"
      defaults={BALONEY_DEFAULTS}
      intro="Everyone writes a fake answer that sounds real, then hunts for the truth."
      clean={(s, cfg) => {
        // Drop categories this site no longer has. None left means all of them.
        const known = cfg.cats.map((c) => c.id);
        const cats = s.cats.filter((c) => known.includes(c));
        return { rounds: s.rounds, secs: s.secs, cats: cats.length ? cats : (undefined as unknown as string[]) };
      }}
      form={(value, onChange, cfg) => <BaloneySettingsForm value={value} onChange={onChange} cfg={cfg} />}
    />
  );
}
