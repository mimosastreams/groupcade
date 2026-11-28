// New Scribble Squad room: rounds and seconds to draw.
import { ScribbleSettingsForm, SCRIBBLE_DEFAULTS, type ScribbleSettings } from '../../games/scribble/Settings';
import { NewRoomScreen } from '../../games/shared/NewRoom';
import type { ScribbleConfig } from '../../lib/api';

export default function NewScribble() {
  return (
    <NewRoomScreen<ScribbleSettings, ScribbleConfig>
      slug="scribble"
      title="Scribble Squad"
      defaults={SCRIBBLE_DEFAULTS}
      intro="Everyone draws a secret prompt on their phone, then guesses each other's sketches on the TV."
      clean={(s) => ({ rounds: s.rounds, secs: s.secs })}
      form={(value, onChange, cfg) => <ScribbleSettingsForm value={value} onChange={onChange} cfg={cfg} />}
    />
  );
}
