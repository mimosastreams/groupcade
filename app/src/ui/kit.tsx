// Small UI kit in the Groupcade "Sticker Book" look: cream paper, navy ink outlines, hard offset
// shadows, chunky Baloo 2 headings over Nunito. Matches the mockups and brand/README.md.
import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import type { ReactNode } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View, type StyleProp, type TextStyle, type ViewStyle } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

export const C = {
  bg: '#FFFDF7', // Cream paper.
  card: '#FFFFFF',
  ink: '#1B1F3B', // Navy: outlines, shadows and main text.
  text: '#1B1F3B',
  mist: '#3A4366', // Secondary text (passes 4.5:1 on cream and the tints).
  faint: '#8A93AE',
  soft: '#C4C9DA', // Light outline for "off" things.
  white: '#FFFFFF',
  accent: '#4A3AFF', // Indigo.
  coral: '#FF6B4A',
  gold: '#FFC93C',
  mint: '#3DD68C',
  blue: '#5BB8F5',
  sky: '#CFEBFF',
  // Light tints for cards and players.
  coralTint: '#FFE3DA',
  goldTint: '#FFF4CF',
  mintTint: '#DDF8EA',
  pinkTint: '#FFE0EE',
  right: '#12784A', // Green text that reads on light backgrounds.
  out: '#B83A1E', // Red text that reads on light backgrounds.
  line: '#1B1F3B',
  onDark: '#CBD3F0', // Secondary text on navy.
  answers: ['#FF6B4A', '#FFC93C', '#3DD68C', '#5BB8F5'],
};

/** Each player gets a phone buddy color and a card tint, in join order. */
export const BUDDIES = [
  { body: C.coral, face: C.coralTint, card: C.coralTint },
  { body: C.gold, face: C.goldTint, card: C.goldTint },
  { body: C.mint, face: C.mintTint, card: C.mintTint },
  { body: C.blue, face: '#E4F4FF', card: C.sky },
  { body: '#FF7FB6', face: C.pinkTint, card: C.pinkTint },
  { body: '#8C7CFF', face: '#ECE9FF', card: '#ECE9FF' },
];
export const buddyFor = (i: number) => BUDDIES[((i % BUDDIES.length) + BUDDIES.length) % BUDDIES.length];

export const F = {
  display: 'Baloo2_800ExtraBold',
  body: 'Nunito_500Medium',
  bold: 'Nunito_700Bold',
  heavy: 'Nunito_800ExtraBold',
};

/** Hard offset shadow, the sticker look. */
export const shadow = (n = 5): ViewStyle => ({
  shadowColor: C.ink,
  shadowOffset: { width: n, height: n },
  shadowOpacity: 1,
  shadowRadius: 0,
  elevation: 0,
});

export function Screen({ children, footer, bg = C.bg, top }: { children: ReactNode; footer?: ReactNode; bg?: string; top?: ReactNode }) {
  const insets = useSafeAreaInsets();
  return (
    <View style={{ flex: 1, backgroundColor: bg }}>
      <View style={{ paddingTop: insets.top }}>{top}</View>
      <ScrollView contentContainerStyle={{ padding: 20, paddingTop: top ? 8 : 16, gap: 16, paddingBottom: footer ? 20 : insets.bottom + 28 }} keyboardShouldPersistTaps="handled">
        {children}
      </ScrollView>
      {footer ? <View style={{ paddingHorizontal: 20, paddingTop: 14, paddingBottom: insets.bottom + 14, gap: 8 }}>{footer}</View> : null}
    </View>
  );
}

/** Top bar: a round back button, a title and something on the right. */
export function TopBar({ title, onBack, right, backLabel = 'Back' }: { title?: string; onBack?: () => void; right?: ReactNode; backLabel?: string }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 20, paddingTop: 10, paddingBottom: 6, minHeight: 60 }}>
      {onBack !== undefined ? <RoundButton label={backLabel} onPress={onBack ?? (() => router.back())} icon="back" /> : null}
      {title ? (
        <Text style={{ flex: 1, fontFamily: F.display, fontSize: 26, lineHeight: 32, color: C.text }} numberOfLines={1}>
          {title}
        </Text>
      ) : (
        <View style={{ flex: 1 }} />
      )}
      {right}
    </View>
  );
}

