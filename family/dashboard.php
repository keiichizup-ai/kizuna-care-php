<?php
// family/dashboard.php
// 会話サマリ + Fitbit/Google Health見守りの統合ダッシュボード。
// TODO: 既存の家族ログインチェックを入れてください。
declare(strict_types=1);

$personId = (int)($_GET['person_id'] ?? 1);

// 日付は必ず日本時間で扱う。
// date('Y-m-d') や JavaScript の toISOString() は、環境によってUTC基準になり、
// 日本時間の深夜帯に「今日」が前日になることがあります。
$tz = new DateTimeZone('Asia/Tokyo');
$todayJst = (new DateTime('now', $tz))->format('Y-m-d');
$yesterdayJst = (new DateTime('yesterday', $tz))->format('Y-m-d');

$requestedDate = $_GET['date'] ?? $todayJst;
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$requestedDate)
    ? (string)$requestedDate
    : $todayJst;

$currentDateObj = DateTime::createFromFormat('!Y-m-d', $date, $tz);
if (!$currentDateObj) {
    $currentDateObj = new DateTime('now', $tz);
    $date = $todayJst;
}

$prevDate = (clone $currentDateObj)->modify('-1 day')->format('Y-m-d');

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kizuna Care 家族見守りダッシュボード</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
:root{--bg:#f6f7fb;--card:#fff;--text:#202124;--muted:#6b7280;--line:#e7e8ee;--main:#2f6fed;--green:#14845f;--yellow:#9a6700;--red:#b42318;--gray:#667085;--soft-green:#eaf8f1;--soft-yellow:#fff8df;--soft-red:#fff1f0;--soft-gray:#f2f4f7;}
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text);margin:0}.wrap{max-width:1180px;margin:0 auto;padding:24px}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}.brand{font-size:13px;font-weight:800;letter-spacing:.08em;color:var(--main);text-transform:uppercase}.title{font-size:28px;font-weight:900;line-height:1.25;margin-top:4px}.subtitle{color:var(--muted);margin-top:6px}.actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border-radius:12px;background:#222;color:#fff;text-decoration:none;border:0;cursor:pointer;font-weight:700;font-size:14px}.btn.secondary{background:#fff;color:#222;border:1px solid var(--line)}.btn.small{padding:7px 10px;font-size:13px;border-radius:10px}.notice{background:#ecfdf3;border:1px solid #abefc6;color:#067647;border-radius:14px;padding:12px 14px;margin:12px 0}.sync-status{font-size:13px;color:var(--muted);align-self:center;max-width:260px}.btn.syncing{opacity:.7;cursor:wait}.grid{display:grid;grid-template-columns:1.35fr .85fr;gap:16px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:0 8px 24px rgba(16,24,40,.04)}.card h2{font-size:18px;margin:0 0 12px}.card h3{font-size:15px;margin:0 0 8px}.wide{grid-column:1/-1}.status-card{display:flex;gap:14px;align-items:center}.status-icon{width:52px;height:52px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900}.status-green{background:var(--soft-green);color:var(--green)}.status-yellow{background:var(--soft-yellow);color:var(--yellow)}.status-red{background:var(--soft-red);color:var(--red)}.status-gray{background:var(--soft-gray);color:var(--gray)}.status-label{font-size:24px;font-weight:900}.muted{color:var(--muted)}.summary{line-height:1.85;white-space:pre-wrap;font-size:15px}.metric{background:#fff;border:1px solid var(--line);border-radius:18px;padding:14px}.metric-label{color:var(--muted);font-size:12px;margin-bottom:6px}.metric-value{font-size:24px;font-weight:900}.unit{font-size:13px;color:var(--muted);margin-left:3px}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.tab{border:1px solid var(--line);background:#fff;border-radius:999px;padding:9px 13px;cursor:pointer;font-weight:700;color:#444}.tab.active{background:#222;color:#fff;border-color:#222}.tab-panel{display:none}.tab-panel.active{display:block}.list{display:flex;flex-direction:column;gap:10px}.highlight{background:#f9fafb;border:1px solid var(--line);border-radius:14px;padding:12px}.message{border-bottom:1px solid var(--line);padding:10px 0}.role{display:inline-block;min-width:56px;font-weight:800}.role.user{color:#7a4a00}.role.assistant{color:#2353c4}.time{font-size:12px;color:var(--muted);margin-left:8px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;font-size:14px}#map{height:360px;border-radius:16px;border:1px solid var(--line);overflow:hidden}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.field label{display:block;font-size:12px;font-weight:800;color:#555;margin-bottom:5px}.input,.textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:10px;font:inherit;background:#fff}.textarea{min-height:72px;resize:vertical}.error{background:#fff2f2;border:1px solid #ffd0d0;color:#8a1f1f;border-radius:14px;padding:12px;white-space:pre-wrap}.loading{animation:pulse 1.4s ease-in-out infinite}@keyframes pulse{0%,100%{opacity:.55}50%{opacity:1}}@media(max-width:900px){.grid{grid-template-columns:1fr}.metrics{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.wrap{padding:16px}.title{font-size:23px}.metrics,.form-grid{grid-template-columns:1fr}.actions{justify-content:flex-start}.status-card{align-items:flex-start}.status-label{font-size:21px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <div class="brand">Kizuna Care</div>
      <div class="title"><span id="personTitle">利用者さん</span>の今日の様子</div>
      <div class="subtitle">会話ログとFitbit / Google Healthデータをまとめた家族向け見守りダッシュボード</div>
    </div>
    <div class="actions">
      <a class="btn secondary" href="?person_id=<?=h($personId)?>&date=<?=h($prevDate)?>">前日</a>
      <a class="btn secondary" href="?person_id=<?=h($personId)?>&date=<?=h($yesterdayJst)?>">昨日</a>
      <a class="btn secondary" href="?person_id=<?=h($personId)?>&date=<?=h($todayJst)?>">今日</a>
      <a class="btn" href="../api/google_health_connect.php?person_id=<?=h($personId)?>">Google Health連携</a>
      <button id="syncHealthBtn" type="button" class="btn secondary">Google Healthデータを更新</button>
      <span id="syncHealthStatus" class="sync-status"></span>
    </div>
  </div>

  <?php if (isset($_GET['connected'])): ?>
    <div class="notice">Google Health連携が完了しました。データが表示されない場合は、同期ジョブ <code>jobs/sync_google_health.php</code> を実行してください。</div>
  <?php endif; ?>

  <div id="errorBox" class="error" style="display:none"></div>

  <div class="grid">
    <section class="card">
      <div class="status-card">
        <div id="statusIcon" class="status-icon status-gray">…</div>
        <div>
          <div class="muted">総合ステータス</div>
          <div id="statusLabel" class="status-label loading">読み込み中</div>
          <div id="statusNote" class="muted">データを取得しています。</div>
        </div>
      </div>
    </section>

    <section class="card">
      <h2>利用者名</h2>
      <div id="nameText" style="font-size:20px;font-weight:900">未登録</div>
      <div class="muted" style="margin-top:4px">表示名は姓・名から作成します。続柄は推測しません。</div>
      <button class="btn secondary small" style="margin-top:12px" onclick="showTab('settings')">名前を登録・編集</button>
    </section>

    <section class="wide metrics">
      <div class="metric"><div class="metric-label">会話ログ</div><div class="metric-value"><span id="conversationCount">-</span><span class="unit">件</span></div></div>
      <div class="metric"><div class="metric-label">歩数</div><div class="metric-value"><span id="steps">-</span><span class="unit">歩</span></div></div>
      <div class="metric"><div class="metric-label">睡眠</div><div class="metric-value" id="sleep">-</div></div>
      <div class="metric"><div class="metric-label">平均心拍</div><div class="metric-value"><span id="avgHr">-</span><span class="unit">bpm</span></div></div>
    </section>

    <section class="card wide">
      <h2>きずなちゃんからの見守りメモ</h2>
      <div id="summary" class="summary loading">読み込み中...</div>
    </section>
  </div>

  <div class="tabs">
    <button class="tab active" data-tab="overview" onclick="showTab('overview')">概要</button>
    <button class="tab" data-tab="conversation" onclick="showTab('conversation')">会話</button>
    <button class="tab" data-tab="health" onclick="showTab('health')">ヘルス</button>
    <button class="tab" data-tab="route" onclick="showTab('route')">運動ルート</button>
    <button class="tab" data-tab="settings" onclick="showTab('settings')">設定</button>
  </div>

  <div id="panel-overview" class="tab-panel active">
    <div class="grid">
      <section class="card">
        <h2>会話のハイライト</h2>
        <div id="highlights" class="list"><div class="highlight loading">読み込み中...</div></div>
      </section>
      <section class="card">
        <h2>同期状態</h2>
        <div id="syncStatus" class="summary">-</div>
      </section>
    </div>
  </div>

  <div id="panel-conversation" class="tab-panel">
    <section class="card">
      <h2>最近の会話ログ</h2>
      <div id="messages" class="summary muted">読み込み中...</div>
    </section>
  </div>

  <div id="panel-health" class="tab-panel">
    <section class="card">
      <h2>今日のヘルス状態</h2>
      <div class="metrics">
        <div class="metric"><div class="metric-label">距離</div><div class="metric-value"><span id="distance">-</span><span class="unit">m</span></div></div>
        <div class="metric"><div class="metric-label">安静時心拍</div><div class="metric-value"><span id="rhr">-</span><span class="unit">bpm</span></div></div>
        <div class="metric"><div class="metric-label">最小心拍</div><div class="metric-value"><span id="minHr">-</span><span class="unit">bpm</span></div></div>
        <div class="metric"><div class="metric-label">最大心拍</div><div class="metric-value"><span id="maxHr">-</span><span class="unit">bpm</span></div></div>
        <div class="metric"><div class="metric-label">SpO2</div><div class="metric-value"><span id="spo2">-</span><span class="unit">%</span></div></div>
        <div class="metric"><div class="metric-label">HRV</div><div class="metric-value"><span id="hrv">-</span></div></div>
        <div class="metric"><div class="metric-label">呼吸数</div><div class="metric-value"><span id="respiratory">-</span></div></div>
        <div class="metric"><div class="metric-label">対象日</div><div class="metric-value" style="font-size:18px" id="targetDate">-</div></div>
      </div>
    </section>
  </div>

  <div id="panel-route" class="tab-panel">
    <section class="card">
      <h2>運動・散歩履歴</h2>
      <table><thead><tr><th>時間</th><th>種類</th><th>距離</th><th>歩数</th><th>平均心拍</th><th>ルート</th></tr></thead><tbody id="exerciseRows"><tr><td colspan="6" class="muted">読み込み中...</td></tr></tbody></table>
      <h2 style="margin-top:18px">運動時GPSルート</h2>
      <div id="map"></div>
      <div class="muted" style="margin-top:8px">※ Charge 6のGPSルートは、運動として記録された場合のみ表示されます。常時現在地ではありません。</div>
    </section>
  </div>

  <div id="panel-settings" class="tab-panel">
    <section class="card">
      <h2>利用者名の登録</h2>
      <div class="form-grid">
        <div class="field"><label>姓</label><input id="lastName" class="input" placeholder="例：山田"></div>
        <div class="field"><label>名</label><input id="firstName" class="input" placeholder="例：花子"></div>
        <div class="field"><label>続柄メモ 任意</label><input id="relationLabel" class="input" placeholder="例：母、父、祖母など。表示の補助用"></div>
        <div class="field"><label>person_id</label><input class="input" value="<?=h($personId)?>" disabled></div>
      </div>
      <div class="field" style="margin-top:10px"><label>メモ 任意</label><textarea id="personMemo" class="textarea" placeholder="家族内で共有したいメモがあれば入力"></textarea></div>
      <button class="btn" style="margin-top:12px" onclick="saveProfile()">保存</button>
      <span id="saveResult" class="muted" style="margin-left:10px"></span>
    </section>
  </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const personId = <?=json_encode($personId)?>;
