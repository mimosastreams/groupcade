// Settings controls every game's setup uses: section headings, text links and +/- steppers.
import * as Haptics from 'expo-haptics';
import { Pressable, Text, View } from 'react-native';
import { C, Chip, F, Row } from './kit';

export const tap = () => Haptics.selectionAsync().catch(() => {});

export function Heading({ children }: { children: string }) {
  return <Text style={{ fontFamily: F.display, fontSize: 19, lineHeight: 24, color: C.ink }}>{children}</Text>;
}

export function LinkButton({ title, onPress }: { title: string; onPress: () => void }) {
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

export function Stepper({
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

/** Category chips with All / Clear. Empty value = every category on; at least one stays on. */
export function CatPicker({ all, value, onChange, title = 'Categories' }: { all: { id: string; name: string }[]; value: string[]; onChange: (next: string[]) => void; title?: string }) {
  const on = value.length ? value : all.map((c) => c.id);
  const save = (next: string[]) => {
    if (!next.length) return; // Keep at least one on.
    onChange(next.length === all.length ? [] : next);
  };
  const toggle = (id: string) => save(on.includes(id) ? on.filter((x) => x !== id) : [...on, id]);
  return (
    <View style={{ gap: 10 }}>
      <Row style={{ justifyContent: 'space-between' }}>
        <Heading>{title}</Heading>
        <LinkButton title="All" onPress={() => onChange([])} />
      </Row>
      <Row style={{ gap: 8 }}>
        {all.map((c) => (
          <Chip key={c.id} text={c.name} on={on.includes(c.id)} onPress={() => toggle(c.id)} />
        ))}
      </Row>
      {on.length === 1 && all.length > 1 ? <Text style={{ fontFamily: F.bold, color: C.mist, fontSize: 14 }}>At least one stays on.</Text> : null}
    </View>
  );
}
