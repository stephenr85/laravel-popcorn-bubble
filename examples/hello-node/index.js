// The reference popcorn-bubble Node transform. Contract: read the JSON envelope
// { "input": {...}, "grant": {...} } on stdin, write a single JSON object on stdout.
// The effective grant is reflected in-band so the code can see exactly what it got.

const chunks = [];
process.stdin.on('data', (c) => chunks.push(c));
process.stdin.on('end', () => {
  const { input, grant } = JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}');

  // Do the transform. (stderr is the diagnostic channel; keep it off stdout under stdio transport.)
  const name = (input && input.name) || 'world';

  process.stdout.write(JSON.stringify({ greeting: `hello, ${name}`, net: grant?.net ?? 'none' }));
});
