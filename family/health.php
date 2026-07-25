<?php
// family/health.php
// Fitbit / Google Health連携の家族向けヘルス見守り画面
// TODO: 既存の家族ログインチェックを入れてください。
declare(strict_types=1);

$personId = (int)($_GET['person_id'] ?? 1);
$date = $_GET['date'] ?? date('Y-m-d');

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kizuna-AI ヘルス見守り</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f7f7fb;color:#222;margin:0}.wrap{max-width:1080px;margin:0 auto;padding:24px}.header{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:20px}.title{font-size:24px;font-weight:800}.btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#222;color:#fff;text-decoration:none;border:0;cursor:pointer}.btn.secondary{background:#fff;color:#222;border:1px solid #ddd}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card{background:#fff;border:1px solid #e6e6ef;border-radius:16px;padding:16px;box-shadow:0 4px 16px rgba(0,0,0,.04)}.wide{grid-column:1/-1}.metric-label{color:#666;font-size:13px;margin-bottom:6px}.metric-value{font-size:26px;font-weight:800}.unit{font-size:14px;color:#666;margin-left:4px}.summary{line-height:1.8;white-space:pre-wrap}.error{background:#fff2f2;border:1px solid #ffd0d0;color:#8a1f1f;border-radius:12px;padding:12px;white-space:pre-wrap}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px}#map{height:360px;border-radius:14px;border:1px solid #ddd}.muted{color:#777}@media(max-width:780px){.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <div>
      <div class="title">ヘルス見守り</div>
      <div class="muted">Fitbit / Google Health連携</div>
    </div>
    <div>
      <a class="btn secondary" href="?person_id=<?=h($personId)?>&date=<?=h(date('Y-m-d', strtotime($date . ' -1 day')))?>">前日</a>
      <a class="btn secondary" href="?person_id=<?=h($personId)?>&date=<?=h(date('Y-m-d'))?>">今日</a>
      <!-- family/health.php から見て api は1階層上なので ../api/... にする -->
      <a class="btn" href="../api/google_health_connect.php?person_id=<?=h($personId)?>">Google Healthと連携</a>
    </div>
  </div>

  <?php if (isset($_GET['connected'])): ?>
    <div class="card wide" style="margin-bottom:14px">Google Health連携が完了しました。データが表示されない場合は、同期ジョブ <code>jobs/sync_google_health.php</code> を実行してください。</div>
  <?php endif; ?>

  <div id="status" class="card wide">読み込み中...</div>

  <div class="grid" style="margin-top:14px">
    <div class="card"><div class="metric-label">歩数</div><div class="metric-value" id="steps">-</div></div>
    <div class="card"><div class="metric-label">睡眠</div><div class="metric-value" id="sleep">-</div></div>
    <div class="card"><div class="metric-label">安静時心拍</div><div class="metric-value"><span id="rhr">-</span><span class="unit">bpm</span></div></div>
    <div class="card"><div class="metric-label">平均心拍</div><div class="metric-value"><span id="ahr">-</span><span class="unit">bpm</span></div></div>
    <div class="card"><div class="metric-label">距離</div><div class="metric-value"><span id="distance">-</span><span class="unit">m</span></div></div>
    <div class="card"><div class="metric-label">最小心拍</div><div class="metric-value"><span id="minhr">-</span><span class="unit">bpm</span></div></div>
    <div class="card"><div class="metric-label">最大心拍</div><div class="metric-value"><span id="maxhr">-</span><span class="unit">bpm</span></div></div>
    <div class="card"><div class="metric-label">SpO2</div><div class="metric-value"><span id="spo2">-</span><span class="unit">%</span></div></div>

    <div class="card wide"><h2>AI見守りコメント</h2><div class="summary" id="aiSummary">まだサマリがありません。</div></div>
    <div class="card wide"><h2>運動・散歩履歴</h2><table><thead><tr><th>時間</th><th>種類</th><th>距離</th><th>歩数</th><th>平均心拍</th><th>ルート</th></tr></thead><tbody id="exerciseRows"><tr><td colspan="6" class="muted">読み込み中...</td></tr></tbody></table></div>
    <div class="card wide"><h2>運動時GPSルート</h2><div id="map"></div><div class="muted" style="margin-top:8px">※ Charge 6のGPSルートは、運動として記録された場合のみ表示されます。常時現在地ではありません。</div></div>
  </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const personId = <?=json_encode($personId)?>;
const date = <?=json_encode($date)?>;

