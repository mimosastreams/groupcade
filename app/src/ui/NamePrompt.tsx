// "Your name" box that works on Android and iPhone (Alert.prompt is iPhone only).
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, TextInput, View } from 'react-native';
import { Button, C, Card, F, H2, P, shadow } from './kit';

export function NamePrompt({
  open,
  title,
  message,
  initial,
  onCancel,
  onSubmit,
}: {
  open: boolean;
  title: string;
  message: string;
  initial: string;
  onCancel: () => void;
  onSubmit: (name: string) => void;
}) {
  const [name, setName] = useState(initial);
  useEffect(() => {
    if (open) setName(initial);
  }, [open, initial]);
  const go = () => {
    const n = name.trim();
    if (n) onSubmit(n);
  };
  return (
    <Modal visible={open} transparent animationType="fade" onRequestClose={onCancel}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <Pressable onPress={onCancel} style={{ flex: 1, backgroundColor: 'rgba(27,31,59,0.55)', justifyContent: 'center', padding: 20 }}>
          <Pressable onPress={() => {}}>
            <Card lifted style={[{ borderRadius: 26, padding: 18, gap: 12 }, shadow(6)]}>
              <H2>{title}</H2>
              <P muted small>{message}</P>
              <TextInput
                value={name}
                onChangeText={(t) => setName(t.slice(0, 16))}
                placeholder="Your name"
                placeholderTextColor={C.faint}
                autoFocus
                maxLength={16}
                returnKeyType="go"
                onSubmitEditing={go}
                style={{ height: 54, borderRadius: 16, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, color: C.ink, fontSize: 20, fontFamily: F.heavy, paddingHorizontal: 14 }}
              />
              <View style={{ flexDirection: 'row', gap: 10 }}>
                <View style={{ flex: 1 }}>
                  <Button kind="ghost" title="Cancel" onPress={onCancel} />
                </View>
                <View style={{ flex: 1 }}>
                  <Button kind="go" title="Join" onPress={go} disabled={!name.trim()} />
                </View>
              </View>
            </Card>
          </Pressable>
        </Pressable>
      </KeyboardAvoidingView>
    </Modal>
  );
}
