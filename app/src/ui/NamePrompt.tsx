// "Your name" box that works on Android and iPhone (Alert.prompt is iPhone only).
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, TextInput, View } from 'react-native';
import { Button, C, Card, H2, P } from './kit';

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
        <Pressable onPress={onCancel} style={{ flex: 1, backgroundColor: 'rgba(0,0,0,0.6)', justifyContent: 'center', padding: 20 }}>
          <Pressable onPress={() => {}}>
            <Card>
              <H2>{title}</H2>
              <P muted small>{message}</P>
              <TextInput
                value={name}
                onChangeText={(t) => setName(t.slice(0, 16))}
                placeholder="Your name"
                placeholderTextColor={C.mist}
                autoFocus
                maxLength={16}
                returnKeyType="go"
                onSubmitEditing={go}
                style={{ height: 52, borderRadius: 12, borderWidth: 1, borderColor: C.line, backgroundColor: C.ink, color: C.white, fontSize: 18, fontWeight: '700', paddingHorizontal: 14 }}
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
