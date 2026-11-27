// Small UI kit in the Groupcade palette (matches the web client's CSS tokens).
import * as Haptics from 'expo-haptics';
import type { ReactNode } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export const C = {
  bg: '#15122B',
  card: '#1E1A3A',
  raised: '#2A2550',
  ink: '#0E0C1F',
  mist: '#ABA7CC',
  white: '#FFFFFF',
  accent: '#FF4FA3',
  violet: '#7C5CFF',
  gold: '#FFD43B',
  right: '#5BE38B',
  out: '#FF4D5E',
  line: 'rgba(255,255,255,0.1)',
  answers: ['#FF8A3D', '#3FC5F0', '#FFD43B', '#9B7BFF'],
};

export function Screen({ children, footer }: { children: ReactNode; footer?: ReactNode }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={{ flex: 1, backgroundColor: C.bg }}>
      <ScrollView contentContainerStyle={{ padding: 16, gap: 14, paddingBottom: footer ? 16 : insets.bottom + 24 }} keyboardShouldPersistTaps="handled">
        {children}
      </ScrollView>
      {footer ? (
        <View style={{ padding: 12, paddingBottom: insets.bottom + 12, borderTopWidth: 1, borderTopColor: C.line, backgroundColor: C.ink, gap: 8 }}>{footer}</View>
      ) : null}
    </View>
  );
}

export function Card({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[s.card, style]}>{children}</View>;
}

export function H1({ children }: { children: ReactNode }) {
  return <Text style={s.h1}>{children}</Text>;
}

export function H2({ children }: { children: ReactNode }) {
  return <Text style={s.h2}>{children}</Text>;
}

export function P({ children, muted, small, center, style }: { children: ReactNode; muted?: boolean; small?: boolean; center?: boolean; style?: object }) {
  return <Text style={[s.p, muted && { color: C.mist }, small && { fontSize: 14 }, center && { textAlign: 'center' }, style]}>{children}</Text>;
}

export function Label({ children }: { children: ReactNode }) {
  return <Text style={s.label}>{children}</Text>;
}

type ButtonProps = {
  title: string;
  onPress: () => void | Promise<unknown>;
  kind?: 'go' | 'plain' | 'ghost' | 'danger';
  disabled?: boolean;
  busy?: boolean;
  small?: boolean;
};

export function Button({ title, onPress, kind = 'plain', disabled, busy, small }: ButtonProps) {
  const bg = kind === 'go' ? C.accent : kind === 'ghost' ? 'transparent' : kind === 'danger' ? 'rgba(255,77,94,0.14)' : C.raised;
  const fg = kind === 'go' ? C.ink : kind === 'danger' ? C.out : C.white;
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled || busy}
      onPress={() => {
        Haptics.selectionAsync().catch(() => {});
        onPress();
      }}
      style={({ pressed }) => [
        s.btn,
        small && { height: 40, paddingHorizontal: 14 },
        { backgroundColor: bg, borderColor: kind === 'go' ? C.accent : C.line, opacity: disabled ? 0.45 : pressed ? 0.8 : 1 },
      ]}
    >
      {busy ? <ActivityIndicator color={fg} /> : <Text style={[s.btnText, small && { fontSize: 15 }, { color: fg }]}>{title}</Text>}
    </Pressable>
  );
}

/** Segmented choice, like the web client's .seg rows. */
export function Seg<T extends string | number>({ options, value, onChange, label }: { options: { value: T; label: string }[]; value: T; onChange: (v: T) => void; label?: (v: T) => string }) {
  return (
    <View style={s.seg}>
      {options.map((o) => {
        const on = o.value === value;
        return (
          <Pressable
            key={String(o.value)}
            accessibilityRole="button"
            accessibilityState={{ selected: on }}
            onPress={() => {
              Haptics.selectionAsync().catch(() => {});
              onChange(o.value);
            }}
            style={[s.segBtn, on && s.segOn]}
          >
            <Text style={[s.segText, on && { color: C.accent }]}>{label ? label(o.value) : o.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export function Chip({ text, on, onPress, tone }: { text: string; on?: boolean; onPress?: () => void; tone?: 'in' | 'out' | 'me' }) {
  const color = tone === 'in' ? C.right : tone === 'out' ? C.mist : on ? C.accent : C.white;
  return (
    <Pressable
      disabled={!onPress}
      onPress={onPress}
      style={[s.chip, on && s.segOn, tone === 'in' && { borderColor: 'rgba(91,227,139,0.6)' }, tone === 'me' && { borderColor: C.accent }, tone === 'out' && { opacity: 0.45 }]}
    >
      <Text style={[s.chipText, { color }, tone === 'out' && { textDecorationLine: 'line-through' }]}>{text}</Text>
    </Pressable>
  );
}

export function Row({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[{ flexDirection: 'row', alignItems: 'center', gap: 10, flexWrap: 'wrap' }, style]}>{children}</View>;
}

export function Banner({ children, tone = 'info' }: { children: ReactNode; tone?: 'info' | 'good' | 'bad' }) {
  const c = tone === 'good' ? C.right : tone === 'bad' ? C.out : C.gold;
  return (
    <View style={[s.banner, { borderColor: c + '88', backgroundColor: c + '18' }]}>
      <Text style={{ color: c, fontWeight: '800', fontSize: 16 }}>{children}</Text>
    </View>
  );
}

export const s = StyleSheet.create({
  card: { backgroundColor: C.card, borderColor: C.line, borderWidth: 1, borderRadius: 14, padding: 16, gap: 10 },
  h1: { color: C.white, fontSize: 30, fontWeight: '900', letterSpacing: 0.2 },
  h2: { color: C.white, fontSize: 20, fontWeight: '800' },
  p: { color: C.white, fontSize: 16, lineHeight: 22 },
  label: { color: C.mist, fontSize: 13, fontWeight: '700', textTransform: 'uppercase', letterSpacing: 0.8 },
  btn: { height: 52, borderRadius: 12, borderWidth: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 20 },
  btnText: { fontSize: 17, fontWeight: '800' },
  seg: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  segBtn: { minHeight: 40, paddingHorizontal: 14, borderRadius: 10, borderWidth: 1, borderColor: C.line, backgroundColor: C.ink, alignItems: 'center', justifyContent: 'center' },
  segOn: { borderColor: C.accent, backgroundColor: 'rgba(255,79,163,0.12)' },
  segText: { color: C.mist, fontWeight: '800', fontSize: 15 },
  chip: { borderWidth: 1, borderColor: C.line, backgroundColor: C.ink, borderRadius: 999, paddingVertical: 7, paddingHorizontal: 13 },
  chipText: { fontWeight: '700', fontSize: 15 },
  banner: { borderWidth: 1, borderRadius: 12, padding: 14 },
  code: { color: C.white, fontSize: 64, fontWeight: '900', letterSpacing: 10, fontVariant: ['tabular-nums'], textAlign: 'center' },
});
