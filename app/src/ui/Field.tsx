// A labelled text box in the Sticker Book look.
import { Text, TextInput, View, type TextInputProps } from 'react-native';
import { C, F } from './kit';

export function Field({ label, hint, multiline, style, ...rest }: TextInputProps & { label?: string; hint?: string }) {
  return (
    <View style={{ gap: 6 }}>
      {label ? (
        <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>
          {label}
          {hint ? <Text style={{ fontFamily: F.bold, color: C.mist }}> {hint}</Text> : null}
        </Text>
      ) : null}
      <TextInput
        placeholderTextColor={C.faint}
        multiline={multiline}
        accessibilityLabel={label}
        style={[
          { minHeight: 52, borderRadius: 16, borderWidth: 3, borderColor: C.ink, backgroundColor: C.white, color: C.ink, fontSize: 17, fontFamily: F.bold, paddingHorizontal: 14, paddingVertical: 12 },
          multiline ? { minHeight: 84, textAlignVertical: 'top' } : null,
          style,
        ]}
        {...rest}
      />
    </View>
  );
}
