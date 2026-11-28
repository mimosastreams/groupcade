// Edit one question pack: its name, its questions, add a question. Every change saves right away.
// (Pasting a long list at once is easier on the website's account page.)
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { accountApi, type Pack, type PackQuestion } from '../../../lib/api';
import { dialog } from '../../../ui/Dialog';
import { Field } from '../../../ui/Field';
import { Banner, Button, C, Card, F, H2, P, Row, Screen, Seg, TopBar } from '../../../ui/kit';

const EMPTY = { q: '', right: '', w1: '', w2: '', w3: '', cat: '', level: 'medium' as PackQuestion['level'] };

export default function PackEditor() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [pack, setPack] = useState<Pack | null>(null);
  const [name, setName] = useState('');
  const [form, setForm] = useState(EMPTY);
  const [editing, setEditing] = useState<number | null>(null); // Index of the question in the form, or null for a new one.
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  useEffect(() => {
    accountApi
      .pack(Number(id))
      .then((r) => {
        setPack(r.pack);
        setName(r.pack.name);
      })
      .catch((e) => setErr(e.message));
  }, [id]);

  const save = async (p: { name?: string; questions?: PackQuestion[] }) => {
    if (!pack) return false;
    setBusy(true);
    setErr('');
    try {
      const r = await accountApi.savePack(pack.id, p);
      setPack(r.pack);
      return true;
    } catch (e) {
      setErr((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  };

  if (!pack) {
    return (
      <Screen top={<TopBar title="Question pack" onBack={() => router.back()} />}>
        {err ? <Banner tone="bad">{err}</Banner> : <ActivityIndicator color={C.accent} style={{ marginTop: 40 }} />}
      </Screen>
    );
  }

  const set = (k: keyof typeof EMPTY) => (v: string) => setForm((f) => ({ ...f, [k]: v }));
  const ready = form.q.trim() && form.right.trim() && form.w1.trim() && form.w2.trim() && form.w3.trim();

  const submit = async () => {
    const q: PackQuestion = { level: form.level, cat: form.cat.trim(), q: form.q.trim(), right: form.right.trim(), wrong: [form.w1.trim(), form.w2.trim(), form.w3.trim()] };
    const list = [...pack.questions];
    if (editing === null) list.push(q);
    else list[editing] = q;
    if (await save({ questions: list })) {
      setForm(EMPTY);
      setEditing(null);
    }
  };

  const edit = (i: number) => {
    const q = pack.questions[i];
    setEditing(i);
    setForm({ q: q.q, right: q.right, w1: q.wrong[0], w2: q.wrong[1], w3: q.wrong[2], cat: q.cat, level: q.level });
  };

  const remove = (i: number) =>
    dialog.alert('Delete this question?', pack.questions[i].q, [
      { text: 'Keep it', style: 'cancel' },
      { text: 'Delete', style: 'destructive', onPress: () => save({ questions: pack.questions.filter((_, j) => j !== i) }) },
    ]);

  const removePack = () =>
    dialog.alert(`Delete "${pack.name}"?`, 'Its questions go too. This can’t be undone.', [
      { text: 'Keep it', style: 'cancel' },
      {
        text: 'Delete pack',
        style: 'destructive',
        onPress: async () => {
          try {
            await accountApi.deletePack(pack.id);
            router.back();
          } catch (e) {
            setErr((e as Error).message);
          }
        },
      },
    ]);

  const full = editing === null && pack.questions.length >= pack.max;

  return (
    <Screen top={<TopBar title={pack.name} onBack={() => router.back()} />}>
      {err ? <Banner tone="bad">{err}</Banner> : null}
      <Card style={{ gap: 10 }}>
        <Field label="Pack name" value={name} onChangeText={setName} maxLength={60} onEndEditing={() => name.trim() && name.trim() !== pack.name && save({ name: name.trim() })} />
        <P small muted>
          {pack.questions.length} of {pack.max} questions. In a game, your questions come first (easy ones early, hard ones late), then regular questions fill any rounds left.
        </P>
      </Card>

      <Card style={{ gap: 12 }}>
        <H2>{editing === null ? 'Add a question' : `Edit question ${editing + 1}`}</H2>
        <Field label="Question" value={form.q} onChangeText={set('q')} multiline maxLength={300} />
        <Field label="Right answer" value={form.right} onChangeText={set('right')} maxLength={120} />
        <Field label="Wrong answers" value={form.w1} onChangeText={set('w1')} maxLength={120} placeholder="Wrong answer 1" />
        <Field value={form.w2} onChangeText={set('w2')} maxLength={120} placeholder="Wrong answer 2" accessibilityLabel="Wrong answer 2" />
        <Field value={form.w3} onChangeText={set('w3')} maxLength={120} placeholder="Wrong answer 3" accessibilityLabel="Wrong answer 3" />
        <Text style={{ fontFamily: F.heavy, fontSize: 15, color: C.ink }}>How hard</Text>
        <Seg
          options={[
            { value: 'easy', label: 'Easy' },
            { value: 'medium', label: 'Medium' },
            { value: 'hard', label: 'Hard' },
          ]}
          value={form.level}
          onChange={(v) => setForm((f) => ({ ...f, level: v as PackQuestion['level'] }))}
        />
        <Field label="Category" hint="(optional)" value={form.cat} onChangeText={set('cat')} maxLength={40} placeholder={pack.name} />
        <Button kind="go" title={editing === null ? (full ? 'This pack is full' : 'Add question') : 'Save question'} onPress={submit} busy={busy} disabled={!ready || full} />
        {editing !== null ? (
          <Button
            small
            kind="ghost"
            title="Cancel"
            onPress={() => {
              setEditing(null);
              setForm(EMPTY);
            }}
          />
        ) : null}
      </Card>

      <View style={{ gap: 10 }}>
        <H2>Questions</H2>
        {pack.questions.length ? (
          pack.questions.map((q, i) => (
            <Pressable
              key={`${i}-${q.q}`}
              onPress={() => edit(i)}
              accessibilityRole="button"
              accessibilityLabel={`Edit: ${q.q}`}
              style={({ pressed }) => [{ borderWidth: 2, borderColor: C.ink, borderRadius: 14, padding: 12, backgroundColor: editing === i ? C.goldTint : C.white, gap: 2, opacity: pressed ? 0.7 : 1 }]}
            >
              <Row style={{ flexWrap: 'nowrap', alignItems: 'flex-start' }}>
                <View style={{ flex: 1, gap: 2 }}>
                  <Text style={{ fontFamily: F.heavy, fontSize: 12, color: C.mist, textTransform: 'uppercase', letterSpacing: 0.6 }}>
                    {q.level}
                    {q.cat ? ` · ${q.cat}` : ''}
                  </Text>
                  <Text style={{ fontFamily: F.heavy, fontSize: 16, color: C.ink }}>{q.q}</Text>
                  <Text style={{ fontFamily: F.bold, fontSize: 14, color: C.mist }}>
                    <Text style={{ color: C.right }}>{q.right}</Text> · {q.wrong.join(' · ')}
                  </Text>
                </View>
                <Pressable onPress={() => remove(i)} hitSlop={10} accessibilityRole="button" accessibilityLabel="Delete this question">
                  <Text style={{ fontSize: 24, lineHeight: 26, color: C.out, paddingHorizontal: 4 }}>×</Text>
                </Pressable>
              </Row>
            </Pressable>
          ))
        ) : (
          <P muted>No questions yet. Add your first one above.</P>
        )}
      </View>

      <Button small kind="danger" title="Delete pack" onPress={removePack} />
    </Screen>
  );
}
