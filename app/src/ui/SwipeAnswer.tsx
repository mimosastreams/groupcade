// Slide an answer to the right to lock it in, like the web player. A tap only gives a little
// nudge and lights up the hint, so a stray touch never picks an answer.
import * as Haptics from 'expo-haptics';
import { useRef, useState, type ReactNode } from 'react';
import { Animated, PanResponder, Pressable, Text, View, type StyleProp, type ViewStyle } from 'react-native';
import Svg, { Path } from 'react-native-svg';
import { C, F, shadow } from './kit';

const LOCK_AT = 0.4; // Share of the row's width to slide before it locks in.

export function SwipeAnswer({
  enabled,
  onLock,
  onTap,
  style,
  label,
  children,
}: {
  enabled: boolean;
  onLock: () => void;
  onTap?: () => void;
  style?: StyleProp<ViewStyle>;
  label?: string;
  children: ReactNode;
}) {
  const x = useRef(new Animated.Value(0)).current;
  const width = useRef(1);
  const armed = useRef(false);
  const [ready, setReady] = useState(false);
  const live = useRef({ enabled, onLock });
  live.current = { enabled, onLock };

  const settle = () => {
    Animated.spring(x, { toValue: 0, useNativeDriver: true, bounciness: 6 }).start();
    armed.current = false;
    setReady(false);
  };

  const pan = useRef(
    PanResponder.create({
      // Only take over a clearly sideways drag, so scrolling the screen still works.
      onMoveShouldSetPanResponderCapture: (_, g) => live.current.enabled && Math.abs(g.dx) > 8 && Math.abs(g.dx) > Math.abs(g.dy) * 1.5,
      onPanResponderTerminationRequest: () => false,
      onPanResponderMove: (_, g) => {
        const dx = Math.max(0, g.dx);
        x.setValue(Math.min(dx, width.current * 0.55));
        const now = dx >= width.current * LOCK_AT;
        if (now !== armed.current) {
          armed.current = now;
          setReady(now);
          if (now) Haptics.selectionAsync().catch(() => {});
        }
      },
      onPanResponderRelease: () => {
        const go = armed.current;
        settle();
        if (go && live.current.enabled) live.current.onLock();
      },
      onPanResponderTerminate: settle,
    })
  ).current;

  const nudge = () => {
    onTap?.();
    Animated.sequence([
      Animated.timing(x, { toValue: 18, duration: 120, useNativeDriver: true }),
      Animated.spring(x, { toValue: 0, useNativeDriver: true, bounciness: 12 }),
    ]).start();
  };

  return (
    <View onLayout={(e) => (width.current = Math.max(1, e.nativeEvent.layout.width))} {...(enabled ? pan.panHandlers : {})}>
      <Animated.View style={{ transform: [{ translateX: x }] }}>
        <Pressable
          disabled={!enabled}
          onPress={nudge}
          accessibilityRole={enabled ? 'button' : undefined}
          accessibilityLabel={label}
          accessibilityHint={enabled ? 'Slide right to lock in this answer' : undefined}
          accessibilityActions={enabled ? [{ name: 'activate', label: 'Lock in' }] : undefined}
          onAccessibilityAction={() => enabled && onLock()}
          style={[style, { flexDirection: 'row', alignItems: 'center' }, ready && [{ backgroundColor: C.gold }, shadow(3)]]}
        >
          {children}
          {enabled ? (
            <Text style={{ fontFamily: F.heavy, fontSize: 14, color: C.ink, opacity: ready ? 1 : 0.7, marginLeft: 8 }}>{ready ? 'Let go' : 'Slide right'}</Text>
          ) : null}
          {enabled ? (
            <Svg width={22} height={22} viewBox="0 0 24 24" fill="none" stroke={C.ink} strokeWidth={3} strokeLinecap="round" strokeLinejoin="round" style={{ opacity: ready ? 1 : 0.4 }}>
              <Path d="M9 5l7 7-7 7" />
            </Svg>
          ) : null}
        </Pressable>
      </Animated.View>
    </View>
  );
}
