<?php
// One-time seed so the network is alive on day one: 200 creators, 46 tracks, rooms, chat, plays.
// Called by GET /api/setup only when the users table is empty. Seed accounts cannot log in (unusable password hash).
declare(strict_types=1);

function seed_all(): int {
  mt_srand(20260927);
  $first = ['Ada','Rue','Jonathan','Ines','Yuki','Kwame','Nia','Sami','Tomas','Lena','Priya','Mateo','Amara','Kofi','Sofia','Hiro','Zara','Luca','Maya','Omar','Freya','Thabo','Isla','Kenji','Leila','Diego','Anya','Tariq','Noor','Emeka','Camila','Rafael','Mina','Arjun','Chloe','Bilal','Sana','Joao','Elif','Kai'];
  $last = ['Oyelaran','Okonkwo','Reid','Duarte','Mori','Boateng','Bell','Fehr','Vey','Strand','Raman','Silva','Diallo','Mensah','Rossi','Tanaka','Ahmed','Moretti','Chen','Haddad','Larsen','Nkosi','Walsh','Sato','Karimi','Ortega','Petrova','Aziz','Rahman','Eze','Lopes','Costa','Park','Nair','Dubois','Qureshi','Malik','Pereira','Yilmaz','Kealoha'];
  $places = [['Lagos','NG',1,'yo'],['Houston','US',-5,'en'],['London','UK',0,'en'],['Porto','PT',0,'pt'],['Osaka','JP',9,'ja'],['Accra','GH',0,'en'],['Detroit','US',-4,'en'],['Berlin','DE',2,'de'],['Prague','CZ',2,'cs'],['Stockholm','SE',2,'sv'],['Mumbai','IN',5,'hi'],['São Paulo','BR',-3,'pt'],['Dakar','SN',0,'fr'],['Nairobi','KE',3,'sw'],['Seoul','KR',9,'ko'],['Mexico City','MX',-6,'es'],['Paris','FR',2,'fr'],['Johannesburg','ZA',2,'en'],['Toronto','CA',-4,'en'],['Istanbul','TR',3,'tr'],['Kingston','JM',-5,'en'],['Melbourne','AU',10,'en']];
  $roles = ['Producer','Vocalist','Songwriter','Strings','Keys','Drums','Bass','Guitar','Mixing','DJ'];
  $noLogin = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT) . '!';
  $t0 = now();

  db()->beginTransaction();
  $uids = [];
  for ($i = 0; $i < 200; $i++) {
    $name = $first[$i % count($first)] . ' ' . $last[($i * 7 + intdiv($i, count($first))) % count($last)];
    $pl = $places[$i % count($places)];
    q('INSERT INTO users (email, pass, name, handle, place, cc, tz, lang, roles, bio, premium, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
      'seed' . $i . '@seed.ellipsis.invalid', $noLogin, $name, 'seed' . $i . strtolower(substr($name, 0, 3)),
      $pl[0], $pl[1], $pl[2], $pl[3], $roles[$i % 10] . ',' . $roles[($i * 3 + 1) % 10],
      'Making music from ' . $pl[0] . '. Send stems, not prompts.', (int)($i % 5 === 0), $t0 - mt_rand(86400, 86400 * 400)]);
    $uids[] = lastid();
  }
  foreach ($uids as $k => $a) for ($j = 1; $j <= 6; $j++) q(upsert_ignore() . ' INTO follows (follower_id, followee_id, created) VALUES (?,?,?)', [$a, $uids[($k * 13 + $j * 17) % 200], $t0]);

  $genres = [['Lo-fi','lofi','Rainy','Nujabes'],['Afrobeats','afrobeats','Late night','Tems'],['Neo-soul','neosoul','Warm','Cleo Sol'],['Drill','drill','Cold','Central Cee'],['House','house','Euphoric','Fred again..'],['Ambient','ambient','Weightless','Sampha'],['R&B','neosoul','After hours','Frank Ocean']];
  $titles = ['Four in the Morning','Nightbus','Home Is With You','Paper Radio','Cold Kitchen','Slow Ferry','Cassette Sunday','Rooftop Static','Blue Hour Loop','Wet Pavement','Tram at Six','Second Chorus','Harmattan','Low Tide','Glass Hours','Mango Season','Last Train South','Soft Machinery','Kerosene','Orbit Café','Lantern','Afterglow Club','Salt Road','Paper Planes Home','Sunday Service','Neon Harbour','Quiet Riot','Moth Light','Balcony Weather','Night Market','Driftwood','Tokyo Rain','Red Dust','Satellite Heart','Copper Sky','Porch Light','Undertow','Honey Static','Midnight Ferry','Velvet Room','Crosstown','Grain','Brass Monkey','Lilac','Echo Park','Feathers'];
  $needs = [['Strings'],['Bass'],['Vocal'],['Keys','Drums'],['Lyrics'],['Mixing'],['Guitar','Vocal']];
  $states = ['released','released','open','building','released','seed','open'];
  $keys = ['F minor','A minor','E♭ major','C♯ minor','G minor','D major','B♭ minor'];
  $msgs = ['Pushed the vocal comp — take 3 is the one.','Bass is tracked and dry. Splits look fair to me — signing.','Left a note at 0:41, the perc can drop for two bars.','Can you bounce a version without the pad?','Key holds — I will put a topline in tonight, my evening.','Love the tail on the last line. Keep it.'];

  foreach ($titles as $k => $title) {
    $g = $genres[$k % count($genres)]; $owner = $uids[($k * 11) % 200]; $st = $states[$k % count($states)];
    $isOpen = $st === 'open' || $st === 'seed';
    q('INSERT INTO tracks (owner_id, title, genre, mood, like_ref, bpm, music_key, state, needs, offer_pct, tags, kind, file, spike_base, published, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
      $owner, $title, $g[0], $g[2], $g[3], [82, 104, 88, 142, 124, 70, 78][$k % 7], $keys[$k % 7], $st,
      json_encode($isOpen ? $needs[$k % count($needs)] : []), $isOpen ? [10, 15, 20, 25][$k % 4] : 0,
      json_encode([$g[0], $g[2], $g[3], 'human-made', 'global']), $k % 3 === 2 ? 'instrumental' : 'song', $g[1],
      mt_rand(120, 2600), $st === 'released' ? $t0 - $k * 3600 : 0, $t0 - mt_rand(600, 86400 * 30)]);
    $tid = lastid();
    $crew = [$owner, $uids[($k * 11 + 23) % 200], $uids[($k * 11 + 57) % 200]];
    $split = $st === 'seed' ? [100, 0, 0] : [50, 30, 20];
    foreach ($crew as $c => $uid) q(upsert_ignore() . ' INTO contributors (track_id, user_id, pct, signed, role, joined) VALUES (?,?,?,?,?,?)', [$tid, $uid, $split[$c], (int)($st === 'released'), $c ? 'Contributor' : 'Owner', $t0]);
    q('INSERT INTO versions (track_id, user_id, label, note, created) VALUES (?,?,?,?,?)', [$tid, $owner, 'v1', 'First sketch', $t0 - 86400]);
    for ($m = 0; $m < 4; $m++) q('INSERT INTO messages (track_id, user_id, text, lang, created) VALUES (?,?,?,?,?)', [$tid, $crew[$m % 3], $msgs[($k + $m) % count($msgs)], 'en', $t0 - (4 - $m) * 900]);
    $n = mt_rand(40, 400);
    for ($p = 0; $p < $n; $p++) {
      $prem = (int)(mt_rand(0, 99) < 22);
      q('INSERT INTO plays (track_id, user_id, premium, ms, created) VALUES (?,?,?,?,?)', [$tid, $uids[mt_rand(0, 199)], $prem, mt_rand(15000, 200000), $t0 - mt_rand(0, 86400 * 28)]);
      if (!$prem) q('INSERT INTO ad_impressions (track_id, user_id, cpm_micros, created) VALUES (?,?,?,?)', [$tid, null, (int)cfg('default_cpm'), $t0 - mt_rand(0, 86400 * 28)]);
    }
  }
  db()->commit();
  return count($uids);
}
