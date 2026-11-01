/* Ellipsis hit engine — original songs in the style of current chart records.
   Renders with OfflineAudioContext: real filters, convolution reverb, sidechain, bus compression, limiter.
   Nothing here copies an existing melody, chord loop, lyric or recording. */
globalThis.HIT = (function () {
  const SR = 32000;
  const mf = m => 440 * Math.pow(2, (m - 69) / 12);
  const rng = seed => { let s = (seed * 2654435761) >>> 0 || 7; return () => { s ^= s << 13; s >>>= 0; s ^= s >> 17; s ^= s << 5; s >>>= 0; return s / 4294967296; }; };
  const SC = { major: [0, 2, 4, 5, 7, 9, 11], minor: [0, 2, 3, 5, 7, 8, 10], dorian: [0, 2, 3, 5, 7, 9, 10], mixo: [0, 2, 4, 5, 7, 9, 10], harm: [0, 2, 3, 5, 7, 8, 11], phryg: [0, 1, 3, 5, 7, 8, 10] };
  const deg = (sc, root, d) => { const n = sc.length, o = Math.floor(d / n); return root + sc[((d % n) + n) % n] + 12 * o; };
  const chordOf = (sc, root, d, size, inv) => { const out = []; for (let i = 0; i < (size || 3); i++) out.push(deg(sc, root, d + i * 2)); if (inv) for (let k = 0; k < inv; k++) out.push(out.shift() + 12); return out; };

  async function render(spec, stem) {
    const on = k => !stem || stem === k;
    const R = rng(spec.seed || 1);
    const spb = 60 / spec.bpm, bar = spb * 4;
    const total = spec.bars * bar + (spec.tail || 2.4);
    const ctx = new OfflineAudioContext(2, Math.ceil(total * SR), SR);
    const T = (b, beat) => (b * 4 + (beat || 0)) * spb;

    /* ── buses ── */
    const master = ctx.createGain();
    const hp = ctx.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 28; hp.Q.value = 0.7;
    const lows = ctx.createBiquadFilter(); lows.type = 'lowshelf'; lows.frequency.value = 95; lows.gain.value = spec.lowBoost != null ? spec.lowBoost : 1.5;
    const air = ctx.createBiquadFilter(); air.type = 'highshelf'; air.frequency.value = 9500; air.gain.value = spec.air != null ? spec.air : 2.5;
    const glue = ctx.createDynamicsCompressor(); glue.threshold.value = -16; glue.ratio.value = 2.6; glue.knee.value = 6; glue.attack.value = 0.012; glue.release.value = 0.18;
    const lim = ctx.createDynamicsCompressor(); lim.threshold.value = -4; lim.ratio.value = 20; lim.knee.value = 0; lim.attack.value = 0.002; lim.release.value = 0.07;
    const mk = ctx.createGain(); mk.gain.value = 1.6;
    if (stem) { master.connect(hp); hp.connect(lows); lows.connect(air); air.connect(ctx.destination); }
    else { master.connect(hp); hp.connect(lows); lows.connect(air); air.connect(glue); glue.connect(mk); mk.connect(lim); lim.connect(ctx.destination); }

    const bus = (g, toMaster) => { const n = ctx.createGain(); n.gain.value = g; if (toMaster !== false) n.connect(master); return n; };
    const drums = bus(spec.drumGain || 0.95), bass = bus(spec.bassGain || 0.85), music = bus(spec.musicGain || 0.6), vox = bus(spec.voxGain || 0.7);
    // music lowpass for intro/build sweeps
    const mlp = ctx.createBiquadFilter(); mlp.type = 'lowpass'; mlp.frequency.value = 20000; mlp.Q.value = 0.8;
    const musicIn = ctx.createGain(); musicIn.connect(mlp); mlp.connect(music);
    const duckM = ctx.createGain(), duckB = ctx.createGain(); duckM.connect(musicIn); duckB.connect(bass);

    /* reverb + delay sends */
    const ir = ctx.createBuffer(2, Math.floor(SR * (spec.verb || 2.4)), SR);
    for (let c = 0; c < 2; c++) { const d = ir.getChannelData(c); let lp = 0; for (let i = 0; i < d.length; i++) { const t = i / d.length; lp += ((R() * 2 - 1) - lp) * (0.62 - 0.45 * t); d[i] = i < SR * 0.018 ? 0 : lp * Math.pow(1 - t, 2.4) * (1 + (c ? 0.03 : -0.03) * Math.sin(i * 0.002)); } }
    const conv = ctx.createConvolver(); conv.buffer = ir; const verbIn = ctx.createGain(), verbOut = ctx.createGain(); verbOut.gain.value = 0.55;
    const vhp = ctx.createBiquadFilter(); vhp.type = 'highpass'; vhp.frequency.value = 260;
    verbIn.connect(vhp); vhp.connect(conv); conv.connect(verbOut); verbOut.connect(master);
    const dL = ctx.createDelay(2), dR = ctx.createDelay(2), dfb = ctx.createGain(), dlp = ctx.createBiquadFilter(), dIn = ctx.createGain(), dOut = ctx.createGain(), merge = ctx.createChannelMerger(2);
    dL.delayTime.value = spb * 0.75; dR.delayTime.value = spb * 0.5; dfb.gain.value = 0.38; dlp.type = 'lowpass'; dlp.frequency.value = 4200; dOut.gain.value = 0.32;
    dIn.connect(dL); dL.connect(dlp); dlp.connect(dR); dR.connect(dfb); dfb.connect(dL); dL.connect(merge, 0, 0); dR.connect(merge, 0, 1); merge.connect(dOut); dOut.connect(master);
    const send = (node, rv, dl) => { if (rv) { const g = ctx.createGain(); g.gain.value = rv; node.connect(g); g.connect(verbIn); } if (dl) { const g = ctx.createGain(); g.gain.value = dl; node.connect(g); g.connect(dIn); } };
    const sendBus = (b, rv, dl) => send(b, rv, dl);
    sendBus(music, spec.musicVerb != null ? spec.musicVerb : 0.22, spec.musicDelay || 0.06);
    sendBus(vox, spec.voxVerb != null ? spec.voxVerb : 0.32, spec.voxDelay != null ? spec.voxDelay : 0.18);
    sendBus(drums, 0.06, 0);

    /* ── one-shot buffers ── */
    const cache = {};
    const buf = (key, secs, fill) => { if (cache[key]) return cache[key]; const n = Math.ceil(secs * SR), b = ctx.createBuffer(2, n, SR); fill(b.getChannelData(0), b.getChannelData(1), n); return (cache[key] = b); };
    const shot = (b, t, gain, dest, rate, pan) => { if (t < 0 || t > total) return; const s = ctx.createBufferSource(); s.buffer = b; if (rate) s.playbackRate.value = rate; const g = ctx.createGain(); g.gain.value = gain; s.connect(g); if (pan) { const p = ctx.createStereoPanner(); p.pan.value = pan; g.connect(p); p.connect(dest); } else g.connect(dest); s.start(t); };
    const KICK = (tune, punch, len) => buf('k' + tune + punch + len, len || 0.5, (L, Rr, n) => { let ph = 0; for (let i = 0; i < n; i++) { const t = i / SR; ph += 2 * Math.PI * (tune + (punch || 120) * Math.exp(-t * 38)) / SR; let v = Math.sin(ph) * Math.exp(-t / ((len || 0.5) * 0.36)); v = Math.tanh(v * 1.6); if (i < 90) v += (R() * 2 - 1) * 0.5 * (1 - i / 90); L[i] = Rr[i] = v * 0.95; } });
    const SNARE = (tone, len, bright) => buf('s' + tone + len + bright, len || 0.32, (L, Rr, n) => { let lp = 0, p = 0; for (let i = 0; i < n; i++) { const t = i / SR, x = R() * 2 - 1; lp += (x - lp) * (bright || 0.7); const hpv = x - p; p = x; const nz = (hpv * 0.55 + lp * 0.45) * Math.exp(-t / ((len || 0.32) * 0.33)); const body = Math.sin(2 * Math.PI * (tone || 190) * t * (1 + 0.4 * Math.exp(-t * 30))) * Math.exp(-t / 0.06) * 0.7; L[i] = (nz * 0.8 + body) * 0.9; Rr[i] = (nz * 0.8 * (0.92 + 0.08 * Math.sin(i * 0.7)) + body) * 0.9; } });
    const CLAP = () => buf('clap', 0.35, (L, Rr, n) => { let lp = 0; for (let i = 0; i < n; i++) { const t = i / SR; let e = 0; [0, 0.009, 0.019].forEach(d => { if (t >= d) e += Math.exp(-(t - d) / 0.0055); }); e += t > 0.028 ? Math.exp(-(t - 0.028) / 0.09) : 0; const x = R() * 2 - 1; lp += (x - lp) * 0.5; const v = (x - lp) * e * 0.55; L[i] = v; Rr[i] = v * (0.9 + 0.2 * R()); } });
    const HAT = (open) => buf('h' + open, open ? 0.45 : 0.07, (L, Rr, n) => { let p = 0, p2 = 0; for (let i = 0; i < n; i++) { const t = i / SR; let x = 0; [1, 1.42, 1.83, 2.37, 3.06, 3.61].forEach(m => { x += Math.sign(Math.sin(2 * Math.PI * 4200 * m * t)); }); x = x / 6 * 0.5 + (R() * 2 - 1) * 0.5; const h = x - p; p = x; const h2 = h - p2; p2 = h; const v = h2 * Math.exp(-t / (open ? 0.12 : 0.016)) * 0.38; L[i] = v; Rr[i] = v; } });
    const SHAKER = () => buf('shk', 0.09, (L, Rr, n) => { let p = 0; for (let i = 0; i < n; i++) { const t = i / SR, x = R() * 2 - 1, h = x - p; p = x; const e = Math.min(1, t / 0.012) * Math.exp(-t / 0.03); L[i] = h * e * 0.3; Rr[i] = h * e * 0.3; } });
    const RIM = () => buf('rim', 0.08, (L, Rr, n) => { for (let i = 0; i < n; i++) { const t = i / SR, v = (Math.sin(2 * Math.PI * 1700 * t) * 0.6 + Math.sin(2 * Math.PI * 820 * t) * 0.4) * Math.exp(-t / 0.012); L[i] = Rr[i] = v * 0.7; } });
    const TOM = f => buf('tom' + f, 0.5, (L, Rr, n) => { let ph = 0; for (let i = 0; i < n; i++) { const t = i / SR; ph += 2 * Math.PI * f * (1 + 0.5 * Math.exp(-t * 20)) / SR; const v = Math.sin(ph) * Math.exp(-t / 0.16); L[i] = Rr[i] = v * 0.7; } });
    const LOG = (m) => buf('log' + m, 0.6, (L, Rr, n) => { const f = mf(m); let ph = 0, ph2 = 0; for (let i = 0; i < n; i++) { const t = i / SR; ph += 2 * Math.PI * f * (1 + 0.08 * Math.exp(-t * 60)) / SR; ph2 += 2 * Math.PI * f * 2.01 / SR; const v = Math.tanh((Math.sin(ph) + 0.35 * Math.sin(ph2) * Math.exp(-t * 18)) * 2.2) * Math.exp(-t / 0.2); L[i] = Rr[i] = v * 0.55; } });
    const KS = (m, bright, len) => buf('ks' + m + bright + len, len || 1.6, (L, Rr, n) => { const f = mf(m), N = Math.max(2, Math.round(SR / f)), q = new Float32Array(N); for (let i = 0; i < N; i++) q[i] = R() * 2 - 1; let k = 0; const d = 0.5 * (bright || 0.995); for (let i = 0; i < n; i++) { const a = q[k], b = q[(k + 1) % N]; q[k] = (a + b) * d; k = (k + 1) % N; const e = Math.min(1, (n - i) / (SR * 0.05)); L[i] = a * e * 0.6; Rr[i] = a * e * 0.6; } });
    const NOISE = (secs) => buf('nz' + secs, secs, (L, Rr) => { for (let i = 0; i < L.length; i++) { L[i] = R() * 2 - 1; Rr[i] = R() * 2 - 1; } });

    const kicks = [];
    const D = {
      kick: (t, v, o) => { o = o || {}; shot(KICK(o.tune || 50, o.punch || 120, o.len || 0.5), t, (v || 1) * 1.0, drums); kicks.push([t, o.duck != null ? o.duck : 1]); },
      snare: (t, v, o) => { o = o || {}; shot(SNARE(o.tone || 190, o.len || 0.32, o.bright || 0.7), t, (v || 1) * 0.75, drums); if (o.verb !== false) { const g = ctx.createGain(); g.gain.value = 0.25 * (v || 1); const s = ctx.createBufferSource(); s.buffer = SNARE(o.tone || 190, o.len || 0.32, o.bright || 0.7); s.connect(g); g.connect(verbIn); s.start(t); } },
      clap: (t, v, pan) => shot(CLAP(), t, (v || 1) * 0.8, drums, 0, pan),
      hat: (t, v, open, pan) => shot(HAT(open), t, (v || 1) * (open ? 0.42 : 0.5), drums, 0, pan != null ? pan : 0.18),
      shaker: (t, v, pan) => shot(SHAKER(), t, (v || 1) * 0.55, drums, 0, pan != null ? pan : -0.25),
      rim: (t, v, pan) => shot(RIM(), t, (v || 1) * 0.5, drums, 0, pan || 0.1),
      tom: (t, f, v, pan) => shot(TOM(f), t, (v || 1) * 0.7, drums, 0, pan || 0),
      log: (t, m, v) => shot(LOG(m), t, (v || 1) * 0.8, bass),
    };

    /* ── tonal voices ── */
    const env = (g, t, dur, a, peak, sus, rel) => { g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(peak, t + a); g.gain.setTargetAtTime(peak * sus, t + a, Math.max(0.02, dur * 0.25)); g.gain.setValueAtTime(peak * sus, t + dur); g.gain.setTargetAtTime(0.0001, t + dur, rel / 3); };
    const osc = (type, f, t, end, detune) => { const o = ctx.createOscillator(); o.type = type; o.frequency.value = f; if (detune) o.detune.value = detune; o.start(t); o.stop(end); return o; };
    const S = {
      // wide detuned saw stack — anthem chords / drops
      supersaw: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + (o.rel || 0.35) + 0.05, f = mf(m), lp = ctx.createBiquadFilter(), g = ctx.createGain(); lp.type = 'lowpass'; lp.Q.value = o.q || 1.2; const c0 = o.cut || 3200; lp.frequency.setValueAtTime(c0 * 0.35, t); lp.frequency.exponentialRampToValueAtTime(c0, t + Math.min(0.12, dur * 0.4)); lp.frequency.setTargetAtTime(c0 * 0.6, t + 0.12, dur * 0.6); env(g, t, dur, o.att || 0.01, (v || 1) * 0.07, o.sus != null ? o.sus : 0.8, o.rel || 0.35); lp.connect(g); g.connect(o.dest || duckM); [-18, -9, -3, 3, 9, 18].forEach((dt, i) => { const ox = osc('sawtooth', f, t, end, dt * (o.spread || 1)); const p = ctx.createStereoPanner(); p.pan.value = (i / 5 - 0.5) * 1.6 * (o.width || 1); ox.connect(p); p.connect(lp); }); },
      // soft pad
      pad: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + 1.2, f = mf(m), lp = ctx.createBiquadFilter(), g = ctx.createGain(); lp.type = 'lowpass'; lp.frequency.value = o.cut || 1800; lp.Q.value = 0.5; env(g, t, dur, o.att || 0.5, (v || 1) * 0.05, 0.9, 1.1); lp.connect(g); g.connect(o.dest || duckM); [-7, 7].forEach((dt, i) => { const ox = osc(o.wave || 'sawtooth', f, t, end, dt); const p = ctx.createStereoPanner(); p.pan.value = i ? 0.6 : -0.6; ox.connect(p); p.connect(lp); }); const sub = osc('triangle', f / 2, t, end); const sg = ctx.createGain(); sg.gain.value = 0.4; sub.connect(sg); sg.connect(lp); },
      // electric piano (FM)
      keys: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + 0.9, f = mf(m), car = osc('sine', f, t, end), mod = osc('sine', f * (o.ratio || 1), t, end), mg = ctx.createGain(), g = ctx.createGain(); mg.gain.setValueAtTime(f * (o.index || 2.2), t); mg.gain.exponentialRampToValueAtTime(f * 0.15, t + 0.6); mod.connect(mg); mg.connect(car.frequency); const trem = osc('sine', 4.8, t, end), tg = ctx.createGain(); tg.gain.value = 0.12; trem.connect(tg); env(g, t, dur, 0.004, (v || 1) * 0.16, 0.45, 0.6); tg.connect(g.gain); car.connect(g); const p = ctx.createStereoPanner(); p.pan.value = ((m % 12) / 11 - 0.5) * 0.5; g.connect(p); p.connect(o.dest || duckM); },
      // pluck / bell / mallet
      pluck: (t, dur, m, v, o) => { o = o || {}; const end = t + Math.max(dur, 0.4) + 0.4, f = mf(m), lp = ctx.createBiquadFilter(), g = ctx.createGain(); lp.type = 'lowpass'; lp.Q.value = o.q || 4; lp.frequency.setValueAtTime(o.cut || 5200, t); lp.frequency.exponentialRampToValueAtTime(380, t + (o.decay || 0.28)); g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime((v || 1) * 0.12, t + 0.003); g.gain.setTargetAtTime(0.0001, t + 0.01, o.decay || 0.28); const a = osc(o.wave || 'sawtooth', f, t, end, 4), b = osc(o.wave2 || 'square', f, t, end, -6); const bg = ctx.createGain(); bg.gain.value = 0.5; a.connect(lp); b.connect(bg); bg.connect(lp); lp.connect(g); const p = ctx.createStereoPanner(); p.pan.value = o.pan != null ? o.pan : 0; g.connect(p); p.connect(o.dest || duckM); },
      bell: (t, dur, m, v, o) => { o = o || {}; const end = t + 2.2, f = mf(m), g = ctx.createGain(); [[1, 1, 1.4], [2.76, 0.42, 0.6], [5.4, 0.2, 0.3], [8.93, 0.1, 0.16]].forEach(([r, a, d]) => { const x = osc('sine', f * r, t, end), xg = ctx.createGain(); xg.gain.setValueAtTime(a * (v || 1) * 0.09, t); xg.gain.exponentialRampToValueAtTime(0.0001, t + d); x.connect(xg); xg.connect(g); }); g.gain.value = 1; const p = ctx.createStereoPanner(); p.pan.value = o.pan || 0; g.connect(p); p.connect(o.dest || duckM); },
      // mono lead with glide + vibrato
      lead: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + 0.3, f = mf(m), a = osc(o.wave || 'sawtooth', o.from ? mf(o.from) : f, t, end, 5), b = osc('square', o.from ? mf(o.from) : f, t, end, -5); if (o.from) { a.frequency.exponentialRampToValueAtTime(f, t + 0.06); b.frequency.exponentialRampToValueAtTime(f, t + 0.06); } const vib = osc('sine', 5.6, t, end), vg = ctx.createGain(); vg.gain.setValueAtTime(0, t); vg.gain.linearRampToValueAtTime(f * 0.009, t + 0.25); vib.connect(vg); vg.connect(a.frequency); vg.connect(b.frequency); const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = o.cut || 2600; lp.Q.value = 2; const g = ctx.createGain(); env(g, t, dur, 0.01, (v || 1) * 0.06, 0.85, 0.12); const bg = ctx.createGain(); bg.gain.value = 0.45; a.connect(lp); b.connect(bg); bg.connect(lp); lp.connect(g); g.connect(o.dest || duckM); },
      // plucked string (Karplus) — guitar, harp, kora
      string: (t, m, v, o) => { o = o || {}; shot(KS(m, o.bright || 0.996, o.len || 1.8), t, (v || 1) * 0.55, o.dest || duckM, 0, o.pan || 0); },
      // brass stab
      brass: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + 0.2, f = mf(m), lp = ctx.createBiquadFilter(), g = ctx.createGain(); lp.type = 'lowpass'; lp.Q.value = 1; lp.frequency.setValueAtTime(500, t); lp.frequency.linearRampToValueAtTime(3200, t + 0.06); lp.frequency.setTargetAtTime(1400, t + 0.08, 0.2); env(g, t, dur, 0.02, (v || 1) * 0.07, 0.7, 0.12); lp.connect(g); g.connect(o.dest || duckM); [-6, 0, 6].forEach(dt => osc('sawtooth', f, t, end, dt).connect(lp)); },
      organ: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + 0.1, f = mf(m), g = ctx.createGain(); env(g, t, dur, 0.005, (v || 1) * 0.05, 1, 0.06); [[1, 1], [2, 0.6], [3, 0.35], [4, 0.25], [6, 0.12]].forEach(([r, a]) => { const x = osc('sine', f * r, t, end), xg = ctx.createGain(); xg.gain.value = a; x.connect(xg); xg.connect(g); }); g.connect(o.dest || duckM); },
    };
    const B = {
      // 808 with glide + drive
      b808: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + 0.08, f = mf(m), x = osc('sine', f, t, end); if (o.to != null) { x.frequency.setValueAtTime(f, t + dur * (o.at || 0.6)); x.frequency.exponentialRampToValueAtTime(mf(o.to), t + dur * (o.at || 0.6) + (o.glide || 0.09)); } x.frequency.setValueAtTime(f * 1.6, t); x.frequency.exponentialRampToValueAtTime(f, t + 0.03); const sh = ctx.createWaveShaper(); const cur = new Float32Array(1024); const dr = o.drive || 2.4; for (let i = 0; i < 1024; i++) { const xx = i / 511.5 - 1; cur[i] = Math.tanh(xx * dr) / Math.tanh(dr); } sh.curve = cur; const g = ctx.createGain(); g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime((v || 1) * 0.55, t + 0.004); g.gain.setTargetAtTime((v || 1) * 0.42, t + 0.05, 0.25); g.gain.setValueAtTime((v || 1) * 0.42, t + dur); g.gain.setTargetAtTime(0.0001, t + dur, 0.02); x.connect(sh); sh.connect(g); g.connect(duckB); },
      // synth/house/funk bass
      sub: (t, dur, m, v, o) => { o = o || {}; const end = t + dur + 0.08, f = mf(m), a = osc('sine', f, t, end), b = osc(o.wave || 'sawtooth', f, t, end, 3), lp = ctx.createBiquadFilter(), g = ctx.createGain(), bg = ctx.createGain(); lp.type = 'lowpass'; lp.Q.value = o.q || 3; lp.frequency.setValueAtTime(o.cut || 900, t); lp.frequency.exponentialRampToValueAtTime(Math.max(120, (o.cut || 900) * 0.25), t + (o.decay || 0.18)); bg.gain.value = o.grit != null ? o.grit : 0.35; b.connect(bg); bg.connect(lp); a.connect(lp); env(g, t, dur, 0.005, (v || 1) * 0.45, 0.75, 0.05); lp.connect(g); g.connect(duckB); },
    };
    const V = {
      // formant vocal chop — "oh / ah / ee" with glide and vibrato
      chop: (t, dur, m, v, o) => { o = o || {}; const F = { ah: [800, 1150, 2900], oh: [450, 800, 2830], oo: [325, 700, 2530], ee: [270, 2140, 2950], eh: [530, 1840, 2480] }[o.vowel || 'ah']; const end = t + dur + 0.25, f = mf(m), src = osc('sawtooth', o.from ? mf(o.from) : f, t, end), src2 = osc('sawtooth', o.from ? mf(o.from) : f, t, end, 7); if (o.from) { src.frequency.exponentialRampToValueAtTime(f, t + 0.07); src2.frequency.exponentialRampToValueAtTime(f, t + 0.07); } const vib = osc('sine', 5.2, t, end), vg = ctx.createGain(); vg.gain.setValueAtTime(0, t); vg.gain.linearRampToValueAtTime(f * 0.012, t + Math.min(0.35, dur)); vib.connect(vg); vg.connect(src.frequency); vg.connect(src2.frequency); const mix = ctx.createGain(); mix.gain.value = 0.5; src.connect(mix); src2.connect(mix); const g = ctx.createGain(); env(g, t, dur, o.att || 0.025, (v || 1) * 0.5, 0.9, o.rel || 0.12); F.forEach((fr, i) => { const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = fr * (o.shift || 1); bp.Q.value = [9, 12, 14][i]; const bg = ctx.createGain(); bg.gain.value = [1, 0.55, 0.22][i]; mix.connect(bp); bp.connect(bg); bg.connect(g); }); const breath = ctx.createBufferSource(); breath.buffer = NOISE(1.5); const nb = ctx.createBiquadFilter(); nb.type = 'bandpass'; nb.frequency.value = F[1]; nb.Q.value = 3; const ng = ctx.createGain(); ng.gain.setValueAtTime(0, t); ng.gain.linearRampToValueAtTime(0.03 * (v || 1), t + 0.02); ng.gain.setTargetAtTime(0.004, t + 0.04, 0.05); ng.gain.setTargetAtTime(0, t + dur, 0.05); breath.connect(nb); nb.connect(ng); ng.connect(g); breath.start(t); breath.stop(end); const p = ctx.createStereoPanner(); p.pan.value = o.pan || 0; g.connect(p); p.connect(o.dest || vox); },
    };
    const FX = {
      riser: (t0, t1, v) => { const s = ctx.createBufferSource(); s.buffer = NOISE(Math.max(0.5, t1 - t0 + 0.2)); const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = 2.5; bp.frequency.setValueAtTime(300, t0); bp.frequency.exponentialRampToValueAtTime(9000, t1); const g = ctx.createGain(); g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.22 * (v || 1), t1); g.gain.setValueAtTime(0, t1 + 0.01); s.connect(bp); bp.connect(g); g.connect(master); send(g, 0.6, 0); s.start(t0); s.stop(t1 + 0.1); },
      impact: (t, v) => { D.kick(t, 0.9 * (v || 1), { tune: 38, punch: 160, len: 1.1, duck: 1 }); const s = ctx.createBufferSource(); s.buffer = NOISE(2.2); const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.setValueAtTime(6000, t); lp.frequency.exponentialRampToValueAtTime(200, t + 1.8); const g = ctx.createGain(); g.gain.setValueAtTime(0.18 * (v || 1), t); g.gain.exponentialRampToValueAtTime(0.0001, t + 2); s.connect(lp); lp.connect(g); g.connect(verbIn); g.connect(master); s.start(t); s.stop(t + 2.1); },
      down: (t, len) => { const s = ctx.createBufferSource(); s.buffer = NOISE(len + 0.2); const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.Q.value = 2; bp.frequency.setValueAtTime(7000, t); bp.frequency.exponentialRampToValueAtTime(250, t + len); const g = ctx.createGain(); g.gain.setValueAtTime(0.14, t); g.gain.exponentialRampToValueAtTime(0.0001, t + len); s.connect(bp); bp.connect(g); g.connect(master); send(g, 0.5, 0); s.start(t); s.stop(t + len + 0.1); },
      sweep: (t0, t1, f0, f1) => { mlp.frequency.setValueAtTime(f0, t0); mlp.frequency.exponentialRampToValueAtTime(f1, t1); },
      cut: (t, f) => mlp.frequency.setValueAtTime(f, t),
      tape: (t, len) => { music.gain.setValueAtTime(spec.musicGain || 0.6, t); music.gain.linearRampToValueAtTime(0.0001, t + len); music.gain.setValueAtTime(spec.musicGain || 0.6, t + len + 0.02); },
    };

    const gate = (obj, k, keep) => { const o = {}; Object.keys(obj).forEach(n => { o[n] = on(k) || (keep && keep(n)) ? obj[n] : (n === 'kick' ? (t, v, op) => { kicks.push([t, op && op.duck != null ? op.duck : 1]); } : () => {}); }); return o; };
    spec.build({ T, spb, bar, R, D: gate(D, 'rhythm'), S: gate(S, 'music'), B: gate(B, 'rhythm'), V: gate(V, 'vox'), FX: gate(FX, 'music', n => n === 'sweep' || n === 'cut' || n === 'tape'), deg, chordOf, SC, mf, total });

    /* sidechain pump from every kick */
    const depth = spec.duck != null ? spec.duck : 0.55, rel = spec.duckRel || spb * 0.6;
    duckM.gain.setValueAtTime(1, 0); duckB.gain.setValueAtTime(1, 0);
    kicks.sort((a, b) => a[0] - b[0]).forEach(([t, k]) => { if (!k) return; [[duckM, depth], [duckB, depth * 0.65]].forEach(([n, d]) => { n.gain.setValueAtTime(1, Math.max(0, t - 0.004)); n.gain.linearRampToValueAtTime(1 - d * k, t + 0.006); n.gain.setTargetAtTime(1, t + 0.02, rel / 3); }); });

    const out = await ctx.startRendering();
    if (stem) return { L: out.getChannelData(0), R: out.getChannelData(1), sr: SR };
    /* finish: gentle soft-clip + normalise to −0.9 dBFS, short fade-out */
    const L = out.getChannelData(0), Rr = out.getChannelData(1); let pk = 0;
    for (let i = 0; i < L.length; i++) { L[i] = Math.tanh(L[i] * 1.08) / 1.02; Rr[i] = Math.tanh(Rr[i] * 1.08) / 1.02; pk = Math.max(pk, Math.abs(L[i]), Math.abs(Rr[i])); }
    const g = 0.9 / (pk || 1), fade = Math.floor(SR * 1.2);
    let e = 0; for (let i = 0; i < L.length; i++) { const f = i > L.length - fade ? (L.length - i) / fade : 1; L[i] *= g * f; Rr[i] *= g * f; e += L[i] * L[i] + Rr[i] * Rr[i]; }
    return { L, R: Rr, sr: SR, secs: L.length / SR, rmsDb: 10 * Math.log10(e / (2 * L.length) + 1e-12) };
  }

  function wav(r) {
    const n = r.L.length, ab = new ArrayBuffer(44 + n * 4), dv = new DataView(ab), S = (o, t) => { for (let i = 0; i < t.length; i++) dv.setUint8(o + i, t.charCodeAt(i)); };
    S(0, 'RIFF'); dv.setUint32(4, 36 + n * 4, true); S(8, 'WAVE'); S(12, 'fmt '); dv.setUint32(16, 16, true); dv.setUint16(20, 1, true); dv.setUint16(22, 2, true); dv.setUint32(24, r.sr, true); dv.setUint32(28, r.sr * 4, true); dv.setUint16(32, 4, true); dv.setUint16(34, 16, true); S(36, 'data'); dv.setUint32(40, n * 4, true);
    for (let i = 0; i < n; i++) { dv.setInt16(44 + i * 4, Math.max(-1, Math.min(1, r.L[i])) * 32767, true); dv.setInt16(46 + i * 4, Math.max(-1, Math.min(1, r.R[i])) * 32767, true); }
    return new Blob([ab], { type: 'audio/wav' });
  }
  function rawBlob(r) { const n = r.L.length, a = new Float32Array(n * 2); for (let i = 0; i < n; i++) { a[i * 2] = r.L[i]; a[i * 2 + 1] = r.R[i]; } return new Blob([a.buffer]); }
  async function rawLoad(blob) { const a = new Float32Array(await blob.arrayBuffer()), n = a.length / 2, L = new Float32Array(n), R = new Float32Array(n); for (let i = 0; i < n; i++) { L[i] = a[i * 2]; R[i] = a[i * 2 + 1]; } return { L, R }; }
  function master(stems, gains) {
    const n = Math.min.apply(null, stems.map(x => x.L.length)), L = new Float32Array(n), R = new Float32Array(n);
    stems.forEach((x, k) => { const g = gains[k]; for (let i = 0; i < n; i++) { L[i] += x.L[i] * g; R[i] += x.R[i] * g; } });
    // glue compressor (RMS, 2.6:1 above -16 dBFS)
    let env = 0; const a = Math.exp(-1 / (SR * 0.012)), rl = Math.exp(-1 / (SR * 0.18)), thr = Math.pow(10, -16 / 20);
    let pk0 = 0; for (let i = 0; i < n; i++) pk0 = Math.max(pk0, Math.abs(L[i]), Math.abs(R[i]));
    const pre = 0.5 / (pk0 || 1);
    for (let i = 0; i < n; i++) { L[i] *= pre; R[i] *= pre; const x = Math.max(Math.abs(L[i]), Math.abs(R[i])); env = x > env ? a * env + (1 - a) * x : rl * env + (1 - rl) * x; const gr = env > thr ? Math.pow(env / thr, 1 / 2.6 - 1) : 1; L[i] *= gr; R[i] *= gr; }
    // make-up + look-ahead limiter at -1 dBFS
    let pk = 0; for (let i = 0; i < n; i++) pk = Math.max(pk, Math.abs(L[i]), Math.abs(R[i]));
    const mk = 1.9 / (pk || 1), look = Math.floor(SR * 0.005), ceil = 0.89, gl = new Float32Array(n); let g = 1;
    for (let i = n - 1; i >= 0; i--) { const x = Math.max(Math.abs(L[i]), Math.abs(R[i])) * mk; const need = x > ceil ? ceil / x : 1; gl[i] = need; }
    let m = 1; const dq = []; for (let i = 0; i < n; i++) { const j = i + look < n ? gl[i + look] : 1; m = Math.min(1, j); let w = 1; for (let k = 0; k <= look && i + k < n; k += 16) w = Math.min(w, gl[i + k]); const target = w; g = target < g ? target : g + (target - g) * 0.0009; L[i] = Math.tanh(L[i] * mk * g * 1.05) / 1.05; R[i] = Math.tanh(R[i] * mk * g * 1.05) / 1.05; }
    let p2 = 0, e = 0; for (let i = 0; i < n; i++) p2 = Math.max(p2, Math.abs(L[i]), Math.abs(R[i]));
    const nm = 0.9 / (p2 || 1), fade = Math.floor(SR * 1.2);
    for (let i = 0; i < n; i++) { const f = i > n - fade ? (n - i) / fade : 1; L[i] *= nm * f; R[i] *= nm * f; e += L[i] * L[i] + R[i] * R[i]; }
    return { L, R, sr: SR, secs: n / SR, rmsDb: 10 * Math.log10(e / (2 * n) + 1e-12) };
  }
  return { render, wav, mf, SC, deg, chordOf, rng, rawBlob, rawLoad, master, SR };
})();


