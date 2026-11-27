import { SvgXml } from 'react-native-svg';

// Same mark as plugin/groupcade/assets/groupcade-logo.svg.
const LOGO = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" role="img" aria-label="Groupcade"><defs><linearGradient id="gcg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7C5CFF"/><stop offset="1" stop-color="#FF4FA3"/></linearGradient></defs><rect x="2" y="2" width="92" height="92" rx="24" fill="url(#gcg)"/><path d="M66 34a24 24 0 1 0 4 22H50" fill="none" stroke="#fff" stroke-width="11" stroke-linecap="round" stroke-linejoin="round"/><circle cx="70" cy="28" r="6" fill="#FFD43B"/></svg>`;

export function Logo({ size = 56 }: { size?: number }) {
  return <SvgXml xml={LOGO} width={size} height={size} />;
}
