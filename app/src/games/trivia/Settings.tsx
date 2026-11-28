// Trivia settings: mode, rounds, seconds, categories. Used before making a room and in the lobby.
import * as Haptics from 'expo-haptics';
import { Pressable, Text, View } from 'react-native';
import { C, Chip, F, Row, shadow } from '../../ui/kit';
import { Heading, LinkButton, Stepper } from '../../ui/Settings';

export type TriviaSettings = {
  mode: 'survive' | 'points';
  rounds: number;
  secs: number;
  cats: string[]; // Empty = every regular category. [NO_CATS] = nothing picked yet.
};

/** "Nothing picked": the host cleared everything and hasn't chosen yet. Never sent to the server. */
export const NO_CATS = '__none__';
export const noCats = (s: Pick<TriviaSettings, 'cats'>) => s.cats.length === 1 && s.cats[0] === NO_CATS;

export const DEFAULT_SETTINGS: TriviaSettings = { mode: 'survive', rounds: 0, secs: 30, cats: [] };

/** Seconds per question the host can pick. */
export const SECS = [15, 20, 30, 45, 60];

type Props = {
  value: TriviaSettings;
  onChange: (next: Partial<TriviaSettings>) => void;
  allCats: string[];
  specCats: string[];
};

const tap = () => Haptics.selectionAsync().catch(() => {});

export function TriviaSettingsForm({ value, onChange, allCats, specCats }: Props) {
  const pts = value.mode === 'points';
  const rounds = pts ? [10, 15, 20] : [10, 15, 20, 0];
  const none = noCats(value);
  const on = none ? [] : value.cats.length ? value.cats : allCats;

  // Any mix is fine (specialty only too), as long as something is on before the game starts.
  const save = (next: string[]) => {
    if (!next.length) return onChange({ cats: [NO_CATS] });
    const same = next.length === allCats.length && allCats.every((x) => next.includes(x));
    onChange({ cats: same ? [] : next });
  };
  const toggle = (c: string) => save(on.includes(c) ? on.filter((x) => x !== c) : [...on, c]);
  const specOn = on.filter((c) => specCats.includes(c));

  return (
    <View style={{ gap: 18 }}>
      <View style={{ gap: 8 }}>
        <Heading>Game mode</Heading>
        <View style={{ flexDirection: 'row', gap: 12 }}>
          <ModeCard
            title="Survival"
            line="Miss one and you're out"
            on={!pts}
            onPress={() => onChange({ mode: 'survive', rounds: 0 })}
          />
          <ModeCard
            title="Points"
            line="Faster answers score more"
            on={pts}
            onPress={() => onChange({ mode: 'points', rounds: value.rounds || 15 })}
          />
        </View>
      </View>

      <View style={{ flexDirection: 'row', gap: 12 }}>
        <Stepper
          label="Rounds"
          values={rounds}
          value={rounds.includes(value.rounds) ? value.rounds : pts ? 15 : 0}
          show={(n) => (n ? String(n) : '∞')}
          say={(n) => (n ? `${n} rounds` : 'Until one is left')}
          onChange={(r) => onChange({ rounds: r })}
        />
        <Stepper label="Seconds per question" values={SECS} value={SECS.includes(value.secs) ? value.secs : 30} show={String} say={(n) => `${n} seconds`} onChange={(secs) => onChange({ secs })} />
      </View>
      {!pts && value.rounds === 0 ? <Text style={{ fontFamily: F.bold, color: C.mist, fontSize: 14, marginTop: -8 }}>∞ = keep going until one player is left.</Text> : null}

      {allCats.length ? (
        <View style={{ gap: 10 }}>
          <Row style={{ justifyContent: 'space-between' }}>
            <Heading>Categories</Heading>
            <Row style={{ gap: 4 }}>
              <LinkButton title="All" onPress={() => save([...allCats, ...specOn])} />
              <LinkButton title="Clear" onPress={() => save(specOn)} />
            </Row>
          </Row>
          <Row style={{ gap: 8 }}>
            {allCats.map((c) => (
              <Chip key={c} text={c} on={on.includes(c)} onPress={() => toggle(c)} />
            ))}
          </Row>
          {none ? <Text style={{ color: C.out, fontFamily: F.heavy, fontSize: 14 }}>Pick at least one category.</Text> : null}
          {specCats.length ? (
            <>
              <Text style={{ color: C.mist, fontFamily: F.heavy, fontSize: 14, marginTop: 4 }}>Specialty (off unless you turn them on)</Text>
              <Row style={{ gap: 8 }}>
                {specCats.map((c) => (
                  <Chip key={c} text={c} on={on.includes(c)} onPress={() => toggle(c)} />
                ))}
              </Row>
            </>
          ) : null}
        </View>
      ) : null}
    </View>
  );
}



function ModeCard({ title, line, on, onPress }: { title: string; line: string; on: boolean; onPress: () => void }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected: on }}
      onPress={() => {
        tap();
        onPress();
      }}
      style={[
        { flex: 1, backgroundColor: on ? C.coral : C.white, borderWidth: 3, borderColor: C.ink, borderRadius: 20, padding: 12 },
        on ? [shadow(4), { transform: [{ rotate: '-1.5deg' }] }] : null,
      ]}
    >
      <Text style={{ fontFamily: F.display, fontSize: 22, lineHeight: 26, color: C.ink }}>{title}</Text>
      <Text style={{ fontFamily: F.heavy, fontSize: 13, color: on ? C.ink : C.mist, marginTop: 2 }}>{line}</Text>
    </Pressable>
  );
}