/* Ellipsis library composer — every library sound is composed on the listener's device from a unique seed.
   Ten genres × ten each of beats, melodies and voices = 300 sounds; no pattern, progression+groove or motif repeats. */
globalThis.GEN = (function () {
  const R = seed => { let s = (seed * 2654435761) >>> 0 || 7; return () => { s ^= s << 13; s >>>= 0; s ^= s >> 17; s ^= s << 5; s >>>= 0; return s / 4294967296; }; };
  const pick = (r, a) => a[Math.floor(r() * a.length) % a.length];
  const PROGS = {
    pop: [[0,4,5,3],[5,3,0,4],[0,5,3,4],[3,4,2,5],[0,3,5,4],[5,4,3,4],[0,2,3,4],[3,0,4,5],[0,4,3,3],[5,3,4,0]],
    hiphop: [[0,5],[0,3],[1,4,0,0],[0,6,5,4],[0,0,5,5],[0,3,6,2],[0,4],[5,6,0,0],[0,2,5,3],[3,2,1,0]],
    trap: [[0,5,0,4],[0,1],[0,5,3,6],[0,6,5,6],[0,3,0,4],[0,0,1,0],[5,3,0,4],[0,2,0,1],[0,4,5,3],[0,6,0,5]],
    rnb: [[1,4,0,5],[3,2,1,0],[0,5,1,4],[3,4,2,5],[1,4,0,0],[5,1,4,0],[0,3,2,5],[3,3,0,0],[1,2,3,4],[0,1,3,4]],
    rock: [[0,6,3,0],[0,5,2,6],[0,3,4,3],[0,3,0,4],[0,6,5,6],[5,3,0,4],[0,4,5,3],[0,2,3,4],[0,6,3,3],[0,0,3,4]],
    afro: [[0,3],[0,4,5,3],[1,4],[0,5,3,4],[0,3,4,3],[5,3,0,4],[0,2,3,0],[0,4],[3,4,0,0],[0,6,3,4]],
    latin: [[0,5,2,6],[0,6,5,4],[0,3,4,0],[5,3,0,4],[0,5,3,4],[0,4,5,3],[0,2,5,6],[0,6,3,4],[3,4,0,5],[0,1,0,6]],
    house: [[0,0,5,3],[0,5,3,4],[5,3,0,4],[0,3],[0,4,5,3],[1,4,0,5],[0,6,5,6],[3,4,2,5],[0,2,3,4],[0,5]],
    lofi: [[1,4,0,5],[3,2,1,0],[0,5,1,4],[3,4,2,5],[1,4,0,3],[0,3,2,5],[5,1,4,0],[3,2,5,1],[0,2,1,4],[2,5,1,4]],
    country: [[0,3,4,0],[0,4,5,3],[0,3,0,4],[0,5,3,4],[3,0,4,0],[0,0,3,4],[0,4,3,0],[0,2,3,4],[0,5,1,4],[3,4,0,0]]
  };
  const G = {
    pop: { chords: ['pluck','saw','keys','bell','strum'], seventh: false, swing: 0, verb: 2.2, dens: 0.55 },
    hiphop: { chords: ['keys','organ','bell','pad'], seventh: true, swing: 0.07, verb: 1.8, dens: 0.45 },
    trap: { chords: ['bell','pad','pluck'], seventh: false, swing: 0, verb: 2.6, dens: 0.42 },
    rnb: { chords: ['keys','pad','bell'], seventh: true, swing: 0.04, verb: 2.8, dens: 0.4 },
    rock: { chords: ['power','strum','power'], seventh: false, swing: 0, verb: 1.6, dens: 0.6 },
    afro: { chords: ['keys','pluck','bell'], seventh: false, swing: 0.03, verb: 2, dens: 0.6 },
    latin: { chords: ['pluck','brass','keys'], seventh: false, swing: 0, verb: 2, dens: 0.6 },
    house: { chords: ['pad','saw','keys','organ'], seventh: true, swing: 0.02, verb: 2.6, dens: 0.5 },
    lofi: { chords: ['keys','bell','pad'], seventh: true, swing: 0.1, verb: 2.4, dens: 0.42 },
    country: { chords: ['strum','keys','strum'], seventh: false, swing: 0, verb: 1.8, dens: 0.55 }
  };
  const VOW = [['ah','oh','ee','oh'],['oo','ah','oo','eh'],['eh','ah','ee'],['oh','oo','ah'],['ee','eh','ah','oh']];

  function motif(r, m) {
    const g = G[m.g], dens = g.dens, ev = []; let d = pick(r, [0, 2, 4, 2, 4, 7]);
    const lo = m.k === 'voice' ? -2 : 0, hi = m.k === 'voice' ? 8 : 11;
    for (let half = 0; half < 2; half++) {
      for (let s = 0; s < 16; s++) {
        const strong = s % 4 === 0, off = s % 2 === 1;
        if (r() > (strong ? dens + 0.25 : off ? dens * 0.55 : dens)) continue;
        const st = r(); d += st < 0.4 ? 1 : st < 0.75 ? -1 : st < 0.85 ? 2 : st < 0.93 ? -2 : (r() < 0.5 ? 4 : -3);
        d = Math.max(lo, Math.min(hi, d));
        ev.push([half * 4 + s * 0.25, d]);
      }
    }
    if (ev.length < 5) ev.push([0, 4], [1.5, 2], [4, 0], [6, 2]);
    ev.sort((a, b) => a[0] - b[0]);
    return ev.map((e, i) => [e[0], e[1], Math.min(2, ((ev[i + 1] ? ev[i + 1][0] : 8) - e[0]) * 0.92)]);
  }

  function compose(a, m) {
    const r = R(m.seed), g = G[m.g], { T, spb, D, S, B, V, deg, SC } = a, sc = SC[m.scale] || SC.major;
    const prog = PROGS[m.g][m.prog % 10], root = m.root, bars = 8, sw = g.swing;
    const at = (b, x) => T(b, x + (Math.round(x * 4) % 2 === 1 ? sw : 0));
    const chordOf = b => { const d = prog[b % prog.length]; return [0, 2, 4].concat(g.seventh ? [6] : []).map(k => deg(sc, root - 12, d + k)); };
    const bassOf = b => deg(sc, root - 24, prog[b % prog.length]), nextBass = b => deg(sc, root - 24, prog[(b + 1) % prog.length]);
    const v = m.v;

    if (m.k === 'beat') {
      const kr = R(m.seed * 7 + 3);
      const extra = [[1.75], [2.5], [0.75, 2.5], [1.5, 2.25], [2.75], [1.75, 3.25], [0.5, 2.5], [2.25, 3.5], [1.25, 2.75], [2.5, 3.75]][v];
      const hat16 = kr() < 0.5, openAt = pick(kr, [3.5, 1.5, 2.5, 0.5]), roll = pick(kr, [1, 3, 5, 7]);
      for (let b = 0; b < bars; b++) {
        const fill = b === bars - 1, alt = b % 2 === 1;
        switch (m.g) {
          case 'trap':
            D.kick(at(b, 0), 1); extra.forEach(x => D.kick(at(b, x), 0.85)); if (alt) D.kick(at(b, 3.25), 0.6);
            D.clap(at(b, 2), 1); D.snare(at(b, 2), 0.45);
            for (let i = 0; i < 8; i++) D.hat(at(b, i * 0.5), i % 2 ? 0.55 : 0.85);
            if (b % 2 === roll % 2) for (let i = 0; i < 6; i++) D.hat(at(b, 3 + i / 6), 0.45 + i * 0.07);
            B.b808(at(b, 0), spb * 1.6, bassOf(b), 0.95, { drive: 2.4 + kr() }); B.b808(at(b, extra[0]), spb * 1.2, bassOf(b), 0.8, { to: nextBass(b), at: 0.6, glide: 0.1, drive: 2.6 });
            break;
          case 'hiphop': case 'lofi':
            D.kick(at(b, 0), m.g === 'lofi' ? 0.8 : 1); extra.forEach(x => D.kick(at(b, x), 0.75));
            D.snare(at(b, 1), m.g === 'lofi' ? 0.6 : 0.9); D.snare(at(b, 3), m.g === 'lofi' ? 0.6 : 0.9); if (alt) D.rim(at(b, 3.75), 0.4);
            for (let i = 0; i < 8; i++) D.hat(at(b, i * 0.5), (i % 2 ? 0.45 : 0.75) * (m.g === 'lofi' ? 0.7 : 1));
            if (hat16 && m.g === 'hiphop') D.hat(at(b, openAt), 0.6, true);
            B.sub(at(b, 0), spb * 1.4, bassOf(b), 0.8, { wave: 'triangle', cut: 600, grit: 0.2 }); B.sub(at(b, extra[0]), spb * 0.9, bassOf(b) + (alt ? 7 : 0), 0.65, { wave: 'triangle', cut: 600 });
            break;
          case 'rnb':
            D.kick(at(b, 0), 0.9); D.kick(at(b, extra[0]), 0.7); D.snare(at(b, 1), 0.7); D.snare(at(b, 3), 0.7); D.rim(at(b, 1), 0.4); D.rim(at(b, 3), 0.4);
            for (let i = 0; i < 16; i++) if (hat16 || i % 2 === 0) D.hat(at(b, i * 0.25), i % 4 === 0 ? 0.55 : 0.3);
            B.b808(at(b, 0), spb * 2.2, bassOf(b), 0.75, { drive: 1.4 }); B.b808(at(b, 2.5), spb * 1.2, bassOf(b), 0.6, { to: nextBass(b), at: 0.7, glide: 0.12, drive: 1.4 });
            break;
          case 'rock':
            D.kick(at(b, 0), 1); D.kick(at(b, 2), 0.9); extra.forEach(x => D.kick(at(b, x), 0.7));
            D.snare(at(b, 1), 1, { tone: 210, bright: 0.85 }); D.snare(at(b, 3), 1, { tone: 210, bright: 0.85 });
            for (let i = 0; i < 8; i++) D.hat(at(b, i * 0.5), i % 2 ? 0.55 : 0.8, b % 4 === 0 && i === 0);
            for (let i = 0; i < 8; i++) B.sub(at(b, i * 0.5), spb * 0.44, bassOf(b) + (i === 7 && alt ? 2 : 0), 0.78, { wave: 'sawtooth', cut: 900, grit: 0.55, decay: 0.15 });
            break;
          case 'afro':
            D.kick(at(b, 0), 1); D.kick(at(b, 2), 0.85); extra.forEach(x => D.kick(at(b, x), 0.6));
            [0, 0.75, 1.5, 2, 2.75, 3.5].forEach((x, i) => { if (i !== v % 6) D.rim(at(b, x), 0.65); });
            for (let i = 0; i < 16; i++) D.shaker(at(b, i * 0.25), i % 2 ? 0.75 : 0.4);
            D.clap(at(b, 3), 0.7); if (hat16) D.clap(at(b, 1), 0.55);
            [[0, 0], [0.75, 0], [1.5, 12], [2.5, 7], [3.25, 10]].forEach(([x, iv], i) => { if ((i + v) % 5 !== 4) D.log(at(b, x), bassOf(b) + 12 + iv, 0.8); });
            break;
          case 'latin':
            [0, 1, 2, 3].forEach(x => D.kick(at(b, x), 0.95)); [0.75, 1.5, 2.75, 3.5].forEach(x => { D.snare(at(b, x), 0.6); D.rim(at(b, x), 0.45); });
            for (let i = 0; i < 8; i++) D.hat(at(b, i * 0.5), 0.6); if (hat16) for (let i = 0; i < 16; i++) D.shaker(at(b, i * 0.25), 0.35);
            [[0, 0.7, 0], [1.5, 0.5, 0], [2, 0.7, 0], [3.5, 0.45, 7]].forEach(([x, l, iv]) => B.sub(at(b, x + (v % 2) * 0.25 * (x > 3 ? 1 : 0)), spb * l, bassOf(b) + iv, 0.8, { wave: 'sine', cut: 500 }));
            break;
          case 'house':
            [0, 1, 2, 3].forEach(x => D.kick(at(b, x), 1)); D.clap(at(b, 1)); D.clap(at(b, 3));
            [0.5, 1.5, 2.5, 3.5].forEach(x => D.hat(at(b, x), 0.75, true)); if (hat16) for (let i = 0; i < 16; i++) if (i % 2) D.hat(at(b, i * 0.25), 0.35);
            extra.forEach(x => D.rim(at(b, x), 0.5));
            [0.5, 1.5, 2.5, 3.5].forEach((x, i) => B.sub(at(b, x), spb * 0.42, bassOf(b) + (i === 3 && alt ? 12 : 0), 0.82, { wave: 'sawtooth', cut: 850, decay: 0.1, q: 6 }));
            break;
          case 'country':
            D.kick(at(b, 0), 0.9); D.kick(at(b, 2), 0.8); D.snare(at(b, 1), 0.7); D.snare(at(b, 3), 0.7); D.rim(at(b, 1), 0.4); D.rim(at(b, 3), 0.4);
            for (let i = 0; i < 16; i++) D.shaker(at(b, i * 0.25), i % 2 ? 0.6 : 0.3);
            [[0, 0], [1, 7], [2, 0], [3, alt ? 4 : 7]].forEach(([x, iv]) => B.sub(at(b, x), spb * 0.9, bassOf(b) + iv, 0.75, { wave: 'triangle', cut: 800 }));
            break;
          default:
            D.kick(at(b, 0), 1); D.kick(at(b, 2), 0.9); extra.forEach(x => D.kick(at(b, x), 0.7)); D.snare(at(b, 1), 0.85); D.snare(at(b, 3), 0.85); D.clap(at(b, 1), 0.6); D.clap(at(b, 3), 0.6);
            for (let i = 0; i < (hat16 ? 16 : 8); i++) D.hat(at(b, i * (hat16 ? 0.25 : 0.5)), i % 2 ? 0.45 : 0.75);
            for (let i = 0; i < 8; i++) B.sub(at(b, i * 0.5), spb * 0.4, bassOf(b) + (i === 7 ? 12 : 0), 0.7, { wave: 'sawtooth', cut: 700, decay: 0.12, grit: 0.3 });
        }
        if (fill) [3, 3.25, 3.5, 3.75].forEach((x, i) => D.tom(at(b, x), [210, 180, 150, 120][i], 0.5));
        chordOf(b).forEach(n => S.pad(T(b), spb * 4, n + 12, 0.28, { cut: 1300, wave: 'triangle' }));
      }
      return;
    }

    const notes = motif(r, m), vow = VOW[v % VOW.length];
    if (m.k === 'melody') {
      const inst = g.chords[v % g.chords.length], lead = pick(r, ['lead', 'bell', 'pluck', 'keys']);
      for (let b = 0; b < bars; b++) {
        const ch = chordOf(b), top = ch.concat([ch[0] + 12]);
        switch (inst) {
          case 'saw': top.forEach(n => S.supersaw(T(b), spb * 3.8, n, 0.6, { cut: 2600 + (b % 4) * 400 })); break;
          case 'pad': ch.forEach(n => S.pad(T(b), spb * 4, n + 12, 0.55, { cut: 2000 })); break;
          case 'keys': [[0, 1.4], [1.5, 0.9], [2.5, 0.9], [3.5, 0.45]].forEach(([x, l]) => ch.forEach(n => S.keys(at(b, x), spb * l, n + 12, 0.5, { index: 1.6 }))); break;
          case 'organ': top.forEach(n => S.organ(T(b), spb * 3.9, n, 0.45)); break;
          case 'bell': [0, 0.75, 1.5, 2.25, 3, 3.5].forEach((x, i) => S.bell(at(b, x), spb * 0.5, top[i % top.length] + 12, 0.5)); ch.forEach(n => S.pad(T(b), spb * 4, n + 12, 0.3, { cut: 1500 })); break;
          case 'brass': [[0, 0.4], [0.75, 0.3], [1.5, 0.5], [3, 0.3], [3.5, 0.4]].forEach(([x, l]) => ch.forEach(n => S.brass(at(b, x), spb * l, n + 12, 0.55))); break;
          case 'power': for (let i = 0; i < 8; i++) [ch[0], ch[0] + 7, ch[0] + 12].forEach(n => S.supersaw(at(b, i * 0.5), spb * (i % 4 === 3 ? 0.45 : 0.32), n, 0.6, { cut: 2200 })); break;
          case 'strum': [0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5].forEach((x, i) => { if ((i === 3 || i === 7) && (b + v) % 2) return; const down = i % 2 === 0, l = down ? top : top.slice().reverse(); l.forEach((n, k) => S.string(at(b, x + k * 0.012), n, down ? 0.55 : 0.32, { bright: 0.996, len: 0.9, pan: (k - 1.5) * 0.18 })); }); break;
          default: { const n0 = 4 / 0.25; for (let i = 0; i < n0; i++) if ((i + v) % 2 === 0 || i % 4 === 0) S.pluck(at(b, i * 0.25), spb * 0.22, top[[0, 1, 2, 3, 2, 1][i % 6]] + 12, 0.5, { cut: 4000, decay: 0.16, pan: i % 2 ? 0.3 : -0.3 }); }
        }
        if (b >= 2) notes.filter(n => n[0] >= (b % 2) * 4 && n[0] < (b % 2) * 4 + 4).forEach(([x, d, l]) => S[lead](at(b, x - (b % 2) * 4), spb * l, deg(sc, root, d) + 12, lead === 'bell' ? 0.45 : 0.36, { cut: 3800 }));
      }
      return;
    }

    for (let b = 0; b < bars; b++) {
      const phrase = notes.filter(n => n[0] >= (b % 2) * 4 && n[0] < (b % 2) * 4 + 4), up = b >= 4 ? 1 : 0;
      phrase.forEach(([x, d, l], i) => {
        const t = at(b, x - (b % 2) * 4), mid = deg(sc, root, d + (b === 6 && i === phrase.length - 1 ? 2 : 0));
        V.chop(t, spb * l, mid, 0.78, { vowel: vow[i % vow.length], from: i === 0 && (v % 3 === 0) ? mid - 2 : null });
        if (up) V.chop(t, spb * l, deg(sc, root, d + 2), 0.38, { vowel: vow[(i + 1) % vow.length], pan: 0.35 });
        if (b === 7) V.chop(t, spb * l, deg(sc, root, d - 3), 0.32, { vowel: 'oo', pan: -0.35 });
      });
    }
  }

  function spec(m) { const g = G[m.g]; return { seed: m.seed, bpm: m.bpm, bars: 8, tail: 1.6, duck: m.k === 'beat' ? 0.25 : 0, verb: g.verb, musicGain: 0.62, drumGain: 0.92, voxGain: 1, build: a => compose(a, m) }; }

  function plan(m) {
    const log = []; const rec = name => new Proxy({}, { get: (_, f) => (...args) => log.push(name + f + ':' + args.filter(x => typeof x !== 'object' || x === null).map(x => typeof x === 'number' ? Math.round(x * 1000) : x).join(',')) });
    const a = { T: (b, x) => (b * 4 + (x || 0)) * 60 / m.bpm, spb: 60 / m.bpm, D: rec('D'), S: rec('S'), B: rec('B'), V: rec('V'), FX: rec('F'), deg: (sc, root, d) => { const n = sc.length, o = Math.floor(d / n); return root + sc[((d % n) + n) % n] + 12 * o; }, SC: { major: [0,2,4,5,7,9,11], minor: [0,2,3,5,7,8,10], dorian: [0,2,3,5,7,9,10], mixo: [0,2,4,5,7,9,10], harm: [0,2,3,5,7,8,11], phryg: [0,1,3,5,7,8,10] } };
    compose(a, m);
    let h = 2166136261; const str = log.join('|'); for (let i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; }
    return { events: log.length, hash: h.toString(16) };
  }
  async function render(m) { const r = await HIT.render(spec(m)); return HIT.wav(HIT.master([r], [1])); }
  return { plan, render, spec, PROGS, G };
})();
