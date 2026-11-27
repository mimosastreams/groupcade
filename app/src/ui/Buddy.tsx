// The phone buddy: one of the TV mascot's phone friends, in a player's color. Drawn from
// brand/groupcade-mascots.svg so each player gets a little character.
import { memo } from 'react';
import Svg, { Circle, G, Path, Rect } from 'react-native-svg';
import { C, buddyFor } from './kit';

export const Buddy = memo(function Buddy({ index = 0, size = 48, cheer }: { index?: number; size?: number; cheer?: boolean }) {
  const b = buddyFor(index);
  const cheek = b.body === C.gold ? C.coral : C.gold;
  const ink = C.ink;
  // Arms up when cheering, out to the side otherwise.
  const armL = cheer ? 'M-18 -2L-31 -22' : 'M-19 4L-32 14';
  const armR = cheer ? 'M18 -2L31 -22' : 'M19 4L32 14';
  const handL = cheer ? { x: -32, y: -24 } : { x: -33, y: 16 };
  const handR = cheer ? { x: 32, y: -24 } : { x: 33, y: 16 };
  return (
    <Svg width={size * 0.95} height={size} viewBox="-40 -40 80 88" accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      <G>
        <Path d="M-8 28L-11 44" stroke={ink} strokeWidth={14} strokeLinecap="round" />
        <Path d="M-8 28L-11 44" stroke={b.body} strokeWidth={7} strokeLinecap="round" />
        <Path d="M8 28L11 44" stroke={ink} strokeWidth={14} strokeLinecap="round" />
        <Path d="M8 28L11 44" stroke={b.body} strokeWidth={7} strokeLinecap="round" />
        <Path d={armL} stroke={ink} strokeWidth={14} strokeLinecap="round" />
        <Path d={armL} stroke={b.body} strokeWidth={7} strokeLinecap="round" />
        <Path d={armR} stroke={ink} strokeWidth={14} strokeLinecap="round" />
        <Path d={armR} stroke={b.body} strokeWidth={7} strokeLinecap="round" />
        <Circle cx={handL.x} cy={handL.y} r={5.5} fill={b.body} stroke={ink} strokeWidth={3.5} />
        <Circle cx={handR.x} cy={handR.y} r={5.5} fill={b.body} stroke={ink} strokeWidth={3.5} />
        <Rect x={-20} y={-31} width={40} height={62} rx={9} fill={b.body} stroke={ink} strokeWidth={4} />
        <Rect x={-14} y={-24} width={28} height={40} rx={5} fill={b.face} />
        <G transform="translate(0 -6)">
          <Path d="M-10 -3q3.5 -5 7 0M3 -3q3.5 -5 7 0" fill="none" stroke={ink} strokeWidth={3} strokeLinecap="round" />
          <Path d="M-6 3h12a6 6 0 0 1 -12 0z" fill={ink} />
          <Circle cx={-10} cy={5} r={2.6} fill={cheek} opacity={0.9} />
          <Circle cx={10} cy={5} r={2.6} fill={cheek} opacity={0.9} />
        </G>
        <Path d="M-5 23h10" stroke={ink} strokeWidth={3} strokeLinecap="round" opacity={0.5} />
      </G>
    </Svg>
  );
});
