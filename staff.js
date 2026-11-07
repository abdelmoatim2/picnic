/* staff.js — أدوات مشتركة لصفحات الموظفين (الطلبات / المطبخ / الداشبورد) */
(function () {
  var css = `
:root{--bg:#eef0f3;--card:#fff;--tx:#1d2433;--mut:#667085;--bd:#d6dbe4;--ac:#0f766e;--dg:#b42318;--wn:#b45309;--ok:#15803d;--bl:#1d4ed8;color-scheme:light dark}
@media (prefers-color-scheme:dark){:root{--bg:#10151c;--card:#1a212c;--tx:#e8ecf3;--mut:#9aa6b8;--bd:#2c3646;--ac:#2dd4bf;--dg:#f97066;--wn:#fbbf24;--ok:#4ade80;--bl:#7aa2ff}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);font:400 15px/1.6 Tahoma,"Segoe UI",sans-serif;padding-bottom:40px}
.w{max-width:1200px;margin:auto;padding:0 12px}
header.sh{position:sticky;top:0;z-index:6;background:var(--card);border-bottom:1px solid var(--bd)}
header.sh .w{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:10px 12px}
header.sh h1{margin:0;font-size:18px}.tb{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
button,.bt{font:inherit;border:1px solid var(--bd);background:var(--card);color:var(--tx);border-radius:10px;padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block;min-height:38px}
button.pr{background:var(--ac);color:#fff;border-color:var(--ac);font-weight:700}button.dg{color:var(--dg)}button:disabled{opacity:.5;cursor:not-allowed}
input,select,textarea{font:inherit;padding:8px 10px;border:1px solid var(--bd);border-radius:8px;background:var(--bg);color:var(--tx)}
[hidden]{display:none!important}
li.ad{display:block!important;background:#fef3c7;color:#78350f;font-weight:700;border-radius:6px;padding:2px 8px!important;font-size:13px!important;margin-top:4px;border-bottom:0!important}
.mut{color:var(--mut)}.b{font-weight:700}
#sl{position:fixed;inset:0;background:var(--bg);z-index:50;display:none;align-items:center;justify-content:center;padding:16px}
#sl form,#sl .box{background:var(--card);border:1px solid var(--bd);border-top:6px solid var(--ac);border-radius:16px;padding:22px;width:100%;max-width:340px}
#sl h2{margin:0 0 12px}#sl label{display:block;font-size:12.5px;color:var(--mut)}#sl input{width:100%;margin:4px 0 10px}.er{color:var(--dg);font-size:13px;min-height:20px}
#snd{display:none;background:var(--wn);color:#fff;text-align:center;padding:8px;font-weight:700;cursor:pointer}
#net{display:none;background:var(--dg);color:#fff;text-align:center;padding:6px;font-size:13px}
#pt{display:none}
.tk{direction:rtl;color:#000;background:#fff;font-family:Tahoma,Arial,sans-serif;font-size:inherit;line-height:1.5}
.tk .c{text-align:center}.tk .b{font-weight:700}.tk .big{font-size:1.25em}.tk .xl{font-size:2em;font-weight:700}.tk .xl2{font-size:1.5em}.tk .sm{font-size:.85em}
.tk hr{border:0;border-top:2px dashed #000;margin:6px 0}
.tk .ln{display:flex;gap:8px;font-size:1.2em;font-weight:700;padding:2px 0}.tk .ln .q{min-width:34px}
.tk .nt{background:#fff;color:#000;border:2px solid #000;border-radius:0;padding:4px 6px;margin:6px 0;font-weight:700}
.tk .tt{display:flex;justify-content:space-between}
@media print{body>*:not(#pt){display:none!important}#pt{display:block!important}html,body{background:#fff!important;color:#000!important;padding:0!important}}
`;
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
})();