const date = <?=json_encode($date)?>;
let latestData = null;

function esc(v){return String(v??'').replace(/[&<>"']/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]))}
function val(v, fallback='-'){return (v===null||v===undefined||v==='') ? fallback : v}
function num(v){return (v===null||v===undefined||v==='') ? '-' : Number(v).toLocaleString()}
function sleepFmt(m){m=Number(m||0); if(!m)return '-'; return Math.floor(m/60)+'時間'+(m%60)+'分'}
function timeFmt(s){if(!s)return '-'; const d=new Date(String(s).replace(' ','T')); if(Number.isNaN(d.getTime())) return s; return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')}
function trTime(s,e){if(!s)return '-'; return e ? timeFmt(s)+'〜'+timeFmt(e) : timeFmt(s)}
function setText(id,value){const el=document.getElementById(id); if(el) el.textContent = val(value)}

function showTab(name){
  document.querySelectorAll('.tab').forEach(t=>t.classList.toggle('active', t.dataset.tab===name));
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.toggle('active', p.id==='panel-'+name));
  if(name === 'route') setTimeout(()=>map.invalidateSize(), 80);
}

let map = L.map('map').setView([35.681236,139.767125],13);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
let routeLayer = null;

async function fetchJson(url, options={}){
  const res = await fetch(url, {...options, cache:'no-store'});
  const text = await res.text();
  if(!res.ok) throw new Error(`API error ${res.status}: ${text}`);
  try{return JSON.parse(text)}catch(e){throw new Error(`JSON parse error: ${text}`)}
}

function statusIcon(level){
  if(level==='green') return '✓';
  if(level==='yellow') return '!';
  if(level==='red') return '!';
  return '…';
}

function renderProfile(data){
  document.getElementById('personTitle').textContent = data.person_label || '利用者さん';
  document.getElementById('nameText').textContent = data.display_name || '未登録';
  const p = data.profile || {};
  document.getElementById('lastName').value = p.last_name || '';
  document.getElementById('firstName').value = p.first_name || '';
  document.getElementById('relationLabel').value = p.relation_label || '';
  document.getElementById('personMemo').value = p.memo || '';
}

function renderDashboard(data){
  latestData = data;
  renderProfile(data);

  const st = data.status || {level:'gray',label:'データ待ち',note:''};
  const icon = document.getElementById('statusIcon');
  icon.className = 'status-icon status-' + (st.level || 'gray');
  icon.textContent = statusIcon(st.level);
  document.getElementById('statusLabel').classList.remove('loading');
  document.getElementById('statusLabel').textContent = st.label || '-';
  document.getElementById('statusNote').textContent = st.note || '';

  const d = data.daily || {};
  setText('conversationCount', data.conversation?.count ?? 0);
  setText('steps', d.steps ? Number(d.steps).toLocaleString() : '-');
  setText('sleep', sleepFmt(d.sleep_minutes));
  setText('avgHr', d.avg_heart_rate);
  setText('distance', d.distance_meters);
  setText('rhr', d.resting_heart_rate);
  setText('minHr', d.min_heart_rate);
  setText('maxHr', d.max_heart_rate);
  setText('spo2', d.spo2_avg);
  setText('hrv', d.hrv_value);
  setText('respiratory', d.respiratory_rate);
  setText('targetDate', data.date || date);

  const summary = document.getElementById('summary');
  summary.classList.remove('loading');
  summary.textContent = data.ai_summary?.summary_text || 'まだ統合サマリがありません。健康データ同期後に jobs/generate_health_ai_summary.php を実行してください。';

  const c = data.connection;
  document.getElementById('syncStatus').textContent = c
    ? `Google Health: ${c.status}\n最終同期: ${c.last_synced_at || '-'}${c.last_error ? '\nエラー: ' + c.last_error : ''}`
    : 'Google Healthは未連携です。右上の「Google Health連携」から許可してください。';

  const highlights = data.conversation?.highlights || [];
  document.getElementById('highlights').innerHTML = highlights.length
    ? highlights.map(x=>`<div class="highlight">${esc(x)}</div>`).join('')
    : '<div class="highlight muted">会話ハイライトはまだありません。</div>';

  renderMessages(data.conversation?.recent_messages || []);
  renderExercises(data.exercises || []);
}

function renderMessages(rows){
  const box = document.getElementById('messages');
  if(!rows.length){box.innerHTML='<span class="muted">この日の会話ログはありません。</span>'; return;}
  box.innerHTML = rows.map(r=>{
    const role = (r.role === 'assistant' || r.role === 'ai') ? 'AI' : '本人';
    const cls = role === 'AI' ? 'assistant' : 'user';
    return `<div class="message"><span class="role ${cls}">${role}</span><span class="time">${esc(timeFmt(r.created_at))}</span><div>${esc(r.content)}</div></div>`;
  }).join('');
}

function renderExercises(exs){
  const tbody = document.getElementById('exerciseRows');
  tbody.innerHTML = '';
  if(!exs.length){tbody.innerHTML='<tr><td colspan="6" class="muted">この日の運動記録はありません。</td></tr>'; return;}
  for(const ex of exs){
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${esc(trTime(ex.started_at,ex.ended_at))}</td><td>${esc(ex.display_name||ex.exercise_type||'-')}</td><td>${ex.distance_meters?esc(Math.round(ex.distance_meters)+'m'):'-'}</td><td>${ex.steps?esc(Number(ex.steps).toLocaleString()+'歩'):'-'}</td><td>${ex.avg_heart_rate?esc(ex.avg_heart_rate+' bpm'):'-'}</td><td>${Number(ex.has_gps)===1?`<button class="btn secondary small" onclick="showRoute(${Number(ex.id)})">表示</button>`:'<span class="muted">なし</span>'}</td>`;
    tbody.appendChild(tr);
  }
}

async function showRoute(id){
  try{
    const data = await fetchJson(`../api/exercise_route.php?session_id=${id}`);
    if(!data.ok || !data.points?.length){alert('ルートデータがありません'); return;}
    const latlngs = data.points.map(p=>[Number(p.latitude), Number(p.longitude)]);
    if(routeLayer) map.removeLayer(routeLayer);
    routeLayer = L.polyline(latlngs).addTo(map);
    map.fitBounds(routeLayer.getBounds(), {padding:[20,20]});
    showTab('route');
  }catch(e){console.error(e); alert('ルート取得に失敗しました。' + e.message);}
}

async function saveProfile(){
  const payload = {
    person_id: personId,
    last_name: document.getElementById('lastName').value,
    first_name: document.getElementById('firstName').value,
    relation_label: document.getElementById('relationLabel').value,
    memo: document.getElementById('personMemo').value,
  };
  const result = document.getElementById('saveResult');
  result.textContent = '保存中...';
  try{
    const data = await fetchJson('../api/person_profile.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(payload)
    });
    if(!data.ok) throw new Error(data.error || '保存に失敗しました');
    result.textContent = '保存しました';
    await loadDashboard();
  }catch(e){console.error(e); result.textContent = '保存エラー: ' + e.message;}
}

async function loadDashboard(){
  try{
    const data = await fetchJson(`../api/dashboard_current.php?person_id=${personId}&date=${encodeURIComponent(date)}`);
    if(!data.ok) throw new Error(data.error || 'データ取得に失敗しました');
    document.getElementById('errorBox').style.display = 'none';
    renderDashboard(data);
  }catch(e){
    console.error(e);
    const box = document.getElementById('errorBox');
    box.style.display = 'block';
    box.textContent = 'ダッシュボードデータを取得できませんでした。\n' + e.message;
  }
}


async function syncHealthNow(){
  const btn = document.getElementById('syncHealthBtn');
  const status = document.getElementById('syncHealthStatus');

  if(!btn) return;

  if(!confirm('Google Healthデータを更新しますか？\n\n先にスマホのFitbitアプリでCharge 6の同期が済んでいると、最新データが反映されます。')){
    return;
  }

  btn.disabled = true;
  btn.classList.add('syncing');
  const originalText = btn.textContent;
  btn.textContent = '更新中...';
  if(status) status.textContent = 'Google HealthデータとAIサマリを更新しています。';

  try{
    const data = await fetchJson('../api/health_sync_now.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        person_id: personId,
        date: date
      })
    });

    if(!data.ok){
      console.error(data);
      throw new Error(data.error || '更新に失敗しました。');
    }

    if(status) status.textContent = '更新しました。画面を再読み込みします。';
    await loadDashboard();

    setTimeout(() => {
      if(status) status.textContent = '更新完了';
      btn.disabled = false;
      btn.classList.remove('syncing');
      btn.textContent = originalText;
    }, 700);

  }catch(e){
    console.error(e);
    if(status) status.textContent = '更新に失敗しました: ' + e.message;
    btn.disabled = false;
    btn.classList.remove('syncing');
    btn.textContent = originalText;
  }
}

const syncHealthBtn = document.getElementById('syncHealthBtn');
if(syncHealthBtn){
  syncHealthBtn.addEventListener('click', syncHealthNow);
}

loadDashboard();
</script>
</body>
</html>
