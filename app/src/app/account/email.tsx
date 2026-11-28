// Sign in with an emailed 6-digit code: type your email, then the code.
import { router } from 'expo-router';
import { useState } from 'react';
import { emailStart, emailVerify } from '../../lib/account';
import { Field } from '../../ui/Field';
import { Banner, Button, Card, H2, P, Screen, TopBar } from '../../ui/kit';

export default function EmailSignIn() {
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  const send = async () => {
    setErr('');
    setBusy(true);
    try {
      await emailStart(email);
      setSent(true);
    } catch (e) {
      setErr((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const verify = async () => {
    setErr('');
    setBusy(true);
    try {
      await emailVerify(email, code);
      router.back(); // The account screen now shows the signed-in host.
    } catch (e) {
      setErr((e as Error).message);
      setBusy(false);
    }
  };

  return (
    <Screen top={<TopBar title="Sign in with email" onBack={() => router.back()} />}>
      {err ? <Banner tone="bad">{err}</Banner> : null}
      {!sent ? (
        <Card style={{ gap: 14 }}>
          <H2>Your email</H2>
          <P muted>We'll email you a 6-digit code. No password needed.</P>
          <Field
            label="Email"
            value={email}
            onChangeText={setEmail}
            placeholder="you@example.com"
            keyboardType="email-address"
            autoCapitalize="none"
            autoCorrect={false}
            autoComplete="email"
            textContentType="emailAddress"
            returnKeyType="send"
            onSubmitEditing={send}
            autoFocus
          />
          <Button kind="go" title="Email me a code" onPress={send} busy={busy} disabled={!/\S+@\S+\.\S+/.test(email)} />
        </Card>
      ) : (
        <Card style={{ gap: 14 }}>
          <H2>Check your email</H2>
          <P muted>We sent a code to {email.trim()}. It works for 10 minutes.</P>
          <Field
            label="Code"
            value={code}
            onChangeText={(t) => setCode(t.replace(/\D/g, '').slice(0, 6))}
            placeholder="123456"
            keyboardType="number-pad"
            autoComplete="one-time-code"
            textContentType="oneTimeCode"
            maxLength={6}
            onSubmitEditing={verify}
            autoFocus
            style={{ fontSize: 28, letterSpacing: 8, textAlign: 'center' }}
          />
          <Button kind="go" title="Sign in" onPress={verify} busy={busy} disabled={code.length !== 6} />
          <Button small kind="ghost" title="Use a different email" onPress={() => { setSent(false); setCode(''); setErr(''); }} />
        </Card>
      )}
    </Screen>
  );
}
