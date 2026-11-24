import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PORT = Number(process.env.PORT || 3000);
const HOST = '0.0.0.0';

// Load simple .env if present without external deps
try {
  const envPath = path.join(__dirname, '.env');
  if (fs.existsSync(envPath)) {
    for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
      const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$/);
      if (m && !(m[1] in process.env)) {
        process.env[m[1]] = m[2].replace(/^['"]|['"]$/g, '');
      }
    }
  }
} catch {
  // ignore .env read errors
}

// Read PHP constants from config.php as fallback when env vars are unset
function readPhpConfig() {
  const out = {};
  try {
    const raw = fs.readFileSync(path.join(__dirname, 'config.php'), 'utf8');
    const strMatches = raw.matchAll(/define\(\s*['"]([A-Z0-9_]+)['"]\s*,\s*['"]([^'"]*)['"]\s*\)/g);
    for (const m of strMatches) {
      out[m[1]] = m[2];
    }
    const saMatch = raw.match(/define\(\s*['"]SUPERADMINS['"]\s*,\s*\[([^\]]*)\]\s*\)/);
    if (saMatch) {
      out.SUPERADMINS = saMatch[1]
        .split(',')
        .map((s) => Number(s.trim()))
        .filter((n) => Number.isFinite(n) && n > 0);
    }
  } catch {
    // ignore
  }
  return out;
}

const phpCfg = readPhpConfig();
const BOT_TOKEN = process.env.BOT_TOKEN || phpCfg.BOT_TOKEN || '';
const BOT_USERNAME = process.env.BOT_USERNAME || phpCfg.BOT_USERNAME || 'xnoxsguard_bot';
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || phpCfg.WEBHOOK_SECRET || '';
const HCAPTCHA_SITEKEY = process.env.HCAPTCHA_SITEKEY || phpCfg.HCAPTCHA_SITEKEY || '';
const HCAPTCHA_SECRET = process.env.HCAPTCHA_SECRET || phpCfg.HCAPTCHA_SECRET || '';
const SUPERADMINS = process.env.SUPERADMINS
  ? process.env.SUPERADMINS.split(',').map((s) => Number(s.trim())).filter(Boolean)
  : (phpCfg.SUPERADMINS || []);
const TG_API_URL = BOT_TOKEN ? `https://api.telegram.org/bot${BOT_TOKEN}/` : '';

// In-memory state for Node dev runner (mirrors PHP bot_settings & AI history)
const botSettings = new Map([
  ['default_ai_chat', 'gemini-4-hydrogen'],
  ['default_ai_image', 'gemini-3.1-flash-lite-image'],
  ['img_daily_limit', '5'],
  ['maintenance_mode', '0'],
]);
const aiHistory = new Map();
const imgUsage = new Map();
const captchaPending = new Map();

const AI_CHAT_MODELS = {
  'gemini-4-hydrogen': { label: '✨ Gemini 4 Hydrogen', short: 'Gemini 4 Hydrogen', provider: 'gemini', desc: 'Standar & seimbang (Rekomendasi)' },
  'gemini-3.1-flash-lite': { label: '⚡ Gemini 3.1 Flash Lite', short: 'Gemini Flash Lite', provider: 'gemini', desc: 'Respons sangat cepat & ringan' },
  'gemini-4-neon': { label: '🧠 Gemini 4 Neon', short: 'Gemini 4 Neon', provider: 'gemini', desc: 'Penalaran kompleks (Paid API Key)' },
  'gemini-4-carbon': { label: '🔬 Gemini 4 Carbon', short: 'Gemini 4 Carbon', provider: 'gemini', desc: 'Penalaran mendalam (Paid API Key)' },
  'deepseek-chat': { label: '🐋 DeepSeek Chat', short: 'DeepSeek', provider: 'deepseek', desc: 'Official DeepSeek API (DEEPSEEK_API_KEY)' },
};

const AI_IMAGE_MODELS = {
  'gemini-3.1-flash-lite-image': { label: '🍌 Gemini 3.1 Flash Lite Image', short: 'Gemini Flash Image', desc: 'Cepat & serbaguna (Default)' },
  'gemini-nano-banana-2.1': { label: '🖼️ Gemini Nano Banana 2.1', short: 'Nano Banana 2.1', desc: 'Kualitas tinggi (1K/2K/4K)' },
  'gemini-3-pro-image': { label: '🎨 Gemini 3 Pro Image', short: 'Gemini 3 Pro Image', desc: 'Detail artistik Pro' },
};

function escHtml(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function sha1(val) {
  return crypto.createHash('sha1').update(String(val)).digest('hex');
}

async function tgApi(method, params = {}) {
  if (!TG_API_URL) return { ok: false, description: 'BOT_TOKEN not configured' };
  try {
    const res = await fetch(`${TG_API_URL}${method}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(params),
    });
    return await res.json();
  } catch (err) {
    return { ok: false, description: err?.message || 'Network error' };
  }
}

async function tgSendPhoto(chatId, buffer, mimeType = 'image/png', caption = '') {
  if (!TG_API_URL) return { ok: false };
  const ext = mimeType.includes('jpeg') || mimeType.includes('jpg') ? 'jpg' : mimeType.includes('webp') ? 'webp' : 'png';
  const form = new FormData();
  form.append('chat_id', String(chatId));
  if (caption) {
    form.append('caption', caption);
    form.append('parse_mode', 'HTML');
  }
  form.append('photo', new Blob([buffer], { type: mimeType }), `image.${ext}`);
  const res = await fetch(`${TG_API_URL}sendPhoto`, { method: 'POST', body: form });
  return await res.json();
}

function mdToTelegramHtml(text) {
  const blocks = [];
  let out = String(text || '').replace(/```(\w*)\r?\n?([\s\S]*?)```/g, (_, lang, code) => {
    const idx = blocks.length;
    const cls = lang ? ` class="language-${escHtml(lang.toLowerCase())}"` : '';
    blocks.push(`<pre><code${cls}>${escHtml(code.trim())}</code></pre>`);
    return `\x02BLOCK${idx}\x03`;
  });
  out = out.replace(/`([^`\r\n]+)`/g, (_, code) => {
    const idx = blocks.length;
    blocks.push(`<code>${escHtml(code)}</code>`);
    return `\x02BLOCK${idx}\x03`;
  });
  out = out.split(/(\x02BLOCK\d+\x03)/).map((part) => (part.startsWith('\x02BLOCK') ? part : escHtml(part))).join('');
  out = out.replace(/^[ \t]*[-*]\s+(.+)$/gm, '• $1');
  out = out.replace(/\*\*(.+?)\*\*/gs, '<b>$1</b>');
  out = out.replace(/__(.+?)__/gs, '<b>$1</b>');
  out = out.replace(/(?<!\*)\*(?!\*)(?!\s)(.+?)(?<!\s)\*(?!\*)/gs, '<i>$1</i>');
  out = out.replace(/^#{1,6}\s+(.+)$/gm, '<b>$1</b>');
  out = out.replace(/\x02BLOCK(\d+)\x03/g, (_, i) => blocks[Number(i)] || '');
  return out.trim();
}

async function callGeminiChat(prompt, history = [], model = 'gemini-4-hydrogen') {
  const apiKey = process.env.GEMINI_API_KEY || '';
  if (!apiKey) return '❌ GEMINI_API_KEY belum diset di environment server.';
  const contents = [];
  for (const h of history) {
    if (!h.content || h.content.startsWith('❌')) continue;
    contents.push({
      role: h.role === 'assistant' ? 'model' : 'user',
      parts: [{ text: h.content }],
    });
  }
  contents.push({ role: 'user', parts: [{ text: prompt }] });

  const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent`;
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-goog-api-key': apiKey,
      'User-Agent': 'aistudio-build',
    },
    body: JSON.stringify({ contents }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) {
    return `❌ ${data.error?.message || `HTTP ${res.status}`}`;
  }
  const parts = data.candidates?.[0]?.content?.parts || [];
  const text = parts.filter((p) => !p.thought && p.text).map((p) => p.text).join('');
  return text ? mdToTelegramHtml(text) : '❌ AI tidak mengembalikan jawaban teks.';
}

async function callDeepSeekChat(prompt, history = []) {
  const apiKey = process.env.DEEPSEEK_API_KEY || '';
  if (!apiKey) return '❌ DEEPSEEK_API_KEY belum diset di environment server.';
  const apiUrl = process.env.DEEPSEEK_API_URL || 'https://api.deepseek.com/v1/chat/completions';
  const messages = [...history.filter((h) => h.content && !h.content.startsWith('❌')), { role: 'user', content: prompt }];
  const res = await fetch(apiUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${apiKey}` },
    body: JSON.stringify({ model: 'deepseek-chat', messages }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) return `❌ ${data.error?.message || `HTTP ${res.status}`}`;
  const text = data.choices?.[0]?.message?.content;
  return text ? mdToTelegramHtml(text) : '❌ Respons DeepSeek kosong.';
}

async function aiChat(prompt, history = []) {
  const model = botSettings.get('default_ai_chat') || 'gemini-4-hydrogen';
  const info = AI_CHAT_MODELS[model] || AI_CHAT_MODELS['gemini-4-hydrogen'];
  if (info.provider === 'deepseek') return callDeepSeekChat(prompt, history);
  return callGeminiChat(prompt, history, model);
}

async function aiGenerateOrEditImage(prompt, inlineImage = null) {
  const apiKey = process.env.GEMINI_API_KEY || '';
  if (!apiKey) return { ok: false, error: '❌ GEMINI_API_KEY belum diset di environment server.' };
  const model = botSettings.get('default_ai_image') || 'gemini-3.1-flash-lite-image';
  const parts = inlineImage
    ? [{ inlineData: { mimeType: inlineImage.mimeType, data: inlineImage.data } }, { text: prompt }]
    : [{ text: prompt }];
  const payload = { contents: [{ role: 'user', parts }] };
  if (model === 'gemini-nano-banana-2.1' || model === 'gemini-3-pro-image') {
    payload.generationConfig = { imageConfig: { aspectRatio: '1:1', imageSize: '1K' } };
  }
  const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent`;
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-goog-api-key': apiKey,
      'User-Agent': 'aistudio-build',
    },
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.error) {
    return { ok: false, error: `❌ ${data.error?.message || `HTTP ${res.status}`}` };
  }
  const outParts = data.candidates?.[0]?.content?.parts || [];
  for (const p of outParts) {
    if (p.inlineData?.data) {
      return {
        ok: true,
        data: Buffer.from(p.inlineData.data, 'base64'),
        mime: p.inlineData.mimeType || 'image/png',
      };
    }
  }
  return { ok: false, error: '❌ Tidak ada gambar yang dihasilkan.' };
}

function getBaseUrl(req) {
  const proto = req.headers['x-forwarded-proto']?.toString().split(',')[0]?.trim() || 'https';
  const host = req.headers.host || `localhost:${PORT}`;
  return `${proto}://${host}`;
}

function readBody(req) {
  return new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
  });
}

function sendHtml(res, status, html) {
  res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(html);
}

function sendJson(res, status, obj) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(obj));
}

function renderSaAiText() {
  const chatId = botSettings.get('default_ai_chat');
  const imgId = botSettings.get('default_ai_image');
  const chatInfo = AI_CHAT_MODELS[chatId] || AI_CHAT_MODELS['gemini-4-hydrogen'];
  const imgInfo = AI_IMAGE_MODELS[imgId] || AI_IMAGE_MODELS['gemini-3.1-flash-lite-image'];
  const gemStatus = process.env.GEMINI_API_KEY ? '✅ Terpasang' : '⚠️ Belum diset (.env)';
  const dsStatus = process.env.DEEPSEEK_API_KEY ? '✅ Terpasang' : '⚠️ Belum diset (.env)';
  return `🤖 <b>Pengaturan Default AI Bot</b>\n\n💬 <b>Default AI Chat:</b> ${escHtml(chatInfo.label)}\n   └ ID: <code>${escHtml(chatId)}</code>\n\n🖼️ <b>Default AI Image:</b> ${escHtml(imgInfo.label)}\n   └ ID: <code>${escHtml(imgId)}</code>\n\n🔑 <b>Status API Server:</b>\n• Gemini API: ${gemStatus}\n• DeepSeek API: ${dsStatus}\n\n<i>Pilih kategori di bawah untuk mengubah AI default yang digunakan bot:</i>`;
}

function kbSaAi() {
  return {
    inline_keyboard: [
      [{ text: '💬 Pilih Default AI Chat', callback_data: 'sa_ai_chat_menu' }],
      [{ text: '🖼️ Pilih Default AI Image', callback_data: 'sa_ai_image_menu' }],
      [{ text: '🔙 Kembali', callback_data: 'sa_panel' }],
    ],
  };
}

function kbSaChatMenu() {
  const active = botSettings.get('default_ai_chat');
  return {
    inline_keyboard: [
      ...Object.entries(AI_CHAT_MODELS).map(([id, m]) => [
        { text: `${id === active ? '✅ ' : ''}${m.label}`, callback_data: `sa_set_ai_chat:${id}` },
      ]),
      [{ text: '⬅️ Kembali', callback_data: 'sa_ai_settings' }],
    ],
  };
}

function kbSaImageMenu() {
  const active = botSettings.get('default_ai_image');
  return {
    inline_keyboard: [
      ...Object.entries(AI_IMAGE_MODELS).map(([id, m]) => [
        { text: `${id === active ? '✅ ' : ''}${m.label}`, callback_data: `sa_set_ai_image:${id}` },
      ]),
      [{ text: '⬅️ Kembali', callback_data: 'sa_ai_settings' }],
    ],
  };
}

function renderHelpText(cat = 'overview', isSa = false) {
  switch (cat) {
    case 'general':
      return '📖 <b>Perintah Umum</b>\n\n/rules, /me, /id, /info (@user / /whois), /stats, /adminlist, /warns, /notes, /report, /checkperms, /cekverif, /features (/fitur)';
    case 'admin':
      return '📖 <b>Perintah Admin</b>\n\n/ban, /tban, /unban, /kick, /mute, /unmute, /warn, /unwarn (/rmwarn), /clearwarns, /promote, /demote, /settitle, /pin, /unpin, /unpinall, /del (/delete), /purge (reply atau @user), /clearall, /slowmode, /tagall, /kickbots, /cleandel';
    case 'content':
      return '📖 <b>Konten, Note &amp; Filter</b>\n\n/lock, /unlock, /locks (sticker, gif, media, voice, game, poll, forward)\n/save (/note), /get, /notes, /clear (/clearnote), #keyword\n/filter, /filters, /stop (/rmfilter), /banword (/addword), /unbanword (/rmword), /banwords';
    case 'safety':
      return '📖 <b>Poin Sanksi, Sang Mata &amp; Vote Kick</b>\n\n/vpoints (/poin), /resetvp (/resetpoin), /topviolators (/violators)\n/sangmata (/cekun, /usernamehistory)\n/votekick (/vkick), /cancelvotekick (/cancelvo)';
    case 'ai':
      return '📖 <b>Asisten AI &amp; Utilitas</b>\n\n/ai (/tanya, /ask), /resetai (/clearai)\n/translate (/terjemahkan, /tr), /detect (/deteksi), /summarize (/ringkas, /tldr)\n/imagine (/gambar, /buatgambar, /imgai), /edit (/editgambar, /editimg) — PM only\n/run &lt;bahasa&gt;\\n&lt;kode&gt; — Code Runner 35+ bahasa\n/price &lt;koin&gt; [mata_uang] (/harga, /crypto) — Harga crypto\n/setlang, /features (/fitur)';
    case 'settings':
      return '📖 <b>Pengaturan Grup</b>\n\n/settings (/mygroupsettings di PM), /setrules, /setwelcome, /setgoodbye, /setlogchannel, /invitelink, /ro (/readonly), /setlang\n<i>Auto-Pin admin: #pengumuman / #announcement</i>';
    case 'sa':
      return isSa
        ? '📖 <b>Superadmin &amp; Hosting</b>\n\n/sa (/superadmin), /sainfo (/botstat), /ping, /sysinfo (/serverinfo, /hostinfo, /vpsinfo), /gban, /ungban, /gbanlist, /massban, /cleargban, /broadcast, /announce, /lookup (/cariuser), /backup, /logs (/salog), /maintenance, /addsa, /rmsa (/removesa), /salist, /email, /tempmail, /whm, /cpanel'
        : '❌ Menu bantuan Superadmin hanya dapat diakses oleh Superadmin.';
    default:
      return '📖 <b>Daftar Perintah Bot</b>\n\nPilih kategori di tombol bawah atau ketik <code>/help [kategori]</code>:\n• <code>general</code> — Perintah Umum &amp; Verifikasi\n• <code>admin</code> — Moderasi &amp; Purge\n• <code>content</code> — Lock, Notes &amp; Filters\n• <code>safety</code> — Poin Sanksi, Sang Mata &amp; VoteKick\n• <code>ai</code> — AI Chat/Image, Translate, Code Runner &amp; Crypto\n• <code>settings</code> — Pengaturan Grup &amp; PM'
        + (isSa ? '\n• <code>sa</code> — Panel Superadmin &amp; Hosting' : '');
  }
}

function renderFeaturesText() {
  return '⭐ <b>Fitur Lengkap Bot</b>\n\n'
    + '✅ Moderasi lengkap, Anti-Flood, Anti-Link + Whitelist, Anti-Spam, Anti-Forward, Anti-Bot, Anti-Raid, Slowmode, Readonly, Clean Service, Purge/Clearall, Auto-Pin #pengumuman\n'
    + '✅ Welcome/Goodbye + Tombol URL &amp; Setuju Aturan, Math Captcha, Web hCaptcha, Verifikasi Kontak &amp; Lokasi (/cekverif)\n'
    + '✅ Sistem Warn, Poin Sanksi, Vote Kick demokratis, Sang Mata tracker, Report\n'
    + '✅ Lock konten, Notes (#keyword), Auto-Filter, Filter Kata Terlarang, Multi-bahasa ID/EN, Log Channel\n'
    + '✅ Gemini AI &amp; DeepSeek Chat, Translate, Detect, Summarize, Gemini Image (/imagine, /edit), SA Default AI Switcher\n'
    + '✅ Code Runner 35+ bahasa (/run) &amp; Live Crypto Price (/price)\n'
    + '✅ Suite Superadmin: Global Ban, Broadcast &amp; Antrian Jadwal, Lookup, Dynamic SA, Backup, Sysinfo, Email, TempMail, WHM &amp; cPanel';
}

function kbHelpMenu(isSa = false) {
  const rows = [
    [{ text: '📋 Umum', callback_data: 'help_cat:general' }, { text: '🛡️ Admin', callback_data: 'help_cat:admin' }],
    [{ text: '🔒 Konten & Filter', callback_data: 'help_cat:content' }, { text: '⚖️ Sanksi & Mata', callback_data: 'help_cat:safety' }],
    [{ text: '🤖 AI & Tools', callback_data: 'help_cat:ai' }, { text: '⚙️ Pengaturan', callback_data: 'help_cat:settings' }],
  ];
  if (isSa) rows.push([{ text: '🔐 Superadmin', callback_data: 'help_cat:sa' }]);
  rows.push([{ text: '⭐ Fitur Lengkap', callback_data: 'menu_features' }]);
  return { inline_keyboard: rows };
}

async function handleTelegramUpdate(update) {
  if (update.callback_query) {
    const cb = update.callback_query;
    const cbId = cb.id;
    const chatId = cb.message?.chat?.id;
    const msgId = cb.message?.message_id;
    const userId = cb.from?.id;
    const [action, param] = String(cb.data || '').split(':');
    const isSa = SUPERADMINS.includes(Number(userId));

    if (action === 'menu_help') {
      await tgApi('answerCallbackQuery', { callback_query_id: cbId });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: renderHelpText('overview', isSa),
        parse_mode: 'HTML',
        reply_markup: JSON.stringify(kbHelpMenu(isSa)),
      });
    } else if (action === 'help_cat') {
      if (param === 'sa' && !isSa) {
        return tgApi('answerCallbackQuery', { callback_query_id: cbId, text: '❌ Hanya Superadmin!', show_alert: true });
      }
      await tgApi('answerCallbackQuery', { callback_query_id: cbId });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: renderHelpText(param || 'overview', isSa),
        parse_mode: 'HTML',
        reply_markup: JSON.stringify(kbHelpMenu(isSa)),
      });
    } else if (action === 'menu_features') {
      await tgApi('answerCallbackQuery', { callback_query_id: cbId });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: renderFeaturesText(),
        parse_mode: 'HTML',
        reply_markup: JSON.stringify({ inline_keyboard: [[{ text: '📖 Bantuan', callback_data: 'menu_help' }]] }),
      });
    } else if (action === 'sa_panel' || action === 'sa_cat_settings') {
      if (!isSa) return tgApi('answerCallbackQuery', { callback_query_id: cbId, text: '❌ Akses ditolak!', show_alert: true });
      await tgApi('answerCallbackQuery', { callback_query_id: cbId });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: '🛠 <b>Pengaturan Bot (Superadmin)</b>',
        parse_mode: 'HTML',
        reply_markup: JSON.stringify({
          inline_keyboard: [[{ text: '🤖 Pengaturan AI (Chat & Image)', callback_data: 'sa_ai_settings' }]],
        }),
      });
    } else if (action === 'sa_ai_settings') {
      if (!isSa) return tgApi('answerCallbackQuery', { callback_query_id: cbId, text: '❌ Akses ditolak!', show_alert: true });
      await tgApi('answerCallbackQuery', { callback_query_id: cbId });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: renderSaAiText(),
        parse_mode: 'HTML',
        reply_markup: JSON.stringify(kbSaAi()),
      });
    } else if (action === 'sa_ai_chat_menu') {
      if (!isSa) return tgApi('answerCallbackQuery', { callback_query_id: cbId, text: '❌ Akses ditolak!', show_alert: true });
      await tgApi('answerCallbackQuery', { callback_query_id: cbId });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: '💬 <b>Pilih Default AI Chat</b>',
        parse_mode: 'HTML',
        reply_markup: JSON.stringify(kbSaChatMenu()),
      });
    } else if (action === 'sa_ai_image_menu') {
      if (!isSa) return tgApi('answerCallbackQuery', { callback_query_id: cbId, text: '❌ Akses ditolak!', show_alert: true });
      await tgApi('answerCallbackQuery', { callback_query_id: cbId });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: '🖼️ <b>Pilih Default AI Image</b>',
        parse_mode: 'HTML',
        reply_markup: JSON.stringify(kbSaImageMenu()),
      });
    } else if (action === 'sa_set_ai_chat' && AI_CHAT_MODELS[param]) {
      if (!isSa) return tgApi('answerCallbackQuery', { callback_query_id: cbId, text: '❌ Akses ditolak!', show_alert: true });
      botSettings.set('default_ai_chat', param);
      await tgApi('answerCallbackQuery', { callback_query_id: cbId, text: `✅ Default AI Chat: ${AI_CHAT_MODELS[param].short}` });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: '💬 <b>Pilih Default AI Chat</b>',
        parse_mode: 'HTML',
        reply_markup: JSON.stringify(kbSaChatMenu()),
      });
    } else if (action === 'sa_set_ai_image' && AI_IMAGE_MODELS[param]) {
      if (!isSa) return tgApi('answerCallbackQuery', { callback_query_id: cbId, text: '❌ Akses ditolak!', show_alert: true });
      botSettings.set('default_ai_image', param);
      await tgApi('answerCallbackQuery', { callback_query_id: cbId, text: `✅ Default AI Image: ${AI_IMAGE_MODELS[param].short}` });
      await tgApi('editMessageText', {
        chat_id: chatId,
        message_id: msgId,
        text: '🖼️ <b>Pilih Default AI Image</b>',
        parse_mode: 'HTML',
        reply_markup: JSON.stringify(kbSaImageMenu()),
      });
    }
    return;
  }

  const msg = update.message;
  if (!msg) return;
  const chatId = msg.chat.id;
  const userId = msg.from?.id || 0;
  const isSa = SUPERADMINS.includes(Number(userId));
  const text = (msg.text || msg.caption || '').trim();
  if (!text.startsWith('/')) return;

  const [rawCmd, ...args] = text.split(/\s+/);
  const cmd = rawCmd.slice(1).split('@')[0].toLowerCase();
  const argText = args.join(' ').trim();

  if (cmd === 'start' || cmd === 'help') {
    const cat = (args[0] || 'overview').toLowerCase();
    await tgApi('sendMessage', {
      chat_id: chatId,
      text: renderHelpText(cat, isSa),
      parse_mode: 'HTML',
      reply_markup: JSON.stringify(kbHelpMenu(isSa)),
    });
  } else if (cmd === 'features' || cmd === 'fitur') {
    await tgApi('sendMessage', {
      chat_id: chatId,
      text: renderFeaturesText(),
      parse_mode: 'HTML',
      reply_markup: JSON.stringify({ inline_keyboard: [[{ text: '📖 Bantuan', callback_data: 'menu_help' }]] }),
    });
  } else if (cmd === 'sa' && isSa) {
    await tgApi('sendMessage', {
      chat_id: chatId,
      text: renderSaAiText(),
      parse_mode: 'HTML',
      reply_markup: JSON.stringify(kbSaAi()),
    });
  } else if (cmd === 'ai' || cmd === 'tanya' || cmd === 'ask') {
    const replyCtx = msg.reply_to_message?.text || '';
    const prompt = argText && replyCtx ? `Konteks: "${replyCtx}"\n\nPertanyaan: ${argText}` : argText || replyCtx;
    if (!prompt) {
      await tgApi('sendMessage', { chat_id: chatId, text: '🤖 Gunakan: <code>/ai pertanyaan</code>', parse_mode: 'HTML' });
      return;
    }
    await tgApi('sendChatAction', { chat_id: chatId, action: 'typing' });
    const key = `${chatId}:${userId}`;
    const hist = aiHistory.get(key) || [];
    const reply = await aiChat(prompt, hist);
    if (!reply.startsWith('❌')) {
      hist.push({ role: 'user', content: prompt }, { role: 'assistant', content: reply });
      aiHistory.set(key, hist.slice(-20));
    }
    await tgApi('sendMessage', { chat_id: chatId, text: `🤖 ${reply}`, parse_mode: 'HTML', reply_to_message_id: msg.message_id });
  } else if (cmd === 'translate' || cmd === 'terjemahkan' || cmd === 'tr') {
    const replyText = msg.reply_to_message?.text || msg.reply_to_message?.caption || '';
    const targetLang = args[0] || '';
    const srcText = args.slice(1).join(' ').trim() || replyText;
    if (!targetLang || !srcText) {
      await tgApi('sendMessage', { chat_id: chatId, text: '🌐 Gunakan: <code>/translate &lt;bahasa&gt; &lt;teks&gt;</code> (atau reply pesan)', parse_mode: 'HTML' });
      return;
    }
    await tgApi('sendChatAction', { chat_id: chatId, action: 'typing' });
    const reply = await aiChat(`Terjemahkan teks berikut ke bahasa ${targetLang}. Berikan hasil terjemahan yang natural dan akurat:\n\n${srcText}`);
    await tgApi('sendMessage', { chat_id: chatId, text: `🌐 ${reply}`, parse_mode: 'HTML', reply_to_message_id: msg.message_id });
  } else if (cmd === 'detect' || cmd === 'deteksi') {
    const srcText = argText || msg.reply_to_message?.text || msg.reply_to_message?.caption || '';
    if (!srcText) {
      await tgApi('sendMessage', { chat_id: chatId, text: '🔍 Gunakan: <code>/detect &lt;teks&gt;</code> (atau reply pesan)', parse_mode: 'HTML' });
      return;
    }
    await tgApi('sendChatAction', { chat_id: chatId, action: 'typing' });
    const reply = await aiChat(`Deteksi bahasa dari teks berikut (sebutkan nama bahasa, kode ISO, dan tingkat keyakinan singkat):\n\n${srcText}`);
    await tgApi('sendMessage', { chat_id: chatId, text: `🔍 ${reply}`, parse_mode: 'HTML', reply_to_message_id: msg.message_id });
  } else if (cmd === 'summarize' || cmd === 'ringkas' || cmd === 'tldr') {
    const srcText = argText || msg.reply_to_message?.text || msg.reply_to_message?.caption || '';
    if (!srcText) {
      await tgApi('sendMessage', { chat_id: chatId, text: '📝 Gunakan: <code>/summarize &lt;teks&gt;</code> (atau reply pesan)', parse_mode: 'HTML' });
      return;
    }
    await tgApi('sendChatAction', { chat_id: chatId, action: 'typing' });
    const reply = await aiChat(`Ringkas teks berikut secara padat, jelas, dan dalam poin-poin penting:\n\n${srcText}`);
    await tgApi('sendMessage', { chat_id: chatId, text: `📝 ${reply}`, parse_mode: 'HTML', reply_to_message_id: msg.message_id });
  } else if (cmd === 'resetai' || cmd === 'clearai') {
    aiHistory.delete(`${chatId}:${userId}`);
    await tgApi('sendMessage', { chat_id: chatId, text: '✅ Riwayat percakapan AI dihapus.' });
  } else if (cmd === 'imagine' || cmd === 'gambar' || cmd === 'buatgambar' || cmd === 'imgai') {
    if (!argText) {
      await tgApi('sendMessage', { chat_id: chatId, text: '🎨 Gunakan: <code>/imagine deskripsi gambar</code>', parse_mode: 'HTML' });
      return;
    }
    const today = new Date().toISOString().slice(0, 10);
    const uKey = `${userId}:${today}`;
    const limit = Number(botSettings.get('img_daily_limit') || '5');
    const used = imgUsage.get(uKey) || 0;
    if (limit > 0 && used >= limit) {
      await tgApi('sendMessage', { chat_id: chatId, text: `⛔ Batas harian gambar tercapai (${used}/${limit}).` });
      return;
    }
    await tgApi('sendChatAction', { chat_id: chatId, action: 'upload_photo' });
    const res = await aiGenerateOrEditImage(argText);
    if (!res.ok) {
      await tgApi('sendMessage', { chat_id: chatId, text: res.error });
      return;
    }
    imgUsage.set(uKey, used + 1);
    await tgSendPhoto(chatId, res.data, res.mime, `🎨 <b>Gambar AI</b>\n📝 <i>${escHtml(argText.slice(0, 200))}</i>`);
  } else if (cmd === 'edit' || cmd === 'editgambar' || cmd === 'editimg') {
    const src = msg.photo?.length ? msg : msg.reply_to_message;
    const fileId = src?.photo?.at(-1)?.file_id || (src?.document?.mime_type?.startsWith('image/') ? src.document.file_id : null);
    if (!fileId || !argText) {
      await tgApi('sendMessage', { chat_id: chatId, text: '✏️ Reply ke foto dengan <code>/edit instruksi perubahan</code>', parse_mode: 'HTML' });
      return;
    }
    const fileInfo = await tgApi('getFile', { file_id: fileId });
    const filePath = fileInfo.result?.file_path;
    if (!filePath) {
      await tgApi('sendMessage', { chat_id: chatId, text: '❌ Gagal mengambil foto dari Telegram.' });
      return;
    }
    const imgRes = await fetch(`https://api.telegram.org/file/bot${BOT_TOKEN}/${filePath}`);
    const buf = Buffer.from(await imgRes.arrayBuffer());
    const mimeType = imgRes.headers.get('content-type') || 'image/jpeg';
    await tgApi('sendChatAction', { chat_id: chatId, action: 'upload_photo' });
    const out = await aiGenerateOrEditImage(argText, { mimeType, data: buf.toString('base64') });
    if (!out.ok) {
      await tgApi('sendMessage', { chat_id: chatId, text: out.error });
      return;
    }
    await tgSendPhoto(chatId, out.data, out.mime, `✏️ <b>Gambar AI (Diedit)</b>\n📝 <i>${escHtml(argText.slice(0, 200))}</i>`);
  }
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  const pathname = url.pathname;

  if (pathname === '/health') {
    return sendJson(res, 200, {
      ok: true,
      backend: 'php',
      bot: BOT_USERNAME,
      ai: {
        default_chat: botSettings.get('default_ai_chat'),
        default_image: botSettings.get('default_ai_image'),
        gemini_configured: Boolean(process.env.GEMINI_API_KEY),
      },
    });
  }

  if (pathname === '/' || pathname === '/index.php') {
    const webhookUrl = `${getBaseUrl(req)}/webhook.php`;
    return sendHtml(res, 200, `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>${escHtml(BOT_USERNAME)} — Status</title>
<style>
  body { font-family: sans-serif; background: #0f0f1a; color: #e0e0e0; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
  .card { background: #1a1a2e; border: 1px solid #333; border-radius: 12px; padding: 40px 48px; text-align: center; max-width: 440px; width: 90%; }
  .dot { display: inline-block; width: 12px; height: 12px; background: #4caf50; border-radius: 50%; margin-right: 8px; box-shadow: 0 0 8px #4caf50; }
  h1 { font-size: 1.8em; margin: 0 0 8px; color: #7ecfff; }
  p { margin: 6px 0; color: #aaa; font-size: 0.95em; }
  .tag { background: #16213e; border: 1px solid #444; border-radius: 6px; padding: 6px 14px; font-family: monospace; font-size: 0.85em; color: #7ecfff; word-break: break-all; display: inline-block; margin-top: 10px; }
  .btn { display: inline-block; margin-top: 20px; padding: 10px 24px; background: #0088cc; color: #fff; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 0.9em; }
  .btn:hover { background: #006aaa; }
</style>
</head>
<body>
<div class="card">
  <h1>🤖 ${escHtml(BOT_USERNAME)}</h1>
  <p><span class="dot"></span><strong>Active and running</strong></p>
  <p style="margin-top:16px;">Webhook URL:</p>
  <div class="tag">${escHtml(webhookUrl)}</div>
  <p style="margin-top:18px; font-size:0.82em; color:#666;">Telegram Group Management Bot v3.1<br>PHP 8.0+ Backend • Gemini AI Enabled</p>
  <a class="btn" href="https://t.me/${escHtml(BOT_USERNAME)}" target="_blank">💬 Open in Telegram</a>
</div>
</body>
</html>`);
  }

  if (pathname === '/setup' || pathname === '/setup.php') {
    const token = url.searchParams.get('token') || '';
    const expected = sha1(BOT_TOKEN);
    if (!BOT_TOKEN || token !== expected) {
      return sendHtml(
        res,
        403,
        `<!DOCTYPE html><html><head><meta charset='utf-8'><title>Forbidden</title><style>body{font-family:monospace;padding:2rem;background:#1a1a2e;color:#e94560;}code{background:#16213e;padding:.2rem .5rem;border-radius:4px;color:#7ecfff;}</style></head><body><h2>403 Forbidden</h2><p>Token tidak valid atau tidak diberikan.</p><p>Akses via: <code>setup.php?token=&lt;sha1(BOT_TOKEN)&gt;</code></p></body></html>`
      );
    }

    let action = url.searchParams.get('action') || '';
    let customUrl = url.searchParams.get('url') || '';
    if (req.method === 'POST') {
      const form = new URLSearchParams(await readBody(req));
      action = form.get('action') || action;
      customUrl = form.get('url') || customUrl;
    }

    let message = '';
    let msgType = 'info';
    let result = null;
    if (action === 'set') {
      if (!customUrl.trim()) {
        message = '❌ URL webhook tidak boleh kosong.';
        msgType = 'error';
      } else {
        const params = {
          url: customUrl.trim(),
          max_connections: 40,
          allowed_updates: ['message', 'callback_query', 'edited_message', 'channel_post', 'chat_member', 'my_chat_member'],
        };
        if (WEBHOOK_SECRET) params.secret_token = WEBHOOK_SECRET;
        result = await tgApi('setWebhook', params);
        message = result.ok ? `✅ Webhook berhasil diset ke: ${escHtml(customUrl.trim())}` : `❌ Gagal: ${escHtml(result.description || 'Unknown error')}`;
        msgType = result.ok ? 'success' : 'error';
      }
    } else if (action === 'delete') {
      result = await tgApi('deleteWebhook');
      message = result.ok ? '✅ Webhook berhasil dihapus!' : `❌ Gagal: ${escHtml(result.description || '')}`;
      msgType = result.ok ? 'success' : 'error';
    } else if (action === 'info') {
      result = await tgApi('getWebhookInfo');
    } else if (action === 'botinfo') {
      result = await tgApi('getMe');
    }

    const whInfo = (await tgApi('getWebhookInfo')).result || {};
    const botInfo = (await tgApi('getMe')).result || {};
    const defaultUrl = `${getBaseUrl(req)}/webhook.php`;
    const whUrl = whInfo.url || '';

    return sendHtml(res, 200, `<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Setup Webhook — ${escHtml(botInfo.first_name || BOT_USERNAME)}</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f0f1a;color:#e2e8f0;padding:1.5rem;}
  .container{max-width:720px;margin:0 auto;}
  .card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1.25rem;margin-bottom:1.25rem;}
  .alert{border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1.25rem;border-left:4px solid;}
  .alert-success{background:#064e3b22;border-color:#10b981;color:#6ee7b7;}
  .alert-error{background:#450a0a22;border-color:#ef4444;color:#fca5a5;}
  input[type="text"]{width:100%;box-sizing:border-box;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#e2e8f0;padding:.6rem .9rem;margin:.5rem 0 1rem;}
  .btn{display:inline-block;padding:.55rem 1.1rem;border-radius:8px;border:none;cursor:pointer;text-decoration:none;font-weight:600;background:#3b82f6;color:#fff;margin-right:.5rem;}
  .btn-danger{background:#dc2626;}
  .btn-outline{background:transparent;border:1px solid #334155;color:#94a3b8;}
  pre{background:#0f172a;border-radius:8px;padding:.75rem;font-size:.75rem;color:#94a3b8;overflow-x:auto;}
</style></head>
<body><div class="container">
  <div class="card"><h2>🤖 ${escHtml(botInfo.first_name || BOT_USERNAME)} (@${escHtml(botInfo.username || BOT_USERNAME)})</h2><p>Telegram Bot Webhook Setup Panel</p></div>
  ${message ? `<div class="alert alert-${msgType === 'success' ? 'success' : 'error'}">${message}</div>` : ''}
  <div class="card"><h3>📡 Status Webhook</h3><p>URL: <code>${escHtml(whUrl || 'Belum diset')}</code></p><p>Pending: ${Number(whInfo.pending_update_count || 0)}</p></div>
  <div class="card"><h3>🔗 Set Webhook URL</h3>
    <form method="post" action="setup.php?token=${escHtml(token)}">
      <input type="hidden" name="action" value="set">
      <input type="text" name="url" value="${escHtml(customUrl || defaultUrl)}">
      <button type="submit" class="btn">🚀 Set Webhook</button>
      <a href="setup.php?token=${escHtml(token)}&action=info" class="btn btn-outline">📋 Info Webhook</a>
      <a href="setup.php?token=${escHtml(token)}&action=delete" class="btn btn-danger">🗑️ Hapus Webhook</a>
    </form>
  </div>
  ${result ? `<div class="card"><pre>${escHtml(JSON.stringify(result, null, 2))}</pre></div>` : ''}
</div></body></html>`);
  }

  if (pathname === '/captcha' || pathname === '/captcha_web.php') {
    const token = url.searchParams.get('token') || '';
    if (req.method === 'POST') {
      const form = new URLSearchParams(await readBody(req));
      const hRes = form.get('h-captcha-response') || '';
      if (!hRes || !HCAPTCHA_SECRET) {
        return sendHtml(res, 200, '<h3>⚠️ Verifikasi captcha gagal atau belum dikonfigurasi.</h3>');
      }
      const verify = await fetch('https://hcaptcha.com/siteverify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ secret: HCAPTCHA_SECRET, response: hRes }).toString(),
      }).then((r) => r.json()).catch(() => ({}));
      if (verify.success && captchaPending.has(token)) {
        const entry = captchaPending.get(token);
        captchaPending.delete(token);
        await tgApi('sendMessage', {
          chat_id: entry.chatId,
          text: `✅ <b>${escHtml(entry.firstName || 'Pengguna')}</b> telah berhasil diverifikasi.`,
          parse_mode: 'HTML',
        });
        return sendHtml(res, 200, '<h3>✅ Verifikasi Berhasil! Silakan kembali ke Telegram.</h3>');
      }
      return sendHtml(res, 200, '<h3>❌ Verifikasi captcha gagal atau kedaluwarsa.</h3>');
    }

    return sendHtml(res, 200, `<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Verifikasi Keanggotaan</title><script src="https://js.hcaptcha.com/1/api.js" async defer></script></head><body style="font-family:sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;"><div style="background:#fff;padding:36px;border-radius:16px;text-align:center;max-width:420px;width:90%;box-shadow:0 4px 20px rgba(0,0,0,0.08);"><h1>🔐 Verifikasi Keanggotaan</h1><form method="POST"><div class="h-captcha" data-sitekey="${escHtml(HCAPTCHA_SITEKEY)}" style="display:flex;justify-content:center;margin:20px 0;"></div><button type="submit" style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-weight:600;cursor:pointer;width:100%;">✅ Verifikasi &amp; Masuk Grup</button></form></div></body></html>`);
  }

  if ((pathname === '/webhook' || pathname === '/webhook.php') && req.method === 'POST') {
    if (WEBHOOK_SECRET && req.headers['x-telegram-bot-api-secret-token'] !== WEBHOOK_SECRET) {
      res.writeHead(403);
      return res.end('Forbidden');
    }
    const raw = await readBody(req);
    sendJson(res, 200, { ok: true });
    if (raw) {
      try {
        await handleTelegramUpdate(JSON.parse(raw));
      } catch {
        // ignore malformed update
      }
    }
    return;
  }

  if (pathname === '/cron' || pathname === '/cron.php') {
    return sendJson(res, 200, { ok: true });
  }

  return sendJson(res, 404, { error: 'Not found' });
});

server.listen(PORT, HOST, () => {
  console.log(`Server listening on http://${HOST}:${PORT}`);
});
