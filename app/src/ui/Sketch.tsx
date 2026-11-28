// Scribble Squad sketches: show one, and a drawing pad for the host when they play too.
// Sketches are strokes on a 1000 × 750 grid, points as "x,y,x,y…", the same as the web client.
import * as Haptics from 'expo-haptics';
import { useMemo, useRef, useState } from 'react';
import { PanResponder, Pressable, Text, View, type LayoutChangeEvent } from 'react-native';
import Svg, { Path, Rect } from 'react-native-svg';
import type { Stroke } from '../lib/api';
import { C, F, useScrollLock } from './kit';

export const GRID_W = 1000;
export const GRID_H = 750;

function pathOf(p: string | number[]) {
  const a = typeof p === 'string' ? p.split(',').map(Number) : p;
  if (a.length < 2) return '';
  let d = `M${a[0]} ${a[1]}`;
  if (a.length === 2) return d + `L${a[0] + 0.1} ${a[1]}`; // A dot.
  for (let i = 2; i + 1 < a.length; i += 2) d += `L${a[i]} ${a[i + 1]}`;
  return d;
}

export function Sketch({ strokes, label, live }: { strokes: (Stroke | { c: string; w: number; p: number[] })[]; label?: string; live?: boolean }) {
  return (
    <View
      accessible
      accessibilityLabel={label || 'A sketch'}
      style={{ width: '100%', aspectRatio: GRID_W / GRID_H, backgroundColor: C.white, borderWidth: 3, borderColor: C.ink, borderRadius: 18, overflow: 'hidden' }}
      pointerEvents={live ? 'none' : 'auto'}
    >
      <Svg width="100%" height="100%" viewBox={`0 0 ${GRID_W} ${GRID_H}`}>
        <Rect x={0} y={0} width={GRID_W} height={GRID_H} fill="#FFFFFF" />
        {strokes.map((s, i) => (
          <Path key={i} d={pathOf(s.p)} stroke={s.c} strokeWidth={s.w} strokeLinecap="round" strokeLinejoin="round" fill="none" />
        ))}
      </Svg>
    </View>
  );
}

type Live = { c: string; w: number; p: number[] };

