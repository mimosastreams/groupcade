// Trivia settings: mode, rounds, seconds, categories. Used before making a room and in the lobby.
import { Text, View } from 'react-native';
import { Button, C, Chip, Label, P, Row, Seg } from '../../ui/kit';

export type TriviaSettings = {
  mode: 'survive' | 'points';
  rounds: number;
  secs: number;
  cats: string[]; // Empty = every regular category. [NO_CATS] = nothing picked yet.
};

/** "Nothing picked": the host cleared everything and hasn't chosen yet. Never sent to the server. */
export const NO_CATS = '__none__';
export const noCats = (s: Pick<TriviaSettings, 'cats'>) => s.cats.length === 1 && s.cats[0] === NO_CATS;

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
  const none = noCats(value);
  const on = none ? [] : value.cats.length ? value.cats : allCats;
  const regularOn = on.filter((c) => allCats.includes(c));

  // Any mix is fine (specialty only too), as long as something is on before the game starts.
  const save = (next: string[]) => {
    if (!next.length) return onChange({ cats: [NO_CATS] });
    const same = next.length === allCats.length && allCats.every((x) => next.includes(x));
    onChange({ cats: same ? [] : next });
  };
  const toggle = (c: string) => save(on.includes(c) ? on.filter((x) => x !== c) : [...on, c]);
  const specOn = on.filter((c) => specCats.includes(c));

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
            <Row style={{ gap: 6 }}>
              <Button small kind="ghost" title="All" onPress={() => save([...allCats, ...specOn])} />
              <Button small kind="ghost" title="Clear" onPress={() => save(specOn)} />
            </Row>
          </Row>
          <Row style={{ gap: 8 }}>
            {allCats.map((c) => (
              <Chip key={c} text={c} on={on.includes(c)} onPress={() => toggle(c)} />
            ))}
          </Row>
          {none ? <Text style={{ color: C.gold, fontWeight: '700', fontSize: 13 }}>Pick at least one category.</Text> : null}
          {specCats.length ? (
            <>
              <Text style={{ color: C.mist, fontWeight: '700', fontSize: 13 }}>Specialty (off unless you turn them on)</Text>
              <Row style={{ gap: 8 }}>
                {specCats.map((c) => (
                  <Chip
                    key={c}
                    text={`🎄 ${c}`}
                    on={on.includes(c)}
                    onPress={() => toggle(c)}
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
