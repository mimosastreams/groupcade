import { Baloo2_800ExtraBold } from '@expo-google-fonts/baloo-2';
import { Nunito_500Medium, Nunito_700Bold, Nunito_800ExtraBold } from '@expo-google-fonts/nunito';
import { useFonts } from 'expo-font';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { initAccount } from '../lib/account';
import { DialogHost } from '../ui/Dialog';
import { C } from '../ui/kit';

SplashScreen.preventAutoHideAsync().catch(() => {});

export default function RootLayout() {
  const [loaded, error] = useFonts({ Baloo2_800ExtraBold, Nunito_500Medium, Nunito_700Bold, Nunito_800ExtraBold });
  const ready = loaded || !!error; // A font that fails to load falls back to the system font.
  useEffect(() => {
    if (ready) SplashScreen.hideAsync().catch(() => {});
  }, [ready]);
  useEffect(() => {
    initAccount(); // The optional host account, if this phone is signed in.
  }, []);
  if (!ready) return null;

  return (
    <>
      <StatusBar style="dark" />
      {/* Every screen draws its own top bar in the Sticker Book look. */}
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: C.bg } }}>
        <Stack.Screen name="index" />
        <Stack.Screen name="trivia/new" />
        <Stack.Screen name="room/[code]" options={{ gestureEnabled: false }} />
        <Stack.Screen name="play/[code]" />
        <Stack.Screen name="account/index" />
        <Stack.Screen name="account/email" />
        <Stack.Screen name="account/pack/[id]" />
      </Stack>
      <DialogHost />
    </>
  );
}