/** The host's drawing pad: colors, pen sizes, undo, clear. Strokes go up through onChange. */
export function DrawPad({ colors, widths, maxPoints, strokes, onChange, disabled }: { colors: string[]; widths: number[]; maxPoints: number; strokes: Live[]; onChange: (s: Live[]) => void; disabled?: boolean }) {
  const [color, setColor] = useState(colors[0] || '#1B1F3B');
  const [width, setWidth] = useState(widths[1] || 10);
  const box = useRef({ w: 1, h: 1 });
  const cur = useRef<Live | null>(null);
  const [, redraw] = useState(0);
  const lockScroll = useScrollLock();
  const state = useRef({ strokes, onChange, color, width, disabled, maxPoints, lockScroll });
  state.current = { strokes, onChange, color, width, disabled, maxPoints, lockScroll };

  const count = (list: Live[]) => list.reduce((n, s) => n + s.p.length / 2, 0);

  const responder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => !state.current.disabled,
        onStartShouldSetPanResponderCapture: () => !state.current.disabled,
        onMoveShouldSetPanResponder: () => !state.current.disabled,
        onMoveShouldSetPanResponderCapture: () => !state.current.disabled,
        onPanResponderTerminationRequest: () => false,
        onShouldBlockNativeResponder: () => true,
        onPanResponderGrant: (e) => {
          state.current.lockScroll(true); // A fast downstroke must not scroll the page instead.
          const { locationX, locationY } = e.nativeEvent;
          const x = Math.round((locationX / box.current.w) * GRID_W);
          const y = Math.round((locationY / box.current.h) * GRID_H);
          cur.current = { c: state.current.color, w: state.current.width, p: [clamp(x, GRID_W), clamp(y, GRID_H)] };
          redraw((n) => n + 1);
        },
        onPanResponderMove: (e) => {
          const s = cur.current;
          if (!s) return;
          if (count(state.current.strokes) + s.p.length / 2 >= state.current.maxPoints) return;
          const { locationX, locationY } = e.nativeEvent;
          const x = clamp(Math.round((locationX / box.current.w) * GRID_W), GRID_W);
          const y = clamp(Math.round((locationY / box.current.h) * GRID_H), GRID_H);
          const lx = s.p[s.p.length - 2];
          const ly = s.p[s.p.length - 1];
          if (Math.abs(x - lx) + Math.abs(y - ly) < 8) return; // Keep sketches small.
          s.p.push(x, y);
          redraw((n) => n + 1);
        },
        onPanResponderRelease: () => finish(),
        onPanResponderTerminate: () => finish(),
      }),
    []
  );

  function finish() {
    state.current.lockScroll(false);
    const s = cur.current;
    cur.current = null;
    if (s && s.p.length >= 2) state.current.onChange([...state.current.strokes, s]);
    redraw((n) => n + 1);
  }

  const onLayout = (e: LayoutChangeEvent) => {
    const { width: w, height: h } = e.nativeEvent.layout;
    box.current = { w: w || 1, h: h || 1 };
  };

  const full = count(strokes) >= maxPoints;
  const pick = (f: () => void) => {
    Haptics.selectionAsync().catch(() => {});
    f();
  };
  return (
    <View style={{ gap: 10 }}>
      <View onLayout={onLayout} {...responder.panHandlers} style={{ opacity: disabled ? 0.6 : 1 }}>
        <Sketch strokes={cur.current ? [...strokes, cur.current] : strokes} label="Your drawing pad" live />
      </View>
      {full ? <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.out }}>That's as much as a sketch can hold. Undo a line to keep going.</Text> : null}
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {colors.map((c) => (
          <Pressable
            key={c}
            accessibilityRole="button"
            accessibilityLabel={c === '#FFFFFF' ? 'Eraser' : `Color ${c}`}
            accessibilityState={{ selected: c === color }}
            onPress={() => pick(() => setColor(c))}
            style={{ width: 36, height: 36, borderRadius: 18, backgroundColor: c, borderWidth: c === color ? 4 : 2, borderColor: c === color ? C.accent : C.ink, alignItems: 'center', justifyContent: 'center' }}
          >
            {c === '#FFFFFF' ? <Text style={{ fontSize: 14 }}>⌫</Text> : null}
          </Pressable>
        ))}
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
        {widths.map((w) => (
          <Pressable
            key={w}
            accessibilityRole="button"
            accessibilityLabel={`Pen size ${w}`}
            accessibilityState={{ selected: w === width }}
            onPress={() => pick(() => setWidth(w))}
            style={{ width: 44, height: 44, borderRadius: 22, borderWidth: 3, borderColor: w === width ? C.accent : C.ink, backgroundColor: C.white, alignItems: 'center', justifyContent: 'center' }}
          >
            <View style={{ width: Math.max(4, w / 2), height: Math.max(4, w / 2), borderRadius: 20, backgroundColor: color === '#FFFFFF' ? C.ink : color }} />
          </Pressable>
        ))}
        <View style={{ flex: 1 }} />
        <SmallLink title="Undo" disabled={!strokes.length || disabled} onPress={() => pick(() => onChange(strokes.slice(0, -1)))} />
        <SmallLink title="Clear" disabled={!strokes.length || disabled} onPress={() => pick(() => onChange([]))} />
      </View>
    </View>
  );
}

const clamp = (n: number, max: number) => Math.max(0, Math.min(max - 1, n));

function SmallLink({ title, onPress, disabled }: { title: string; onPress: () => void; disabled?: boolean }) {
  return (
    <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} hitSlop={6} style={{ minHeight: 44, paddingHorizontal: 6, justifyContent: 'center', opacity: disabled ? 0.4 : 1 }}>
      <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.accent }}>{title}</Text>
    </Pressable>
  );
}
