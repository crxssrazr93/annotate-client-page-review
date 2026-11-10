'use strict';
// Review server: the annotate.js reference backend plus a small admin API.
// Reviews live in DATA_DIR/reviews.json; comments in DATA_DIR/comments/<review>.json.
// Admin routes need "Authorization: Bearer <ADMIN_TOKEN>".
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { createAnnotateServer } = require('./annotate-server.cjs');

const DATA_DIR = path.resolve(process.env.DATA_DIR || 'data');
const CONFIG = path.join(DATA_DIR, 'reviews.json');
const ADMIN_TOKEN = process.env.ADMIN_TOKEN || '';
const ID_RE = /^[A-Za-z0-9_-]{1,128}$/;

if (ADMIN_TOKEN.length < 24) {
  console.error('Set ADMIN_TOKEN (24+ characters).');
  process.exit(1);
}
fs.mkdirSync(DATA_DIR, { recursive: true });

// The annotate server reads reviews from this Map on every request, so edits apply at once.
const reviews = new Map();
function load() {
  let list = [];
  try { list = JSON.parse(fs.readFileSync(CONFIG, 'utf8')).reviews || []; } catch (e) { list = []; }
  reviews.clear();
  for (const r of list) reviews.set(r.id, r);
}
function save() {
  const tmp = CONFIG + '.' + crypto.randomUUID() + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify({ reviews: [...reviews.values()] }, null, 2));
  fs.renameSync(tmp, CONFIG);
}
load();

const annotate = createAnnotateServer({
  reviewsMap: reviews,
  dataDir: path.join(DATA_DIR, 'comments'),
  purgeAfterDays: Number(process.env.PURGE_AFTER_DAYS || 30),
  writesPerMinute: Number(process.env.WRITES_PER_MINUTE || 60),
});
const annotateHandler = annotate.listeners('request')[0];

function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  res.end(body === undefined ? '' : JSON.stringify(body));
}
async function readJson(req) {
  let size = 0; const chunks = [];
  for await (const c of req) { size += c.length; if (size > 64 * 1024) throw Object.assign(new Error('too_large'), { status: 413 }); chunks.push(c); }
  if (!chunks.length) return {};
  try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch (e) { throw Object.assign(new Error('invalid_json'), { status: 400 }); }
}
function authorized(req) {
  const given = Buffer.from(String(req.headers.authorization || '').replace(/^Bearer\s+/i, ''));
  const want = Buffer.from(ADMIN_TOKEN);
  return given.length === want.length && crypto.timingSafeEqual(given, want);
}
const str = (v, max) => (typeof v === 'string' ? v.slice(0, max) : '');
const publicReview = r => ({ ...r, invites: (r.invites || []).map(i => ({ ...i })) });