function esc(v){return String(v??'').replace(/[&<>"']/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]))}
function sleepFmt(m){m=Number(m||0); if(!m)return '-'; return Math.floor(m/60)+'時間'+(m%60)+'分'}
function trTime(s,e){if(!s)return '-'; const a=new Date(String(s).replace(' ','T')), b=e?new Date(String(e).replace(' ','T')):null; const f=d=>String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0'); return b?f(a)+'〜'+f(b):f(a)}
function setText(id, value){document.getElementById(id).textContent = (value === null || value === undefined || value === '') ? '-' : value;}

let map = L.map('map').setView([35.681236,139.767125],13);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
let routeLayer = null;

async function fetchJson(url){
  const res = await fetch(url, {cache: 'no-store'});
  const text = await res.text();
  if(!res.ok){ throw new Error(`API error ${res.status}: ${text}`); }
  try { return JSON.parse(text); }
  catch(e){ throw new Error(`JSON parse error: ${text}`); }
}

async function loadHealth(){
  try {
    // ローカルのサブディレクトリ配置でも動くように ../api/... を使う
    const data = await fetchJson(`../api/health_current.php?person_id=${personId}&date=${encodeURIComponent(date)}`);
    if(!data.ok){ throw new Error(data.error || 'データ取得に失敗しました'); }

    const c = data.connection;
    const statusEl = document.getElementById('status');
    statusEl.className = 'card wide';
    statusEl.textContent = c
      ? `連携状態: ${c.status} / 最終同期: ${c.last_synced_at || '-'}${c.last_error ? ' / エラー: ' + c.last_error : ''}`
      : '未連携です。「Google Healthと連携」から許可してください。';

    const d = data.daily || {};
    document.getElementById('steps').innerHTML = d.steps ? Number(d.steps).toLocaleString() + '<span class="unit">歩</span>' : '-';
    document.getElementById('sleep').textContent = sleepFmt(d.sleep_minutes);
    setText('rhr', d.resting_heart_rate);
    setText('ahr', d.avg_heart_rate);
    setText('minhr', d.min_heart_rate);
    setText('maxhr', d.max_heart_rate);
    setText('spo2', d.spo2_avg);
    setText('distance', d.distance_meters);
    document.getElementById('aiSummary').textContent = data.ai_summary?.summary_text || 'まだサマリがありません。cronで jobs/generate_health_ai_summary.php を実行してください。';

    const exerciseRows = document.getElementById('exerciseRows');
    exerciseRows.innerHTML = '';
    const exs = data.exercises || [];
    if(!exs.length){
      exerciseRows.innerHTML = '<tr><td colspan="6" class="muted">この日の運動記録はありません。</td></tr>';
      return;
    }
    for(const ex of exs){
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${esc(trTime(ex.started_at,ex.ended_at))}</td><td>${esc(ex.display_name||ex.exercise_type||'-')}</td><td>${ex.distance_meters?esc(Math.round(ex.distance_meters)+'m'):'-'}</td><td>${ex.steps?esc(Number(ex.steps).toLocaleString()+'歩'):'-'}</td><td>${ex.avg_heart_rate?esc(ex.avg_heart_rate+' bpm'):'-'}</td><td>${Number(ex.has_gps)===1?`<button class="btn secondary" onclick="showRoute(${Number(ex.id)})">表示</button>`:'<span class="muted">なし</span>'}</td>`;
      exerciseRows.appendChild(tr);
    }
  } catch (error) {
    console.error(error);
    const statusEl = document.getElementById('status');
    statusEl.className = 'card wide error';
    statusEl.textContent = 'データを取得できませんでした。\n' + error.message;
    document.getElementById('exerciseRows').innerHTML = '<tr><td colspan="6" class="muted">取得エラー</td></tr>';
  }
}

async function showRoute(id){
  try {
    const data = await fetchJson(`../api/exercise_route.php?session_id=${id}`);
    if(!data.ok || !data.points?.length){ alert('ルートデータがありません'); return; }
    const latlngs = data.points.map(p=>[Number(p.latitude), Number(p.longitude)]);
    if(routeLayer) map.removeLayer(routeLayer);
    routeLayer = L.polyline(latlngs).addTo(map);
    map.fitBounds(routeLayer.getBounds(), {padding:[20,20]});
  } catch (error) {
    console.error(error);
    alert('ルート取得に失敗しました。' + error.message);
  }
}

loadHealth();
</script>
</body>
</html>