const $ = id => document.getElementById(id);
const esc = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const ST = { pending: "بانتظار التأكيد", new: "جديد", prep: "قيد التحضير", ready: "جاهز", out: "خرج للتوصيل", done: "تم التسليم", cancel: "ملغي" };
const ROLE = { manager: "مدير", waiter: "ويتر", delivery: "موظف توصيل", kitchen: "مطبخ" };
const ALLOW = {
  manager: ["pending", "new", "prep", "ready", "out", "done", "cancel"],
  delivery: ["pending", "new", "prep", "ready", "out", "done", "cancel"],
  waiter: ["new", "prep", "ready", "done", "cancel"],
  kitchen: ["prep", "ready"]
};
const HOME = { manager: "orders.html", waiter: "orders.html", delivery: "orders.html", kitchen: "kitchen.html" };
let CFG = { name: "", cur: "", dec: 2 }, ME = null;
const money = n => (+n).toFixed(CFG.dec) + " " + CFG.cur;
const hhmm = ts => new Date(ts * 1000).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
const ago = ts => { const m = Math.max(0, Math.floor((Date.now() / 1000 - ts) / 60)); return m < 1 ? "الآن" : m < 60 ? "منذ " + m + " د" : "منذ " + Math.floor(m / 60) + " س " + (m % 60) + " د"; };
const lget = (k, d) => { try { var v = localStorage.getItem(k); return v === null ? d : v } catch (e) { return d } };
const lset = (k, v) => { try { localStorage.setItem(k, v) } catch (e) { } };

async function api(a, body) {
  const o = { credentials: "same-origin", cache: "no-store" };
  if (body) { o.method = "POST"; o.headers = { "Content-Type": "application/json" }; o.body = JSON.stringify(body) }
  const r = await fetch("api.php?action=" + a + (body ? "" : "&_=" + Date.now()), o);
  let j; try { j = await r.json() } catch (e) { throw new Error("استجابة غير صالحة من الخادم") }
  if (r.status == 401) { showLogin(); throw new Error("auth") }
  if (!j.ok) throw new Error(j.error || "خطأ");
  return j;
}

/* ---------- الدخول ---------- */
let OPT = null;
function showLogin() {
  let d = $("sl");
  if (!d) {
    d = document.createElement("div"); d.id = "sl";
    d.innerHTML = '<form onsubmit="doLogin(event)"><h2 id="slt"></h2><label>اسم المستخدم</label><input id="slu" dir="ltr" autocomplete="username" autocapitalize="none" required><label>كلمة المرور</label><input id="slp" type="password" autocomplete="current-password" required><div class="er" id="sle" role="alert"></div><button class="pr" style="width:100%">دخول</button><p class="mut" style="font-size:12px;margin:10px 0 0">المدير العام: اسم المستخدم <b>admin</b> وكلمة مرور لوحة التحكم</p></form>';
    document.body.appendChild(d);
  }
  $("slt").textContent = CFG.name || "دخول الموظفين";
  d.style.display = "flex";
}
async function doLogin(e) {
  e.preventDefault(); $("sle").textContent = "";
  try { const j = await api("staff_login", { username: $("slu").value, password: $("slp").value }); $("slp").value = ""; enter(j.user) }
  catch (x) { $("sle").textContent = x.message == "Failed to fetch" ? "تعذر الاتصال بالخادم" : x.message }
}
async function logout() { try { await api("staff_logout", {}) } catch (e) { } location.reload() }
function enter(u) {
  if (OPT.roles && OPT.roles.indexOf(u.role) < 0) { location.href = HOME[u.role] || "orders.html"; return }
  ME = u; if ($("sl")) $("sl").style.display = "none";
  OPT.onReady(u);
}
async function staffInit(opt) {
  OPT = opt;
  try { const m = await (await fetch("api.php?action=menu&_=" + Date.now(), { cache: "no-store" })).json(); CFG = Object.assign(CFG, m.config || {}); CFG.dec = +CFG.dec || 0 } catch (e) { }
  document.title = (opt.title || "") + (CFG.name ? " · " + CFG.name : "");
  try { const r = await fetch("api.php?action=me&_=" + Date.now(), { credentials: "same-origin", cache: "no-store" }); const j = await r.json(); if (j.ok && j.user) enter(j.user); else showLogin() }
  catch (e) { showLogin(); $("sle").textContent = "تعذر الاتصال بالخادم" }
}