async function admin(req, res, parts) {
  if (!authorized(req)) return send(res, 401, { error: 'unauthorized' });
  const [, , id, sub, token] = parts; // admin / reviews / :id / invites / :token
  if (parts[1] !== 'reviews') return send(res, 404, { error: 'not_found' });

  if (!id) {
    if (req.method !== 'GET') return send(res, 405, { error: 'method_not_allowed' });
    return send(res, 200, { reviews: [...reviews.values()].map(publicReview) });
  }
  if (!ID_RE.test(id)) return send(res, 400, { error: 'invalid_id' });
  const review = reviews.get(id);

  if (!sub) {
    if (req.method === 'GET') return review ? send(res, 200, publicReview(review)) : send(res, 404, { error: 'review_not_found' });
    if (req.method === 'PUT') {
      const b = await readJson(req);
      const next = review || { id, invites: [], authors: [] };
      next.name = str(b.name, 200);
      next.note = str(b.note, 2000);
      next.origins = Array.isArray(b.origins) ? b.origins.filter(o => /^https?:\/\/[^/\s]+$/.test(o)).slice(0, 20) : (next.origins || []);
      next.pages = Array.isArray(b.pages) ? b.pages.filter(p => typeof p === 'string').slice(0, 200) : (next.pages || []);
      next.open = b.open === true;
      next.closed = b.closed === true;
      next.shared = b.shared === true;
      next.selfServe = b.selfServe === true;
      reviews.set(id, next); save();
      return send(res, 200, publicReview(next));
    }
    return send(res, 405, { error: 'method_not_allowed' });
  }
  if (!review) return send(res, 404, { error: 'review_not_found' });

  if (sub === 'invites' && !token && req.method === 'POST') {
    const b = await readJson(req);
    const name = str(b.name, 80).trim();
    if (!name) return send(res, 422, { error: 'name_required' });
    const invite = {
      token: 'inv_' + crypto.randomBytes(18).toString('base64url'),
      reviewer: { id: 'rvr_' + crypto.randomBytes(9).toString('base64url'), name },
      expiresAt: null, revoked: false, createdAt: new Date().toISOString(),
    };
    review.invites = review.invites || []; review.invites.push(invite);
    review.authors = review.authors || [];
    if (b.author === true) review.authors.push(invite.reviewer.id);
    save();
    return send(res, 201, { ...invite, author: b.author === true });
  }
  if (sub === 'invites' && token && req.method === 'DELETE') {
    const invite = (review.invites || []).find(i => i.token === token);
    if (!invite) return send(res, 404, { error: 'invite_not_found' });
    invite.revoked = true; save();
    return send(res, 204);
  }
  if (sub === 'comments' && req.method === 'GET') {
    const data = annotate.annotate.review(id);
    const comments = Object.values(data.comments || {}).filter(c => !c.deletedAt)
      .sort((a, b) => (a.createdAt < b.createdAt ? 1 : -1));
    return send(res, 200, { comments });
  }
  return send(res, 404, { error: 'not_found' });
}

// A distinct, well-separated palette (annotate's own 5 first, then more).
const PALETTE = [
  '#f59e0b', '#f43f5e', '#8b5cf6', '#0ea5e9', '#10b981',
  '#ec4899', '#f97316', '#6366f1', '#14b8a6', '#eab308', '#06b6d4', '#a3e635',
];

// GET /v1/annotate/reviews/:id/suggest-color?page=... — origin-checked, no auth.
// Returns a palette color not already used by comments (on that page), to reduce collisions.
function suggestColor(req, res, parts, url) {
  const id = parts[3];
  const review = reviews.get(id);
  const origin = req.headers.origin;
  if (review && origin && (review.origins || []).includes(origin)) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Vary', 'Origin');
    res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  }
  if (req.method === 'OPTIONS') return send(res, 204);
  if (!review) return send(res, 404, { error: 'review_not_found' });
  if (!origin || !(review.origins || []).includes(origin)) return send(res, 403, { error: 'origin_not_allowed' });
  const page = url.searchParams.get('page');
  const data = annotate.annotate.review(id);
  const counts = new Map(PALETTE.map(c => [c.toLowerCase(), 0]));
  for (const c of Object.values(data.comments || {})) {
    if (c.deletedAt) continue;
    if (page !== null && c.page !== page) continue;
    const k = String(c.color || '').toLowerCase();
    if (counts.has(k)) counts.set(k, counts.get(k) + 1);
  }
  // Least-used colors; shuffle within the lowest tier so two simultaneous viewers rarely match.
  const min = Math.min(...counts.values());
  const free = PALETTE.filter(c => counts.get(c.toLowerCase()) === min);
  const color = free[Math.floor(Math.random() * free.length)] || PALETTE[0];
  return send(res, 200, { color, palette: PALETTE });
}

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const parts = url.pathname.split('/').filter(Boolean).map(decodeURIComponent);
  try {
    if (url.pathname === '/health') return send(res, 200, { ok: true, reviews: reviews.size });
    if (parts[0] === 'admin') return await admin(req, res, parts);
    if (parts[0] === 'v1' && parts[1] === 'annotate' && parts[2] === 'reviews' && parts[4] === 'suggest-color') {
      return suggestColor(req, res, parts, url);
    }
    return annotateHandler(req, res);
  } catch (e) {
    if (!res.headersSent) send(res, e.status || 500, { error: e.status ? e.message : 'internal' });
    if (!e.status) console.error(e);
  }
}).listen(Number(process.env.PORT || 8787), process.env.HOST || '127.0.0.1', () => {
  console.log('review server listening on', process.env.PORT || 8787);
});
