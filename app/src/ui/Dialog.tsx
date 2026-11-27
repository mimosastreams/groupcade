// In-app pop-ups that stand in for React Native's Alert.alert and Alert.prompt.
// Why: once the TV screen is attached, iOS has two active scenes, and React Native puts its
// native alerts in the first one it finds, which can be the TV. The host then can't see or tap
// them ("Play too", "Home", "End game" seem to do nothing). These pop-ups live in the phone's
// own screen, so they always show up on the phone.
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, TextInput, View } from 'react-native';
import { Button, C, Card, F, H2, P, shadow } from './kit';

type DialogButton = { text: string; style?: 'cancel' | 'destructive' | 'default'; onPress?: () => void };
type Req =
  | { kind: 'alert'; title: string; message?: string; buttons: DialogButton[] }
  | { kind: 'prompt'; title: string; message?: string; initial: string; onSubmit: (text: string) => void };

let show: ((r: Req | null) => void) | null = null;

export const dialog = {
  /** Same shape as Alert.alert(title, message, buttons). */
  alert(title: string, message?: string, buttons?: DialogButton[]) {
    const r: Req = { kind: 'alert', title, message, buttons: buttons && buttons.length ? buttons : [{ text: 'OK' }] };
    if (show) show(r);
  },
  /** Same shape as Alert.prompt(title, message, callback, type, defaultValue). */
  prompt(title: string, message: string, onSubmit: (text: string) => void, _type?: string, initial = '') {
    if (show) show({ kind: 'prompt', title, message, initial, onSubmit });
  },
};

/** Mount once, at the app's root. */
export function DialogHost() {
  const [req, setReq] = useState<Req | null>(null);
  const [text, setText] = useState('');
  useEffect(() => {
    show = (r) => {
      setReq(r);
      if (r && r.kind === 'prompt') setText(r.initial);
    };
    return () => {
      show = null;
    };
  }, []);

  const close = () => setReq(null);
  const cancel = () => {
    if (req?.kind === 'alert') req.buttons.find((b) => b.style === 'cancel')?.onPress?.();
    close();
  };
  const submit = () => {
    if (req?.kind !== 'prompt') return;
    const t = text.trim();
    if (!t) return;
    close();
    req.onSubmit(t);
  };

  return (
    <Modal visible={!!req} transparent animationType="fade" onRequestClose={cancel}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <Pressable onPress={cancel} style={{ flex: 1, backgroundColor: 'rgba(27,31,59,0.55)', justifyContent: 'center', padding: 20 }}>
          <Pressable onPress={() => {}}>
            {req ? (
              <Card lifted style={[{ borderRadius: 26, padding: 18, gap: 12 }, shadow(6)]}>
                <H2>{req.title}</H2>
                {req.message ? <P muted small>{req.message}</P> : null}
                {req.kind === 'prompt' ? (
                  <TextInput
                    value={text}
                    onChangeText={(t) => setText(t.slice(0, 16))}
                    placeholder="Your name"
                    placeholderTextColor={C.faint}
                    autoFocus
                    maxLength={16}
                    returnKeyType="go"
                    onSubmitEditing={submit}
                    style={{ height: 54, borderRadius: 16, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, color: C.ink, fontSize: 20, fontFamily: F.heavy, paddingHorizontal: 14 }}
                  />
                ) : null}
                <View style={{ flexDirection: 'row', gap: 10 }}>
                  {req.kind === 'prompt' ? (
                    <>
                      <View style={{ flex: 1 }}>
                        <Button kind="ghost" title="Cancel" onPress={close} />
                      </View>
                      <View style={{ flex: 1 }}>
                        <Button kind="go" title="Join" onPress={submit} disabled={!text.trim()} />
                      </View>
                    </>
                  ) : (
                    req.buttons.map((b) => (
                      <View key={b.text} style={{ flex: 1 }}>
                        <Button
                          kind={b.style === 'cancel' ? 'ghost' : b.style === 'destructive' ? 'danger' : 'go'}
                          title={b.text}
                          onPress={() => {
                            close();
                            b.onPress?.();
                          }}
                        />
                      </View>
                    ))
                  )}
                </View>
              </Card>
            ) : null}
          </Pressable>
        </Pressable>
      </KeyboardAvoidingView>
    </Modal>
  );
}
