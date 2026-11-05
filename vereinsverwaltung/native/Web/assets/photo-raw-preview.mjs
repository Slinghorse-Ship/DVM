/* Read declared JPEG previews only; never scan arbitrary RAW bytes for a JPEG. */
const MAX_PREVIEW = 8 * 1024 * 1024,
  MAX_PIXELS = 16000000;
const invalid = () => Error("Keine passende eingebettete Fotovorschau.");
const cancelled = (signal) => {
  if (signal?.aborted) throw new DOMException("Abgebrochen", "AbortError");
};
export async function rawPreview(file, { signal } = {}) {
  let budget = 512 * 1024;
  const read = async (offset, length) => {
    cancelled(signal);
    if (
      !Number.isSafeInteger(offset) ||
      !Number.isSafeInteger(length) ||
      offset < 0 ||
      length < 1 ||
      offset + length > file.size ||
      length > budget
    )
      throw invalid();
    budget -= length;
    const bytes = await file.slice(offset, offset + length).arrayBuffer();
    cancelled(signal);
    return new DataView(bytes);
  };
  const header = await read(0, 8),
    little = header.getUint16(0, false) === 0x4949;
  if (
    (!little && header.getUint16(0, false) !== 0x4d4d) ||
    header.getUint16(2, little) !== 42
  )
    throw invalid();
  const queue = [{ offset: header.getUint32(4, little), orientation: 1 }],
    seen = new Set(),
    candidates = [];
  while (queue.length) {
    const directory = queue.shift();
    if (!directory.offset || seen.has(directory.offset)) continue;
    if (seen.size >= 64) throw invalid();
    seen.add(directory.offset);
    const count = (await read(directory.offset, 2)).getUint16(0, little);
    if (count > 256) throw invalid();
    const view = await read(directory.offset + 2, count * 12 + 4),
      tags = new Map();
    for (let i = 0; i < count; i++) {
      const p = i * 12,
        tag = view.getUint16(p, little),
        type = view.getUint16(p + 2, little),
        n = view.getUint32(p + 4, little);
      if (
        ![254, 256, 257, 259, 262, 273, 274, 279, 330, 513, 514].includes(
          tag,
        ) ||
        ![3, 4].includes(type) ||
        n < 1 ||
        n > 16
      )
        continue;
      const size = type === 3 ? 2 : 4,
        length = n * size,
        data =
          length <= 4
            ? new DataView(view.buffer, p + 8, 4)
            : await read(view.getUint32(p + 8, little), length);
      tags.set(
        tag,
        Array.from({ length: n }, (_, j) =>
          type === 3
            ? data.getUint16(j * size, little)
            : data.getUint32(j * size, little),
        ),
      );
    }
    const value = (tag) => tags.get(tag)?.[0],
      orientation = value(274) || directory.orientation;
    if (orientation < 1 || orientation > 8) throw invalid();
    const width = value(256),
      height = value(257),
      photometric = value(262);
    if (photometric === 32803 && width * height > 120000000)
      throw Error("RAW-Bild ist für die Verarbeitung zu groß.");
    const offset = value(513) ?? value(273),
      length = value(514) ?? value(279);
    const declaredJPEG =
      (tags.has(513) && tags.has(514)) ||
      ([6, 7].includes(value(259)) && [0, 1, 2].includes(photometric));
    const singleStrip =
      tags.get(273)?.length === undefined || tags.get(273).length === 1;
    if (
      declaredJPEG &&
      singleStrip &&
      Number.isInteger(width) &&
      Number.isInteger(height) &&
      width > 0 &&
      height > 0 &&
      Math.max(width, height) >= 512 &&
      width * height <= MAX_PIXELS &&
      Number.isInteger(offset) &&
      Number.isInteger(length) &&
      length >= 4 &&
      length <= MAX_PREVIEW &&
      offset > 0 &&
      offset + length <= file.size
    )
      candidates.push({ offset, length, width, height, orientation });
    for (const offset of tags.get(330) || [])
      queue.push({ offset, orientation });
    const next = view.getUint32(count * 12, little);
    if (next) queue.push({ offset: next, orientation });
  }
  candidates.sort((a, b) => b.width * b.height - a.width * a.height);
  for (const candidate of candidates) {
    cancelled(signal);
    try {
      const buffer = await file
        .slice(candidate.offset, candidate.offset + candidate.length)
        .arrayBuffer();
      cancelled(signal);
      const parsed = inspectJPEG(buffer);
      if (
        parsed.width !== candidate.width ||
        parsed.height !== candidate.height
      )
        continue;
      if (
        candidate.orientation !== 1 &&
        parsed.orientation !== 1 &&
        candidate.orientation !== parsed.orientation
      )
        continue;
      return {
        ...candidate,
        buffer: parsed.buffer,
        orientation:
          candidate.orientation === 1
            ? parsed.orientation
            : candidate.orientation,
      };
    } catch (error) {
      if (signal?.aborted || error.name === "AbortError") throw error;
    }
  }
  throw invalid();
}
function inspectJPEG(buffer) {
  const v = new DataView(buffer),
    bytes = new Uint8Array(buffer);
  if (
    v.byteLength < 4 ||
    v.getUint16(0, false) !== 0xffd8 ||
    v.getUint16(v.byteLength - 2, false) !== 0xffd9
  )
    throw invalid();
  let offset = 2,
    width = 0,
    height = 0,
    orientation = 1,
    steps = 0;
  const remove = [];
  while (offset < v.byteLength && steps++ < 128) {
    const start = offset;
    if (v.getUint8(offset++) !== 255) throw invalid();
    while (offset < v.byteLength && v.getUint8(offset) === 255) offset++;
    const marker = v.getUint8(offset++);
    if (marker === 0xda) break;
    if (marker === 0xd9 || offset + 2 > v.byteLength) throw invalid();
    const length = v.getUint16(offset, false),
      end = offset + length;
    if (length < 2 || end > v.byteLength || offset > 256 * 1024)
      throw invalid();
    if ([0xc0, 0xc1, 0xc2].includes(marker)) {
      if (length < 8 || v.getUint8(offset + 2) !== 8) throw invalid();
      height = v.getUint16(offset + 3, false);
      width = v.getUint16(offset + 5, false);
      if (!width || !height || width * height > MAX_PIXELS) throw invalid();
    }
    if (
      marker === 0xe1 &&
      length >= 16 &&
      String.fromCharCode(...bytes.subarray(offset + 2, offset + 8)) ===
        "Exif\0\0"
    ) {
      const t = offset + 8,
        little = v.getUint16(t, false) === 0x4949;
      if (
        (little || v.getUint16(t, false) === 0x4d4d) &&
        v.getUint16(t + 2, little) === 42
      ) {
        const d = t + v.getUint32(t + 4, little);
        if (d >= t + 8 && d + 2 <= end) {
          const count = v.getUint16(d, little);
          if (count <= 128 && d + 2 + count * 12 + 4 <= end)
            for (let i = 0; i < count; i++) {
              const p = d + 2 + i * 12;
              if (
                v.getUint16(p, little) === 274 &&
                v.getUint16(p + 2, little) === 3 &&
                v.getUint32(p + 4, little) === 1
              ) {
                const n = v.getUint16(p + 8, little);
                if (n >= 1 && n <= 8) orientation = n;
              }
            }
        }
      }
      remove.push([start, end]);
    }
    offset = end;
  }
  if (!width || !height || steps >= 128) throw invalid();
  if (!remove.length) return { buffer, width, height, orientation };
  const cleaned = new Uint8Array(
    bytes.length - remove.reduce((n, [a, b]) => n + b - a, 0),
  );
  let at = 0,
    last = 0;
  for (const [a, b] of remove) {
    cleaned.set(bytes.subarray(last, a), at);
    at += a - last;
    last = b;
  }
  cleaned.set(bytes.subarray(last), at);
  return { buffer: cleaned.buffer, width, height, orientation };
}
