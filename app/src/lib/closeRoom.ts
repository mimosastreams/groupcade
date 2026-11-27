// Closing a room from the phone: stop any game in progress, so players land on the results
// screen, then forget the room here and send the TV back to its idle screen. The site drops
// the room on its own 2 hours after its last activity.
import { setTvRoomUrl } from '../../modules/groupcade-tv';
import { api } from './api';
import { setCastRoom } from './cast';
import { store, type SavedRoom } from './store';

export async function closeRoom(room: SavedRoom) {
  try {
    const st = await api.state(room.code, room.host);
    if (st.host && ['cat', 'q', 'rev'].includes(st.phase)) await api.host(room.code, room.host, 'end');
  } catch {
    // Offline or already gone: forgetting it here is what matters.
  }
  await store.savePlayerToken(room.code, null);
  await store.clearRoom();
  setTvRoomUrl(null);
  setCastRoom(null);
}
