// Trivia settings: mode, rounds, seconds, categories. Used before making a room and in the lobby.
import * as Haptics from 'expo-haptics';
import { Pressable, Text, View } from 'react-native';
import { C, Chip, F, Row, shadow } from '../../ui/kit';

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
            onPress={() => onChange({ mode: 'survive' })}
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
          value={rounds.includes(value.rounds) ? value.rounds : 15}
          show={(n) => (n ? String(n) : '∞')}
          say={(n) => (n ? `${n} rounds` : 'Until one is left')}
          onChange={(r) => onChange({ rounds: r })}
        />
        <Stepper label="Seconds per question" values={[15, 20, 30]} value={value.secs} show={String} say={(n) => `${n} seconds`} onChange={(secs) => onChange({ secs })} />
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

function Heading({ children }: { children: string }) {
  return <Text style={{ fontFamily: F.display, fontSize: 19, lineHeight: 24, color: C.ink }}>{children}</Text>;
}

function LinkButton({ title, onPress }: { title: string; onPress: () => void }) {
  return (
    <Pressable
      accessibilityRole="button"
      onPress={() => {
        tap();
        onPress();
      }}
      hitSlop={6}
      style={{ minHeight: 44, paddingHorizontal: 8, justifyContent: 'center' }}
    >
      <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.accent }}>{title}</Text>
    </Pressable>
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

function Stepper({
  label,
  values,
  value,
  show,
  say,
  onChange,
}: {
  label: string;
  values: number[];
  value: number;
  show: (n: number) => string;
  say: (n: number) => string;
  onChange: (n: number) => void;
}) {
  const i = Math.max(0, values.indexOf(value));
  const step = (d: number) => {
    const j = i + d;
    if (j < 0 || j >= values.length) return;
    tap();
    onChange(values[j]);
  };
  return (
    <View
      style={{ flex: 1, backgroundColor: C.goldTint, borderWidth: 3, borderColor: C.ink, borderRadius: 20, paddingHorizontal: 12, paddingVertical: 10 }}
      accessible
      accessibilityRole="adjustable"
      accessibilityLabel={label}
      accessibilityValue={{ text: say(value) }}
      accessibilityActions={[{ name: 'increment' }, { name: 'decrement' }]}
      onAccessibilityAction={(e) => step(e.nativeEvent.actionName === 'increment' ? 1 : -1)}
    >
      <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.ink }}>{label}</Text>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 6 }}>
        <StepButton sign="−" disabled={i === 0} onPress={() => step(-1)} />
        <Text style={{ fontFamily: F.display, fontSize: 30, lineHeight: 36, color: C.ink }}>{show(value)}</Text>
        <StepButton sign="+" disabled={i === values.length - 1} onPress={() => step(1)} />
      </View>
    </View>
  );
}

function StepButton({ sign, disabled, onPress }: { sign: string; disabled: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      importantForAccessibility="no"
      accessibilityElementsHidden
      style={({ pressed }) => ({ width: 44, height: 44, borderRadius: 22, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, alignItems: 'center', justifyContent: 'center', opacity: disabled ? 0.35 : pressed ? 0.7 : 1 })}
    >
      <Text style={{ fontFamily: F.heavy, fontSize: 22, lineHeight: 24, color: C.ink }}>{sign}</Text>
    </Pressable>
  );
}
