// Trivia settings: mode, rounds, seconds, categories. Used before making a room and in the lobby.
import { Text, View } from 'react-native';
import { Button, C, Chip, Label, P, Row, Seg } from '../../ui/kit';

export type TriviaSettings = {
  mode: 'survive' | 'points';
  rounds: number;
  secs: number;
  cats: string[]; // Empty = every regular category.
};

export const DEFAULT_SETTINGS: TriviaSettings = { mode: 'survive', rounds: 15, secs: 20, cats: [] };

type Props = {
  value: TriviaSettings;
  onChange: (next: Partial<TriviaSettings>) => void;
  allCats: string[];
  specCats: string[];
};

export function TriviaSettingsForm({ value, onChange, allCats, specCats }: Props) {
  const pts = value.mode === 'points';
  const rounds = pts ? [10, 15, 20] : [10, 15, 20, 0];
  const on = value.cats.length ? value.cats : allCats;
  const regularOn = on.filter((c) => allCats.includes(c));

  const toggle = (c: string) => {
    const next = on.includes(c) ? on.filter((x) => x !== c) : [...on, c];
    if (!next.some((x) => allCats.includes(x))) return; // Keep at least one regular category.
    const same = next.length === allCats.length && allCats.every((x) => next.includes(x));
    onChange({ cats: same ? [] : next });
  };

  return (
    <View style={{ gap: 12 }}>
      <Label>Game mode</Label>
      <Seg
        options={[
          { value: 'survive', label: 'Survival' },
          { value: 'points', label: 'Points' },
        ]}
        value={value.mode}
        onChange={(mode) => onChange({ mode, rounds: mode === 'points' && !value.rounds ? 15 : value.rounds })}
      />
      <P muted small>
        {pts
          ? 'Nobody is knocked out. A right answer scores 500 points plus up to 500 more for speed. Most points after the last round wins.'
          : "Wrong answer and you're out. If everyone misses, nobody is out. Last one standing wins."}
      </P>

      <Label>Rounds</Label>
      <Seg options={rounds.map((n) => ({ value: n, label: n ? String(n) : 'Until one is left' }))} value={value.rounds} onChange={(r) => onChange({ rounds: r })} />

      <Label>Seconds per question</Label>
      <Seg options={[15, 20, 30].map((n) => ({ value: n, label: `${n}s` }))} value={value.secs} onChange={(secs) => onChange({ secs })} />

      {allCats.length ? (
        <>
          <Row style={{ justifyContent: 'space-between' }}>
            <Label>
              Categories ({regularOn.length} of {allCats.length})
            </Label>
            <Row>
              <Button small kind="ghost" title="All" onPress={() => {
                  const spec = on.filter((c) => specCats.includes(c));
                  onChange({ cats: spec.length ? [...allCats, ...spec] : [] });
                }} />
            </Row>
          </Row>
          <Row style={{ gap: 8 }}>
            {allCats.map((c) => (
              <Chip key={c} text={c} on={on.includes(c)} onPress={() => toggle(c)} />
            ))}
          </Row>
          {specCats.length ? (
            <>
              <Text style={{ color: C.mist, fontWeight: '700', fontSize: 13 }}>Specialty (off unless you turn them on)</Text>
              <Row style={{ gap: 8 }}>
                {specCats.map((c) => (
                  <Chip
                    key={c}
                    text={`🎄 ${c}`}
                    on={value.cats.includes(c)}
                    onPress={() => {
                      const base = value.cats.length ? value.cats : allCats;
                      onChange({ cats: base.includes(c) ? base.filter((x) => x !== c) : [...base, c] });
                    }}
                  />
                ))}
              </Row>
            </>
          ) : null}
        </>
      ) : null}
    </View>
  );
}