/* ---------- الصوت ---------- */
let AC = null;
function audio() { try { AC = AC || new (window.AudioContext || window.webkitAudioContext)(); if (AC.state === "suspended") AC.resume() } catch (e) { } return AC }
function beep(n) {
  n = n || 3; const a = audio();
  if (!a || a.state !== "running") { sndBar(true); return false }
  for (let i = 0; i < n; i++) { const o = a.createOscillator(), g = a.createGain(); o.frequency.value = i % 2 ? 880 : 1175; g.gain.value = .3; o.connect(g); g.connect(a.destination); o.start(a.currentTime + i * .3); o.stop(a.currentTime + i * .3 + .2) }
  return true;
}
function sndBar(show) {
  let b = $("snd");
  if (!b) { b = document.createElement("div"); b.id = "snd"; b.textContent = "🔔 اضغط هنا لتفعيل صوت تنبيه الطلبات الجديدة"; b.onclick = () => { audio(); b.style.display = "none"; beep(1) }; document.body.insertBefore(b, document.body.firstChild) }
  b.style.display = show ? "block" : "none";
}
document.addEventListener("click", () => { audio(); if (AC && AC.state === "running") sndBar(false) });
function netBar(bad, msg) {
  let b = $("net");
  if (!b) { b = document.createElement("div"); b.id = "net"; document.body.insertBefore(b, document.body.firstChild) }
  b.textContent = "⚠️ تعذر تحديث الطلبات" + (msg ? ": " + msg : "") + " — جارٍ إعادة المحاولة...";
  b.style.display = bad ? "block" : "none";
}
function lastStat(t) {
  let e = $("ls");
  if (!e) { e = document.createElement("div"); e.id = "ls"; e.style.cssText = "position:fixed;bottom:4px;left:8px;font-size:11px;color:var(--mut);background:var(--card);padding:2px 8px;border-radius:8px;border:1px solid var(--bd);z-index:5"; document.body.appendChild(e) }
  e.textContent = t;
}

/* ---------- متابعة الطلبات (Polling) ---------- */
const OR = {
  map: {}, rev: 0, first: true, n: 0, cb: null, staff: [], timer: null,
  list() { return Object.keys(OR.map).map(k => OR.map[k]).sort((a, b) => b.id - a.id) },
  async poll() {
    const full = OR.first || OR.n % 12 === 0;
    const j = await api("orders&since=" + (full ? 0 : OR.rev));
    const fresh = [];
    if (full) {
      const old = OR.map; OR.map = {};
      j.orders.forEach(o => { const p0 = old[o.id]; OR.map[o.id] = o; if (!OR.first && (!p0 || o.batches > p0.batches)) fresh.push(o) });
    } else {
      j.orders.forEach(o => { const p0 = OR.map[o.id]; if (!p0 || o.batches > p0.batches) fresh.push(o); OR.map[o.id] = o });
    }
    OR.rev = j.rev; if (j.staff) OR.staff = j.staff;
    OR.first = false; OR.n++;
    lastStat("آخر تحديث " + new Date().toLocaleTimeString("en-GB") + " · وصل " + j.orders.length + " طلب");
    OR.cb(OR.list(), fresh);
  },
  start(cb) {
    OR.cb = cb;
    const loop = async () => {
      try { await OR.poll(); netBar(false) } catch (e) { if (e.message != "auth") netBar(true, e.message) }
      OR.timer = setTimeout(loop, 4000);
    };
    loop();
  },
  put(o) { OR.map[o.id] = o; OR.cb(OR.list(), []) }
};

function itemsLI(o) {
  let h = "", last = -1;
  (o.items || []).forEach(i => {
    const b = i.b || 0;
    if (b !== last) { if (b > 0) h += `<li class="ad">➕ ملحق${b > 1 ? " " + b : ""} · ${hhmm(i.t || o.created)}</li>`; last = b }
    h += `<li><b>${i.q} ×</b><span>${esc(i.n)}</span></li>`;
  });
  return h;
}