export function RoundButton({ label, onPress, icon }: { label: string; onPress: () => void; icon: 'back' | 'close' }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      hitSlop={6}
      onPress={onPress}
      style={({ pressed }) => [{ width: 44, height: 44, borderRadius: 22, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, alignItems: 'center', justifyContent: 'center', opacity: pressed ? 0.7 : 1 }]}
    >
      <Svg width={18} height={18} viewBox="0 0 24 24" fill="none" stroke={C.ink} strokeWidth={3} strokeLinecap="round" strokeLinejoin="round">
        <Path d={icon === 'back' ? 'M15 5l-7 7 7 7' : 'M6 6l12 12M18 6L6 18'} />
      </Svg>
    </Pressable>
  );
}

export function Card({ children, style, tint, lifted, tilt }: { children: ReactNode; style?: StyleProp<ViewStyle>; tint?: string; lifted?: boolean; tilt?: number }) {
  return <View style={[s.card, tint ? { backgroundColor: tint } : null, lifted ? shadow(5) : null, tilt ? { transform: [{ rotate: `${tilt}deg` }] } : null, style]}>{children}</View>;
}

export function H1({ children, style }: { children: ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[s.h1, style]}>{children}</Text>;
}

export function H2({ children, style }: { children: ReactNode; style?: StyleProp<TextStyle> }) {
  return <Text style={[s.h2, style]}>{children}</Text>;
}

export function P({ children, muted, small, center, style }: { children: ReactNode; muted?: boolean; small?: boolean; center?: boolean; style?: StyleProp<TextStyle> }) {
  return <Text style={[s.p, muted && { color: C.mist }, small && { fontSize: 14, lineHeight: 19 }, center && { textAlign: 'center' }, style]}>{children}</Text>;
}

export function Label({ children }: { children: ReactNode }) {
  return <Text style={s.label}>{children}</Text>;
}

type ButtonProps = {
  title: string;
  onPress: () => void | Promise<unknown>;
  /** go: indigo, the main action. hot: coral, the big moment (Join, Start). plain: white. ghost: no fill. danger: red text. dark: white on navy. */
  kind?: 'go' | 'hot' | 'plain' | 'ghost' | 'danger' | 'dark';
  disabled?: boolean;
  busy?: boolean;
  small?: boolean;
  big?: boolean;
};

export function Button({ title, onPress, kind = 'plain', disabled, busy, small, big }: ButtonProps) {
  const bg = kind === 'go' ? C.accent : kind === 'hot' ? C.coral : kind === 'dark' ? C.ink : kind === 'ghost' || kind === 'danger' ? 'transparent' : C.white;
  const fg = kind === 'go' || kind === 'dark' ? C.white : kind === 'danger' ? C.out : C.ink;
  const border = kind === 'ghost' || kind === 'danger' ? 'transparent' : C.ink;
  const lift = (kind === 'go' || kind === 'hot') && !disabled;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!(disabled || busy), busy: !!busy }}
      disabled={disabled || busy}
      onPress={() => {
        Haptics.selectionAsync().catch(() => {});
        onPress();
      }}
      style={({ pressed }) => [
        s.btn,
        small && { height: 44, paddingHorizontal: 16 },
        big && { height: 60 },
        { backgroundColor: bg, borderColor: border, opacity: disabled ? 0.45 : 1 },
        lift && !pressed ? shadow(small ? 3 : 4) : null,
        pressed && lift ? { transform: [{ translateX: 3 }, { translateY: 3 }] } : null,
        pressed && !lift ? { opacity: 0.7 } : null,
      ]}
    >
      {busy ? (
        <ActivityIndicator color={fg} />
      ) : (
        <Text style={[s.btnText, small && { fontSize: 16, fontFamily: F.heavy }, big && { fontSize: 26 }, { color: fg }]} numberOfLines={1}>
          {title}
        </Text>
      )}
    </Pressable>
  );
}

