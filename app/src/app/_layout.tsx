import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { C } from '../ui/kit';

export default function RootLayout() {
  return (
    <>
      <StatusBar style="light" />
      <Stack
        screenOptions={{
          headerStyle: { backgroundColor: C.ink },
          headerTintColor: C.white,
          headerTitleStyle: { fontWeight: '800' },
          contentStyle: { backgroundColor: C.bg },
          headerBackButtonDisplayMode: 'minimal',
        }}
      >
        <Stack.Screen name="index" options={{ headerShown: false }} />
        <Stack.Screen name="trivia/new" options={{ title: 'New Trivia game' }} />
        <Stack.Screen name="room/[code]" options={{ title: 'Host', headerBackVisible: false, gestureEnabled: false }} />
        <Stack.Screen name="play/[code]" options={{ title: 'Playing' }} />
      </Stack>
    </>
  );
}
