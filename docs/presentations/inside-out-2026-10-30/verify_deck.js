// Verify build/inside-out.key against deck.json. Run:
//   osascript -l JavaScript verify_deck.js <absolute path to this folder>
// Prints "verify OK" or throws on the first mismatch.
ObjC.import('Foundation');

function run(argv) {
  const dir = argv[0];
  const read = (p) => ObjC.unwrap($.NSString.stringWithContentsOfFileEncodingError(p, $.NSUTF8StringEncoding, null));
  const keyPath = `${dir}/build/inside-out.key`;
  if (!$.NSFileManager.defaultManager.fileExistsAtPath(keyPath)) throw new Error(`file not found: ${keyPath}`);
  const deck = JSON.parse(read(`${dir}/deck.json`));

  const K = Application('Keynote Creator Studio');
  const doc = K.open(Path(keyPath));
  const fail = (msg) => { throw new Error(`verify FAILED: ${msg}`); };

  if (doc.width() !== 1920 || doc.height() !== 1080) fail(`size ${doc.width()}x${doc.height()}`);
  const slides = doc.slides;
  if (slides.length !== deck.slides.length) fail(`slide count ${slides.length} != ${deck.slides.length}`);

  deck.slides.forEach((want, i) => {
    const s = slides[i];
    const where = `slide ${i + 1} (${want.id})`;
    if (s.skipped() !== want.skipped) fail(`${where}: skipped ${s.skipped()}`);
    if (s.presenterNotes().trim() !== want.notes.trim()) fail(`${where}: presenter notes differ`);
    const effect = s.transitionProperties().transitionEffect;
    if (effect !== want.transition.effect && !(want.transition.effect === 'none' && effect === 'no transition effect')) {
      fail(`${where}: transition ${effect} != ${want.transition.effect}`);
    }
    const pngs = want.images.filter((im) => !im.file.endsWith('.gif')).length + (want.look === 'white' ? 0 : 1);
    const gifs = want.images.filter((im) => im.file.endsWith('.gif')).length;
    if (s.images.length !== pngs) fail(`${where}: ${s.images.length} images, want ${pngs}`);
    if (s.movies.length !== gifs) fail(`${where}: ${s.movies.length} movies (gif), want ${gifs}`);
    const texts = s.textItems().filter((t) => t.objectText().trim() !== '').length;
    if (texts !== want.texts.length) fail(`${where}: ${texts} text items, want ${want.texts.length}`);
    if (s.audioClips.length !== (want.audio ? 1 : 0)) fail(`${where}: ${s.audioClips.length} audio clips`);
  });

  doc.close({saving: 'no'});
  return `verify OK (${deck.slides.length} slides)`;
}