/** A choice of a few options, as sticker buttons. */
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
            <Text style={[s.segText, on && { color: C.ink }]}>{label ? label(o.value) : o.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** A pill: categories (on/off) and names. */
export function Chip({ text, on, onPress, tone, tint }: { text: string; on?: boolean; onPress?: () => void; tone?: 'in' | 'out' | 'me'; tint?: string }) {
  const off = onPress && !on && !tone;
  return (
    <Pressable
      disabled={!onPress}
      accessibilityRole={onPress ? 'button' : undefined}
      accessibilityState={onPress ? { selected: !!on } : undefined}
      onPress={onPress}
      style={[
        s.chip,
        on && { backgroundColor: C.mintTint },
        off && { borderColor: C.soft },
        tone === 'in' && { backgroundColor: C.mintTint },
        tone === 'me' && { backgroundColor: C.goldTint },
        tone === 'out' && { opacity: 0.45 },
        tint ? { backgroundColor: tint } : null,
      ]}
    >
      {on ? (
        <Svg width={14} height={14} viewBox="0 0 24 24" fill="none" stroke={C.right} strokeWidth={4} strokeLinecap="round" strokeLinejoin="round">
          <Path d="M4 12l5 5L20 6" />
        </Svg>
      ) : null}
      <Text style={[s.chipText, off && { color: C.mist, fontFamily: F.bold }, tone === 'out' && { textDecorationLine: 'line-through' }]}>{text}</Text>
    </Pressable>
  );
}

export function Row({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  return <View style={[{ flexDirection: 'row', alignItems: 'center', gap: 10, flexWrap: 'wrap' }, style]}>{children}</View>;
}

export function Banner({ children, tone = 'info' }: { children: ReactNode; tone?: 'info' | 'good' | 'bad' }) {
  const bg = tone === 'good' ? C.mintTint : tone === 'bad' ? C.coralTint : C.goldTint;
  return (
    <View style={[s.banner, { backgroundColor: bg }]}>
      <Text style={{ color: C.ink, fontFamily: F.heavy, fontSize: 16 }}>{children}</Text>
    </View>
  );
}

/** A small rounded tag, like "Round 4 of 10" or a category. */
export function Tag({ text, bg = C.white, style }: { text: string; bg?: string; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[{ alignSelf: 'flex-start', backgroundColor: bg, borderWidth: 3, borderColor: C.ink, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 3 }, style]}>
      <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.ink }}>{text}</Text>
    </View>
  );
}

export const s = StyleSheet.create({
  card: { backgroundColor: C.card, borderColor: C.ink, borderWidth: 3, borderRadius: 22, padding: 16, gap: 10 },
  h1: { color: C.text, fontSize: 34, lineHeight: 40, fontFamily: F.display },
  h2: { color: C.text, fontSize: 24, lineHeight: 30, fontFamily: F.display },
  p: { color: C.text, fontSize: 16, lineHeight: 22, fontFamily: F.body },
  label: { color: C.text, fontSize: 19, lineHeight: 24, fontFamily: F.display },
  btn: { height: 54, borderRadius: 999, borderWidth: 3, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 20 },
  btnText: { fontSize: 22, fontFamily: F.display, lineHeight: 28 },
  seg: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  segBtn: { minHeight: 44, paddingHorizontal: 14, borderRadius: 16, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, alignItems: 'center', justifyContent: 'center' },
  segOn: { backgroundColor: C.gold },
  segText: { color: C.mist, fontFamily: F.heavy, fontSize: 15 },
  chip: { minHeight: 44, flexDirection: 'row', alignItems: 'center', gap: 6, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, borderRadius: 999, paddingVertical: 6, paddingHorizontal: 14 },
  chipText: { fontFamily: F.heavy, fontSize: 15, color: C.ink },
  banner: { borderWidth: 3, borderColor: C.ink, borderRadius: 18, padding: 14 },
  code: { color: C.text, fontSize: 56, fontFamily: F.display, letterSpacing: 6, textAlign: 'center' },
});