/* ---------- الطباعة ---------- */
const PAPERS = {
  "80": { label: "حراري 80mm", page: "@page{margin:0}", w: "80mm", pad: "3mm 4mm", fs: "14px" },
  "58": { label: "حراري 58mm", page: "@page{margin:0}", w: "58mm", pad: "2mm 3mm", fs: "12px" },
  "a5": { label: "ورقية A5", page: "@page{size:A5;margin:0}", w: "100%", pad: "12mm 14mm", fs: "16px" },
  "a4": { label: "ورقية A4", page: "@page{size:A4;margin:0}", w: "100%", pad: "16mm 20mm", fs: "17px" }
};
const paperOpts = () => ["80", "58", "a5", "a4"].map(k => `<option value="${k}">${PAPERS[k].label}</option>`).join("");
function ensurePt() { let t = $("pt"); if (!t) { t = document.createElement("div"); t.id = "pt"; document.body.appendChild(t) } return t }
function setPaper(p) {
  if (!PAPERS[p]) p = "80"; const k = PAPERS[p]; lset("paper", p);
  let s = $("pgs"); if (!s) { s = document.createElement("style"); s.id = "pgs"; document.head.appendChild(s) }
  s.textContent = k.page;
  const t = ensurePt(); t.style.width = k.w; t.style.padding = k.pad; t.style.fontSize = k.fs; t.style.boxSizing = "border-box";
  document.querySelectorAll("select.ppsel").forEach(e => e.value = p);
}
function ticket(o, b) {
  const all = o.items || [], row = i => `<div class="ln"><span class="q">${i.q} ×</span><span>${esc(i.n)}</span></div>`;
  let L = "";
  if (typeof b === "number") L = all.filter(i => (i.b || 0) == b).map(row).join("");
  else { let last = -1; all.forEach(i => { const x = i.b || 0; if (x !== last) { if (x > 0) L += `<div class="c b sm" style="margin-top:4px">— ملحق —</div>`; last = x } L += row(i) }) }
  const when = new Date(((b > 0 && all.filter(i => (i.b || 0) == b)[0] || {}).t * 1000 || o.created * 1000)).toLocaleString("en-GB", { dateStyle: "short", timeStyle: "short" });
  let h = `<div class="tk"><div class="c b xl2">${esc(CFG.name)}</div><div class="c sm">${when}</div><hr>`;
  if (b > 0) h += `<div class="c xl">ملحق</div><div class="c b big">للطلب #${o.no}</div><div class="c b big">طاولة ${esc(o.tbl)}</div><hr>${L}`;
  else h += `<div class="c xl">طلب #${o.no}</div><div class="c b big">${o.type == "d" ? "طاولة " + esc(o.tbl) : "توصيل"}</div><hr>${L}`;
  if (o.note) h += `<div class="nt">ملاحظات: ${esc(o.note)}</div>`;
  if (!(b > 0)) {
    if (o.type == "h") h += `<hr><div class="b">الاسم: ${esc(o.cname)}</div><div class="b">الهاتف: <span dir="ltr">${esc(o.phone)}</span></div><div>العنوان: ${esc(o.addr)}</div>`;
    else if (o.phone) h += `<div class="sm" style="margin-top:4px">الهاتف: <span dir="ltr">${esc(o.phone)}</span></div>`;
  }
  h += `<hr>`;
  if (+o.svc > 0 && !(b > 0)) h += `<div class="tt"><span>المجموع</span><span>${money(o.sub)}</span></div><div class="tt"><span>الخدمة</span><span>${money(o.svc)}</span></div>`;
  h += `<div class="tt b big"><span>${b > 0 ? "إجمالي الطلب حتى الآن" : "الإجمالي"}</span><span>${money(o.total)}</span></div><hr><div class="c sm">شكراً لزيارتكم</div><div style="height:14mm"></div></div>`;
  return h;
}
function printTicket(o, b) {
  const t = ensurePt(); setPaper(lget("paper", "80"));
  t.innerHTML = ticket(o, b);
  return new Promise(res => {
    let done = false; const fin = () => { if (done) return; done = true; window.removeEventListener("afterprint", fin); res() };
    window.addEventListener("afterprint", fin); setTimeout(fin, 20000);
    setTimeout(() => window.print(), 80);
  });
}
