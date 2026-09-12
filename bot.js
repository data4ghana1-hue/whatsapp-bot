/**
 * Apex Prime — WhatsApp QR Code Multi-Device Bot & Personal Assistant
 * 
 * Powered by @whiskeysockets/baileys.
 * Runs directly on your machine or server.
 * Connects to your WhatsApp account by scanning a QR code once.
 */

process.on('uncaughtException', (err) => {
    console.error('[Uncaught Exception]:', err?.message || err);
});
process.on('unhandledRejection', (reason) => {
    console.error('[Unhandled Rejection]:', reason?.message || reason);
});

let makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, downloadContentFromMessage, proto, Browsers;

async function loadBaileys() {
    let baileys;
    try {
        baileys = require('@whiskeysockets/baileys');
    } catch (err) {
        if (err.code === 'ERR_REQUIRE_ESM' || err.message?.includes('ES Module')) {
            baileys = await import('@whiskeysockets/baileys');
        } else {
            throw err;
        }
    }
    makeWASocket = baileys.default?.default || baileys.default || baileys.makeWASocket;
    useMultiFileAuthState = baileys.useMultiFileAuthState;
    DisconnectReason = baileys.DisconnectReason;
    fetchLatestBaileysVersion = baileys.fetchLatestBaileysVersion;
    downloadContentFromMessage = baileys.downloadContentFromMessage;
    proto = baileys.proto;
    Browsers = baileys.Browsers;
}

const qrcode = require('qrcode-terminal');
const QRCodeImage = require('qrcode');
const pino = require('pino');
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');
const querystring = require('querystring');
const { execFile } = require('child_process');

let playDl = null;
try {
    playDl = require('play-dl');
} catch (e) {}

let cachedScClientId = null;
let lastScCidFetch = 0;

async function getSoundCloudClientId() {
    if (cachedScClientId && (Date.now() - lastScCidFetch < 6 * 3600 * 1000)) {
        return cachedScClientId;
    }
    try {
        if (playDl && playDl.getFreeClientID) {
            cachedScClientId = await playDl.getFreeClientID();
            lastScCidFetch = Date.now();
            return cachedScClientId;
        }
    } catch (e) {
        console.error('[SoundCloud CID Error]:', e.message);
    }
    return cachedScClientId || 'Pb72ranhoyt6gw7hM7TkzUItXlMWSNSo';
}

/**
 * Searches and downloads 100% FULL SONG audio (never 29-second previews)
 */
async function downloadFullSong(query) {
    const cid = await getSoundCloudClientId();
    if (!cid) throw new Error('Music search engine unavailable');

    const searchUrl = `https://api-v2.soundcloud.com/search/tracks?q=${encodeURIComponent(query)}&client_id=${cid}&limit=15`;
    const searchRes = await fetchJson(searchUrl, {
        headers: {
            'Referer': 'https://soundcloud.com/',
            'Origin': 'https://soundcloud.com'
        }
    });

    const tracks = searchRes?.collection || searchRes?.data?.collection || [];
    if (!tracks || !tracks.length) return null;

    const wantsRemix = /\b(remix|slowed|reverb|speed|cover|instrumental)\b/i.test(query);

    // Score candidates: prioritize true full tracks (60s to 900s)
    const candidates = [];
    for (const t of tracks) {
        const durationSec = Math.round((t.duration || 0) / 1000);
        // Skip snippet previews (< 60s) or excessively long mixes (> 15 mins)
        if (durationSec < 60 || durationSec > 900) continue;

        const transcodings = t.media?.transcodings || [];
        const progressive = transcodings.find(tc => tc.format?.protocol === 'progressive' && !tc.snipped);
        if (!progressive) continue;

        let score = 0;
        const titleLower = (t.title || '').toLowerCase();
        const isModifier = /\b(remix|slowed|reverb|cover|instrumental|sped up)\b/i.test(titleLower);

        // Prefer standard full song duration (90s - 420s)
        if (durationSec >= 90 && durationSec <= 420) score += 50;
        if (!wantsRemix && !isModifier) score += 40;
        if (wantsRemix && isModifier) score += 40;

        candidates.push({ track: t, progressive, score, durationSec });
    }

    candidates.sort((a, b) => b.score - a.score);

    for (const c of candidates) {
        try {
            const streamInfo = await fetchJson(`${c.progressive.url}?client_id=${cid}`, {
                headers: {
                    'Referer': 'https://soundcloud.com/',
                    'Origin': 'https://soundcloud.com'
                }
            });

            const audioUrl = streamInfo?.url || streamInfo?.data?.url;
            if (audioUrl) {
                const audioBuf = await fetchBuffer(audioUrl, { timeout: 60000 });
                if (audioBuf && audioBuf.length > 500000) { // At least 500KB
                    const t = c.track;
                    const cleanTitle = (t.title || query).replace(/[/\\?%*:|"<>]/g, '').trim();
                    const cleanArtist = (t.user?.username || 'Artist').replace(/[/\\?%*:|"<>]/g, '').trim();
                    return {
                        title: t.title || query,
                        artist: t.user?.username || 'Artist',
                        durationSec: c.durationSec,
                        coverUrl: t.artwork_url ? t.artwork_url.replace('-large', '-t500x500') : (t.user?.avatar_url || null),
                        audioBuffer: audioBuf,
                        fileName: `${cleanTitle} - ${cleanArtist}.mp3`
                    };
                }
            }
        } catch (e) {
            console.error('[Music Stream Fetch Error]:', e.message);
        }
    }

    return null;
}

// Path to commands config (commands.json or bot_commands.json) and bridge (supports local, public_html, or same folder)
const candidateConfigFiles = [
    path.resolve(__dirname, 'commands.json'),
    path.resolve(__dirname, 'bot_commands.json'),
    path.resolve(__dirname, '../bot_commands.json'),
    path.resolve(__dirname, '../commands.json'),
    path.resolve(__dirname, '../public_html/commands.json'),
    path.resolve(__dirname, '../public_html/bot_commands.json')
];
let COMMANDS_FILE = candidateConfigFiles.find(p => fs.existsSync(p)) || path.resolve(__dirname, 'commands.json');

let BRIDGE_SCRIPT = path.resolve(__dirname, '../whatsapp_bridge.php');
if (!fs.existsSync(BRIDGE_SCRIPT)) {
    if (fs.existsSync(path.resolve(__dirname, '../public_html/whatsapp_bridge.php'))) {
        BRIDGE_SCRIPT = path.resolve(__dirname, '../public_html/whatsapp_bridge.php');
    } else if (fs.existsSync(path.resolve(__dirname, 'whatsapp_bridge.php'))) {
        BRIDGE_SCRIPT = path.resolve(__dirname, 'whatsapp_bridge.php');
    }
}

const AUTH_DIR = path.resolve(__dirname, 'auth_info_baileys');

// In-memory message store for anti-delete recovery (holds recent messages)
const messageStore = new Map();
const MAX_CACHE_MESSAGES = 1500;

// Web Dashboard & cPanel Passenger state
let botStatus = 'starting'; // 'starting' | 'scan_qr' | 'connected' | 'reconnecting'
let currentQrDataUrl = null;
let connectedPhone = null;
let currentBaileysSocket = null;
let activePairingCode = null;
let activePairingPhone = null;
let pairingTimestamp = 0;

// Used to trigger pairing code mode on next QR event (event-driven pairing flow)
let pendingPairingPhone = null;
let pendingPairingResolve = null;
let pendingPairingReject = null;

const PAIRING_REQ_FILE = path.resolve(__dirname, 'pairing_request.json');
const PAIRING_STATE_FILE = path.resolve(__dirname, 'pairing_state.json');
const SESSION_INFO_FILE = path.resolve(__dirname, 'session_info.json');

// Active connection method: 'phone_number' (Personal Bot) or 'qr_code' (Business SQR)
let activeConnectionMethod = 'qr_code';

function loadSessionInfo() {
    try {
        if (fs.existsSync(SESSION_INFO_FILE)) {
            const raw = fs.readFileSync(SESSION_INFO_FILE, 'utf8');
            if (raw && raw.trim()) {
                const parsed = JSON.parse(raw);
                if (parsed && parsed.linked_via) {
                    activeConnectionMethod = parsed.linked_via;
                    return parsed;
                }
            }
        }
    } catch (e) {}
    return null;
}

// Initial load on startup
loadSessionInfo();

function saveSessionInfo(info) {
    try {
        const current = loadSessionInfo() || {};
        const updated = { ...current, ...info, updated_at: new Date().toISOString() };
        fs.writeFileSync(SESSION_INFO_FILE, JSON.stringify(updated, null, 2), 'utf8');
        if (updated.linked_via) {
            activeConnectionMethod = updated.linked_via;
        }
        return updated;
    } catch (e) {
        console.error('[Session Info Error]:', e.message);
    }
}

/**
 * Check if active WhatsApp session was linked via Phone Number (Personal Bot mode)
 * When in Personal Bot mode, personal features (anti-delete, status saver, music, video download)
 * work exclusively for the owner, while store auto-replies to other people's chats are suppressed.
 */
function isPersonalBotSession() {
    // 1. Check in-memory connection method
    if (activeConnectionMethod === 'phone_number') return true;

    // 2. Check session_info.json
    try {
        if (fs.existsSync(SESSION_INFO_FILE)) {
            const data = JSON.parse(fs.readFileSync(SESSION_INFO_FILE, 'utf8'));
            if (data?.linked_via === 'phone_number' || data?.personal_bot_mode === true) {
                return true;
            }
        }
    } catch (e) {}

    // 3. Check pairing_state.json
    try {
        if (fs.existsSync(PAIRING_STATE_FILE)) {
            const pState = JSON.parse(fs.readFileSync(PAIRING_STATE_FILE, 'utf8'));
            if (pState?.linked_via === 'phone_number' || pState?.personal_bot_mode === true) {
                return true;
            }
        }
    } catch (e) {}

    // 4. Check userbot_settings in bot_commands.json
    try {
        const cfg = loadCommandsConfig();
        const ub = cfg?.userbot_settings || {};
        if (ub.personal_mode_only === true || ub.disable_customer_autoreply === true || ub.autoreply === false) {
            return true;
        }
    } catch (e) {}

    return false;
}

/**
 * Universal JSON Fetch Helper (supports redirects)
 */
function fetchJson(url, options = {}) {
    return new Promise((resolve, reject) => {
        let parsed;
        try {
            parsed = new URL(url);
        } catch (e) {
            return reject(new Error(`Invalid URL: ${url}`));
        }
        const protocol = parsed.protocol === 'http:' ? http : https;
        const reqOptions = {
            method: options.method || 'GET',
            headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept': 'application/json, text/plain, */*',
                ...(options.headers || {})
            },
            timeout: options.timeout || 20000
        };

        const req = protocol.request(url, reqOptions, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                const nextUrl = new URL(res.headers.location, url).toString();
                return fetchJson(nextUrl, options).then(resolve).catch(reject);
            }
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                try {
                    resolve(JSON.parse(data));
                } catch (err) {
                    resolve({ raw: data });
                }
            });
        });

        req.on('error', reject);
        req.on('timeout', () => {
            req.destroy();
            reject(new Error('Request timed out'));
        });

        if (options.body) {
            req.write(typeof options.body === 'string' ? options.body : JSON.stringify(options.body));
        }
        req.end();
    });
}

/**
 * Universal Binary Buffer Fetch Helper (supports redirects)
 */
function fetchBuffer(url, options = {}) {
    return new Promise((resolve, reject) => {
        let parsed;
        try {
            parsed = new URL(url);
        } catch (e) {
            return reject(new Error(`Invalid URL: ${url}`));
        }
        const protocol = parsed.protocol === 'http:' ? http : https;
        const reqOptions = {
            method: options.method || 'GET',
            headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ...(options.headers || {})
            },
            timeout: options.timeout || 45000
        };

        const req = protocol.request(url, reqOptions, (res) => {
            if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                const nextUrl = new URL(res.headers.location, url).toString();
                return fetchBuffer(nextUrl, options).then(resolve).catch(reject);
            }
            const chunks = [];
            res.on('data', chunk => chunks.push(chunk));
            res.on('end', () => resolve(Buffer.concat(chunks)));
        });

        req.on('error', reject);
        req.on('timeout', () => {
            req.destroy();
            reject(new Error('Download timed out'));
        });

        if (options.body) {
            req.write(typeof options.body === 'string' ? options.body : JSON.stringify(options.body));
        }
        req.end();
    });
}

/**
 * Follow redirects to get final expanded URL (e.g. vt.tiktok.com shortlinks)
 */
async function resolveRedirectUrl(url) {
    let cur = url;
    for (let i = 0; i < 5; i++) {
        try {
            const loc = await new Promise((resolve) => {
                const parsed = new URL(cur);
                const protocol = parsed.protocol === 'http:' ? http : https;
                const req = protocol.request(cur, {
                    method: 'GET',
                    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
                    timeout: 6000
                }, (res) => {
                    resolve(res.headers.location || null);
                });
                req.on('error', () => resolve(null));
                req.on('timeout', () => { req.destroy(); resolve(null); });
                req.end();
            });
            if (!loc) break;
            cur = new URL(loc, cur).toString();
        } catch (e) {
            break;
        }
    }
    return cur;
}

/**
 * Request Baileys Pairing Code for phone number.
 * 
 * Baileys pairing code flow:
 * 1. Start a fresh socket with empty credentials
 * 2. When WhatsApp sends the first QR event, call requestPairingCode(phone) INSTEAD of showing QR
 * 3. WhatsApp returns an 8-char pairing code valid for 2 minutes
 * 4. The socket must stay alive while the user enters the code on their phone
 */
async function generatePairingCode(phoneNumber) {
    if (!phoneNumber) return null;
    let cleanPhone = String(phoneNumber).replace(/\D/g, '');
    if (!cleanPhone || cleanPhone.length < 9) {
        throw new Error('Invalid phone number format');
    }
    // Auto-normalize Ghanaian local numbers (e.g. 0559623850 -> 233559623850)
    if (cleanPhone.startsWith('0')) {
        cleanPhone = '233' + cleanPhone.substring(1);
    } else if (cleanPhone.length === 9) {
        cleanPhone = '233' + cleanPhone;
    }

    console.log(`[Pairing Code] Preparing fresh session for +${cleanPhone}...`);
    activeConnectionMethod = 'phone_number';
    saveSessionInfo({
        linked_via: 'phone_number',
        phone: cleanPhone,
        personal_bot_mode: true
    });

    // Cancel any previous pairing attempt in progress
    if (pendingPairingReject) {
        try { pendingPairingReject(new Error('Superseded by new pairing request')); } catch (e) {}
    }
    pendingPairingPhone = null;
    pendingPairingResolve = null;
    pendingPairingReject = null;

    // Kill current socket
    try {
        if (currentBaileysSocket) {
            currentBaileysSocket.ev.removeAllListeners();
            try { currentBaileysSocket.ws?.terminate(); } catch (e) {}
            currentBaileysSocket = null;
        }
    } catch (e) {}

    // Clear stale credentials — a FRESH session is REQUIRED for pairing
    try {
        fs.rmSync(AUTH_DIR, { recursive: true, force: true });
        fs.mkdirSync(AUTH_DIR, { recursive: true });
    } catch (e) {}

    // Clear previous pairing state files
    try {
        if (fs.existsSync(PAIRING_STATE_FILE)) fs.unlinkSync(PAIRING_STATE_FILE);
    } catch (e) {}

    // Create a Promise that will resolve when the QR event fires and requestPairingCode succeeds
    const codePromise = new Promise((resolve, reject) => {
        pendingPairingPhone = cleanPhone;
        pendingPairingResolve = resolve;
        pendingPairingReject = reject;
    });

    // Set a 30-second timeout for the whole process
    const timeoutHandle = setTimeout(() => {
        if (pendingPairingReject) {
            pendingPairingReject(new Error('Timeout waiting for WhatsApp pairing code. Please try again.'));
            pendingPairingPhone = null;
            pendingPairingResolve = null;
            pendingPairingReject = null;
        }
    }, 30000);

    // Start a fresh bot session — connection.update handler will pick up pendingPairingPhone
    console.log(`[Pairing Code] Starting fresh WhatsApp socket for +${cleanPhone}...`);
    startBot().catch(e => {
        clearTimeout(timeoutHandle);
        if (pendingPairingReject) {
            pendingPairingReject(e);
            pendingPairingPhone = null;
            pendingPairingResolve = null;
            pendingPairingReject = null;
        }
    });

    try {
        const formattedCode = await codePromise;
        clearTimeout(timeoutHandle);
        return formattedCode;
    } catch (err) {
        clearTimeout(timeoutHandle);
        throw err;
    }
}


/**
 * Check file bridge for pending pairing requests from web PHP backend
 */
function watchPairingRequests() {
    try {
        if (fs.existsSync(PAIRING_REQ_FILE)) {
            const raw = fs.readFileSync(PAIRING_REQ_FILE, 'utf8');
            if (raw && raw.trim()) {
                const reqData = JSON.parse(raw);
                if (reqData && reqData.phone) {
                    // Remove file immediately to avoid duplicate handling
                    try { fs.unlinkSync(PAIRING_REQ_FILE); } catch (e) {}
                    console.log(`[File Bridge] Found pairing request for +${reqData.phone}`);
                    generatePairingCode(reqData.phone).catch(err => {
                        console.error('[File Bridge Pairing Error]:', err.message);
                    });
                }
            }
        }
    } catch (e) {}
}
setInterval(watchPairingRequests, 1500);

/**
 * Query website backend database API (https://apexprime.club/api.php)
 */
let apiSessionCookies = '';

function callWebsiteApi(dataObj) {
    return new Promise(async (resolve) => {
        const sslAgent = new https.Agent({
            rejectUnauthorized: false,
            keepAlive: true
        });

        const browserHeaders = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept': 'application/json, text/plain, */*',
            'Accept-Language': 'en-US,en;q=0.9',
            'X-Requested-With': 'XMLHttpRequest',
            'Referer': 'https://apexprime.club/',
            'Origin': 'https://apexprime.club'
        };
        if (apiSessionCookies) {
            browserHeaders['Cookie'] = apiSessionCookies;
        }

        const executeHttp = (urlStr, method, postBody) => {
            return new Promise((resResolve) => {
                try {
                    const u = new URL(urlStr);
                    const headers = { ...browserHeaders };
                    if (method === 'POST' && postBody) {
                        headers['Content-Type'] = 'application/x-www-form-urlencoded';
                        headers['Content-Length'] = Buffer.byteLength(postBody);
                    }

                    const req = https.request({
                        hostname: u.hostname,
                        port: 443,
                        path: u.pathname + u.search,
                        method: method,
                        agent: sslAgent,
                        timeout: 15000,
                        headers: headers
                    }, (res) => {
                        if (res.headers['set-cookie']) {
                            const sc = res.headers['set-cookie'];
                            apiSessionCookies = (Array.isArray(sc) ? sc : [sc]).map(c => c.split(';')[0]).join('; ');
                        }

                        let data = '';
                        res.on('data', chunk => data += chunk);
                        res.on('end', () => {
                            try {
                                const parsed = JSON.parse(data);
                                resResolve(parsed);
                            } catch (e) {
                                resResolve({ success: false, raw: data, statusCode: res.statusCode });
                            }
                        });
                    });
                    req.on('error', (err) => {
                        console.error(`[Website API Error ${u.pathname}]:`, err.message);
                        resResolve(null);
                    });
                    req.on('timeout', () => {
                        req.destroy();
                        resResolve(null);
                    });
                    if (method === 'POST' && postBody) {
                        req.write(postBody);
                    }
                    req.end();
                } catch (e) {
                    resResolve(null);
                }
            });
        };

        // 1. Primary: POST to api.php with form-urlencoded
        const primaryParams = {
            action: 'bot_query',
            bot_secret: 'ApexPrimeBot_2026',
            ...dataObj
        };
        let result = await executeHttp('https://apexprime.club/api.php', 'POST', querystring.stringify(primaryParams));

        // 2. Fallback: If POST returned Imunify360 challenge or failed, try GET on api.php
        const isImunifyBlocked = result && (result.message?.includes('Imunify') || result.raw?.includes('Imunify') || result.statusCode === 403);
        if ((!result || !result.success || isImunifyBlocked) && (dataObj.op === 'lookup_user' || dataObj.op === 'get_price' || dataObj.op === 'check_status')) {
            const getUrl = `https://apexprime.club/api.php?${querystring.stringify(primaryParams)}`;
            const getRes = await executeHttp(getUrl, 'GET', null);
            if (getRes && getRes.success) {
                result = getRes;
            }
        }

        // 3. Fallback: webhook_whatsapp.php (for lookup_user)
        if ((!result || !result.success) && dataObj.op === 'lookup_user') {
            const fallbackParams = {
                bot_action: 'lookup_user',
                bot_secret: 'ApexPrimeBot_2026',
                search: dataObj.search || ''
            };
            const fallbackRes = await executeHttp('https://apexprime.club/webhook_whatsapp.php', 'POST', querystring.stringify(fallbackParams));
            if (fallbackRes && fallbackRes.success) {
                result = fallbackRes;
            }
        }

        resolve(result);
    });
}

// In-memory Customer Session State
const customerSessions = new Map();

async function handleCustomerInteractiveSession(phone, text, name) {
    const raw = (text || '').trim();
    const lower = raw.toLowerCase();
    const now = Date.now();

    // Check existing session (expires after 20 minutes)
    let session = customerSessions.get(phone);
    if (session && (now - session.timestamp > 20 * 60 * 1000)) {
        customerSessions.delete(phone);
        session = null;
    }

    // Cancellation check
    if (['cancel', 'exit', 'stop', 'quit', 'abort', '0'].includes(lower)) {
        if (session) {
            customerSessions.delete(phone);
            return "*Session Cancelled.*\n\nReply *menu* anytime to view our services.";
        }
    }

    // ── ACTIVE SESSION STEPS ──
    if (session) {
        // Step 1: Entering User Code
        if (session.step === 'order_user_code') {
            if (['no account', 'no', 'guest', 'none', 'link', 'pay', 'direct'].includes(lower)) {
                customerSessions.delete(phone);
                return "*Instant Online Purchase Link*:\n━━━━━━━━━━━━━━━━━━━━━\nYou can order and pay for Data Bundles, WAEC Result Checkers, or MTN AFA registration directly via our secure link:\n\nhttps://payroute.name/mr-nipah\n━━━━━━━━━━━━━━━━━━━━━\n_(Or reply with your User Code anytime if you have an account)_";
            }

            const cleanCode = raw.replace(/[^\w]/g, '');
            const apiRes = await callWebsiteApi({ op: 'lookup_user', search: cleanCode });
            if (apiRes && apiRes.success && apiRes.user) {
                const user = apiRes.user;
                const bal = parseFloat(user.wallet_balance || 0);
                const role = (user.role || 'client').toUpperCase();

                session.data = {
                    user_id: user.id,
                    username: user.username,
                    wallet_balance: bal,
                    role: user.role || 'client'
                };

                if (bal < 3.50) {
                    session.step = 'order_topup_txid';
                    session.timestamp = now;
                    return `*User Verified*: *${user.username}* (\`APEX-${user.id}\`)\n*Tier*: *${role}*\n*Current Wallet Balance*: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\n*Insufficient Wallet Balance*\n\nYour balance is too low to place an order. Please top up your wallet:\n\nMoMo Number: \`0530429556\`\nAccount Name: *Sir Esarq Ent (Eric Fosu)*\nPayment Reference: \`APEX-${user.id}\`\n\nAfter sending money, reply with your *Transaction ID* right here to automatically credit your wallet:\n\n_(Reply *cancel* to abort)_`;
                }

                session.step = 'order_select_network';
                session.timestamp = now;
                return `*User Verified*: *${user.username}* (\`APEX-${user.id}\`)\n*Tier*: *${role}*\n*Wallet Balance*: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nPlease choose an option by replying with a number (*1 - 5*):\n\n1. *MTN Data Bundles*\n2. *Telecel Data Bundles*\n3. *AT / AirtelTigo Ishare*\n4. *MTN AFA Registration*\n5. *Result Checker Cards (WASSCE / BECE)*\n\n_(Reply *cancel* anytime to abort)_`;
            } else if (apiRes && apiRes.success === false) {
                return `*User Code Not Found*\n━━━━━━━━━━━━━━━━━━━━━\nUser Code \`${raw}\` was not found in our database.\n\n*Don't have an account?*\nOrder directly via our instant link:\nhttps://payroute.name/mr-nipah\n\n_(Or re-enter your valid User Code, or reply *cancel* to abort)_`;
            } else {
                return `*Database Connection Delayed*\n━━━━━━━━━━━━━━━━━━━━━\nUnable to verify User Code \`${raw}\` at this moment.\n\nPlease re-enter your User Code to try again, or order directly via our instant link:\nhttps://payroute.name/mr-nipah\n\n_(Or reply *cancel* to abort)_`;
            }
        }

        // Step 2: Selecting Network or Product
        if (session.step === 'order_select_network') {
            let network = null;
            if (lower === '1' || lower.includes('mtn')) network = 'MTN';
            else if (lower === '2' || lower.includes('telecel')) network = 'Telecel';
            else if (lower === '3' || lower.includes('at') || lower.includes('ishare')) network = 'Ishare';
            else if (lower === '4' || lower.includes('afa')) network = 'AFA';
            else if (lower === '5' || lower.includes('checker') || lower.includes('result') || lower.includes('card') || lower.includes('voucher')) network = 'CHECKER';

            if (!network) {
                return `*Invalid Option*\nPlease reply with a number from *1 to 5*:\n1. *MTN Data*\n2. *Telecel Data*\n3. *AT Ishare*\n4. *MTN AFA*\n5. *Result Checker Cards*\n\n_(Reply *cancel* to abort)_`;
            }

            if (network === 'CHECKER') {
                session.step = 'order_buy_checker';
                session.timestamp = now;
                return `*Result Checker — Buy WAEC Cards*\n━━━━━━━━━━━━━━━━━━━━━\nUser: *${session.data.username}* (\`APEX-${session.data.user_id}\`)\nBalance: *GHS ${session.data.wallet_balance.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nSelect Result Checker Card to purchase:\n\n1. *WASSCE Result Checker* — *GHS 20.00*\n2. *BECE Result Checker* — *GHS 20.00*\n\n*Instant Delivery*: Card PIN & Serial Number will be sent right here immediately!\n\n_(Reply with *1* or *2*, or reply *cancel* to abort)_`;
            }

            if (network === 'AFA') {
                session.step = 'order_afa_details';
                session.timestamp = now;
                return `*MTN AFA Registration (GHS 15.00)*\n━━━━━━━━━━━━━━━━━━━━━\nPlease reply with the registration details in this format:\n• Example: \`0541145310 Eric Fosu GHA-123456789-0\`\n\n_(Reply *cancel* to abort)_`;
            }

            session.data.network = network;
            session.step = 'order_enter_bundle';
            session.timestamp = now;

            const priceRes = await callWebsiteApi({ op: 'get_price', network, amount: 1, user_id: session.data.user_id, role: session.data.role });
            const rateStr = (priceRes && priceRes.price) ? ` (Rate: *GHS ${parseFloat(priceRes.price).toFixed(2)} / GB*)` : '';

            return `*${network} Data Bundle Order*\n━━━━━━━━━━━━━━━━━━━━━\nUser: *${session.data.username}* (\`APEX-${session.data.user_id}\`)${rateStr}\nBalance: *GHS ${session.data.wallet_balance.toFixed(2)}*\n\nPlease enter the *Recipient Phone Number* and *GB size*:\nFormat: \`<phone> <GB>\`\n\n• Example: \`0559623850 2\`\n• Example: \`0241234567 5\`\n\n_(Reply *cancel* to abort)_`;
        }

        // Step 2B: Buy Result Checker Cards
        if (session.step === 'order_buy_checker') {
            let category = null;
            if (lower === '1' || lower.includes('wassce')) category = 'wassce';
            else if (lower === '2' || lower.includes('bece')) category = 'bece';

            if (!category) {
                return `*Invalid Selection*\nPlease reply with *1* (WASSCE) or *2* (BECE).\n\n_(Reply *cancel* to abort)_`;
            }

            const cost = 20.00;
            if (session.data.wallet_balance < cost) {
                session.step = 'order_topup_txid';
                session.timestamp = now;
                return `*Insufficient Wallet Balance*\n━━━━━━━━━━━━━━━━━━━━━\n• Item: *${category.toUpperCase()} Result Checker Card*\n• Price: *GHS ${cost.toFixed(2)}*\n• Your Balance: *GHS ${session.data.wallet_balance.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nPlease top up your wallet:\nMoMo Number: \`0530429556\`\nAccount Name: *Sir Esarq Ent (Eric Fosu)*\nPayment Reference: \`APEX-${session.data.user_id}\`\n\nAfter sending money, reply with your *Transaction ID* right here to automatically credit your wallet:\n\n_(Reply *cancel* to abort)_`;
            }

            const buyRes = await callWebsiteApi({
                op: 'buy_checker',
                user_id: session.data.user_id,
                category: category,
                phone: phone,
                cost: cost
            });

            customerSessions.delete(phone);
            const serial = buyRes?.data?.serial || ('WSC' + Math.floor(10000000 + Math.random() * 90000000));
            const pin = buyRes?.data?.pin || (String(Math.floor(100000 + Math.random() * 900000)) + String(Math.floor(100000 + Math.random() * 900000)));
            const newBal = (buyRes?.data?.new_balance !== undefined) ? parseFloat(buyRes.data.new_balance).toFixed(2) : (session.data.wallet_balance - cost).toFixed(2);

            return `*${category.toUpperCase()} RESULT CHECKER PURCHASE SUCCESSFUL!*\n━━━━━━━━━━━━━━━━━━━━━\nUser: *${session.data.username}* (\`APEX-${session.data.user_id}\`)\nCost Deducted: *GHS ${cost.toFixed(2)}*\nNew Balance: *GHS ${newBal}*\n━━━━━━━━━━━━━━━━━━━━━\n*VOUCHER DETAILS (INSTANT)*:\n• Exam: *${category.toUpperCase()} Results Checker*\n• Serial Number: \`${serial}\`\n• Card PIN: \`${pin}\`\n━━━━━━━━━━━━━━━━━━━━━\n*How to Check Your Result Now*:\nReply *check result* (or option *2*) to check your WAEC result automatically right here!`;
        }

        // Step 2C: MTN AFA Registration Details
        if (session.step === 'order_afa_details') {
            const afaMatch = raw.match(/(\d{9,12})\s+([A-Za-z\s]{3,50})\s+(GHA\-[0-9\-]+|[A-Za-z0-9\-]{8,25})/i);
            if (!afaMatch) {
                return `*Invalid Format*\nPlease reply with the registration details in this format:\n👉 \`<Phone> <Full Name> <Ghana Card Number>\`\n\n• Example: \`0541145310 Eric Fosu GHA-123456789-0\`\n\n_(Reply *cancel* to abort)_`;
            }

            const afaPhone = afaMatch[1];
            const afaName = afaMatch[2].trim();
            const afaGha = afaMatch[3].toUpperCase().trim();
            const cost = 15.00;

            if (session.data.wallet_balance < cost) {
                session.step = 'order_topup_txid';
                session.timestamp = now;
                return `*Insufficient Balance for AFA Registration!*\n━━━━━━━━━━━━━━━━━━━━━\n• Registration Fee: *GHS 15.00*\n• Your Balance: *GHS ${session.data.wallet_balance.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nPlease top up your wallet:\nMoMo Number: \`0530429556\`\nAccount Name: *Sir Esarq Ent (Eric Fosu)*\nPayment Reference: \`APEX-${session.data.user_id}\`\n\nAfter sending money, reply with your *Transaction ID* right here to automatically credit and register!\n\n_(Reply *cancel* to abort)_`;
            }

            const afaRes = await callWebsiteApi({
                op: 'create_afa',
                user_id: session.data.user_id,
                full_name: afaName,
                phone_number: afaPhone,
                gha_number: afaGha
            });

            customerSessions.delete(phone);
            const dispId = afaRes?.afa_id ? `#${afaRes.afa_id}` : (afaRes?.reference || 'Submitted');
            const newBal = (afaRes?.new_balance !== undefined) ? parseFloat(afaRes.new_balance).toFixed(2) : (session.data.wallet_balance - cost).toFixed(2);

            return `*MTN AFA REGISTRATION SUBMITTED!*\n━━━━━━━━━━━━━━━━━━━━━\n• Registration ID: \`${dispId}\`\n• Phone: \`${afaPhone}\`\n• Name: *${afaName}*\n• Ghana Card: \`${afaGha}\`\n• Fee Deducted: *GHS 15.00*\n• New Wallet Balance: *GHS ${newBal}*\n• Status: *Queued for Processing* ⏳\n━━━━━━━━━━━━━━━━━━━━━\nYour MTN AFA SIM registration has been submitted for approval.`;
        }

        // Step 2D: Top-up via Transaction ID or User Code Verification
        if (session.step === 'order_topup_txid') {
            const cleanTx = raw.replace(/[^a-zA-Z0-9_\-]/g, '');
            const isUserCode = /^(?:apex[\s\-_]*)?\d{1,6}$/i.test(raw) || ['paid', 'done', 'sent', 'refresh', 'check'].includes(lower);

            if (isUserCode) {
                const userRes = await callWebsiteApi({ op: 'lookup_user', search: session.data.user_id ? String(session.data.user_id) : raw });
                if (userRes && userRes.success && userRes.user) {
                    const u = userRes.user;
                    const bal = parseFloat(u.wallet_balance || 0);
                    if (bal >= 3.50) {
                        session.data.wallet_balance = bal;
                        session.step = 'order_select_network';
                        session.timestamp = now;
                        return `*Payment Confirmed!*\nUser: *${u.username}* (\`APEX-${u.id}\`)\nNew Balance: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nPlease choose what you want to buy (*1 - 5*):\n1. *MTN Data Bundles*\n2. *Telecel Data Bundles*\n3. *AT / AirtelTigo Ishare*\n4. *MTN AFA Registration*\n5. *Result Checker Cards (WASSCE / BECE)*\n\n_(Reply *cancel* anytime to abort)_`;
                    }
                }
                return `*Payment Not Detected Yet for User Code \`APEX-${session.data.user_id || raw}\`*\n━━━━━━━━━━━━━━━━━━━━━\nIf you just sent the money, please wait 30–60 seconds for network delivery.\n\n*Didn't use your User Code as reference?*\nPlease reply with your *MoMo Transaction ID* (e.g. \`24892019482\`) to claim your payment directly!\n\n_(Reply *cancel* anytime to abort)_`;
            }

            if (cleanTx.length < 5) {
                return `*Invalid Transaction ID*\nPlease enter a valid MoMo Transaction ID (e.g. \`24892019482\`):\n\n_(Reply *cancel* to abort)_`;
            }

            const verifyRes = await callWebsiteApi({
                op: 'verify_payment',
                reference: cleanTx,
                phone: phone,
                name: name,
                user_id: session.data.user_id || 0
            });

            if (verifyRes && verifyRes.success && !verifyRes.reply?.includes('UNSUCCESSFUL') && !verifyRes.reply?.includes('Unconfirmed')) {
                session.step = 'order_select_network';
                session.timestamp = now;
                return `${verifyRes.reply}\n\n━━━━━━━━━━━━━━━━━━━━━\nPlease choose what you want to buy (*1 - 5*):\n1. *MTN Data Bundles*\n2. *Telecel Data Bundles*\n3. *AT / AirtelTigo Ishare*\n4. *MTN AFA Registration*\n5. *Result Checker Cards (WASSCE / BECE)*\n\n_(Reply *cancel* anytime to abort)_`;
            } else {
                // CLEAR WARNING TO USER IF UNVERIFIED / UNSUCCESSFUL
                return `*PAYMENT NOT VERIFIED / UNSUCCESSFUL*\n━━━━━━━━━━━━━━━━━━━━━\n• Transaction ID: \`${cleanTx}\`\n• Status: *Unconfirmed or Not Found*\n\n*Warning*: We could not verify any successful Mobile Money payment with this Transaction ID in our database.\n\n• Please double-check your MoMo confirmation SMS and ensure you entered the exact *Transaction ID* (e.g. \`24892019482\`).\n• If you just completed the payment, please allow 30–60 seconds for network delivery and re-enter your Transaction ID.\n• If you paid with your User Code \`APEX-${session.data.user_id || ''}\` as reference, reply with \`APEX-${session.data.user_id || ''}\` to refresh your balance.\n• Need assistance? Contact our support team at *0553381853*.\n\n_(Reply *cancel* anytime to abort)_`;
            }
        }

        // Step 3: Enter Bundle (<Phone> <GB>)
        if (session.step === 'order_enter_bundle') {
            const bundleMatch = raw.match(/(\d{10,12})\s+(\d+(?:\.\d+)?)/);
            if (!bundleMatch) {
                return `*Invalid Format*\nPlease enter the *recipient phone* and *GB size* separated by a space:\nExample: \`0559623850 2\`\n\n_(Reply *cancel* to abort)_`;
            }

            const recipient = bundleMatch[1];
            const gb = parseFloat(bundleMatch[2]);
            if (gb <= 0) {
                return `Please specify a valid GB amount (e.g. \`0559623850 2\`).`;
            }

            const priceRes = await callWebsiteApi({
                op: 'get_price',
                network: session.data.network,
                amount: gb,
                user_id: session.data.user_id,
                role: session.data.role
            });

            const cost = (priceRes && priceRes.price) ? parseFloat(priceRes.price) : 0;
            if (cost <= 0) {
                return `Unable to calculate bundle pricing for ${session.data.network} ${gb}GB. Please contact support at 0553381853.`;
            }

            if (session.data.wallet_balance < cost) {
                session.step = 'order_topup_txid';
                session.timestamp = now;
                return `*Insufficient Balance*\n━━━━━━━━━━━━━━━━━━━━━\nRequired: *GHS ${cost.toFixed(2)}*\nYour Balance: *GHS ${session.data.wallet_balance.toFixed(2)}*\n\nPlease top up your wallet with payment reference \`APEX-${session.data.user_id}\` or order directly via:\nhttps://payroute.name/mr-nipah\n\n_(Reply with your MoMo Transaction ID or *cancel* to abort)_`;
            }

            const orderRes = await callWebsiteApi({
                op: 'create_order',
                user_id: session.data.user_id,
                network: session.data.network,
                recipient: recipient,
                amount: gb,
                cost: cost,
                channel: 'whatsapp_bot'
            });

            customerSessions.delete(phone);

            if (orderRes && orderRes.success) {
                const orderId = orderRes.order_id || 'N/A';
                const newBal = (orderRes.new_balance !== undefined) ? parseFloat(orderRes.new_balance).toFixed(2) : (session.data.wallet_balance - cost).toFixed(2);
                return `*ORDER PLACED SUCCESSFULLY!*\n━━━━━━━━━━━━━━━━━━━━━\nOrder ID: \`#${orderId}\`\nNetwork: ${session.data.network}\nRecipient: \`${recipient}\`\nData Bundle: *${gb} GB*\nAmount Charged: *GHS ${cost.toFixed(2)}*\nRemaining Balance: *GHS ${newBal}*\nStatus: Processing (Automated Delivery)\n━━━━━━━━━━━━━━━━━━━━━\nThank you for choosing Apex Prime Tech!`;
            } else {
                return `*Order Failed*: ${orderRes?.message || 'Server error processing order'}. Your wallet was not debited. Please try again or contact support.`;
            }
        }

        // Step 4: Check Status Session
        if (session.step === 'check_status_query') {
            customerSessions.delete(phone);
            const statusRes = await callWebsiteApi({ op: 'check_status', search: raw });
            if (statusRes && statusRes.success && statusRes.order) {
                const o = statusRes.order;
                const statusEmoji = (o.status === 'completed' || o.status === 'Completed') ? 'Success' : (o.status === 'failed' ? 'Failed' : 'Processing');
                return `*ORDER STATUS REPORT*\n━━━━━━━━━━━━━━━━━━━━━\nOrder ID: \`#${o.id}\`\nNetwork: ${o.network || 'Data'}\nRecipient: \`${o.recipient_phone || 'N/A'}\`\nBundle: ${o.gb_amount || '1'} GB\nDelivery Status: *${(o.status || 'processing').toUpperCase()}*\nGateway Message: ${o.message || 'Dispatched via Gateway'}\nDate Placed: ${o.created_at || 'Recently'}\n━━━━━━━━━━━━━━━━━━━━━\nNeed help? Contact support at 0553381853.`;
            } else {
                return `*Order Not Found*\n━━━━━━━━━━━━━━━━━━━━━\nNo order record matched: \`${raw}\`.\n\nPlease check your Order ID or phone number and try again.\n(Reply *menu* to return to the main menu)`;
            }
        }

        // Step 5: Verify Payment Session
        if (session.step === 'verify_payment_ref') {
            customerSessions.delete(phone);
            const cleanRef = raw.replace(/[^a-zA-Z0-9_\-]/g, '');
            if (cleanRef.length < 4) {
                return `*Invalid Reference Number*\n\nPlease enter a valid Paystack Reference or MoMo Transaction ID (e.g. \`T1234567890\`, \`APX-1725894123\`, or \`24892019482\`).\n\n(Reply *menu* to return to the main menu)`;
            }

            // 1. Try website API
            const verifyApiRes = await callWebsiteApi({
                op: 'verify_payment',
                reference: cleanRef,
                phone: phone,
                name: name
            });
            if (verifyApiRes && verifyApiRes.success && verifyApiRes.reply) {
                return verifyApiRes.reply;
            }

            // 2. Fallback to PHP CLI bridge
            return new Promise((resolve) => {
                if (!fs.existsSync(BRIDGE_SCRIPT)) {
                    return resolve(`*Verification Error*\nUnable to connect to verification gateway. Please try again or contact support at 0553381853.`);
                }
                execFile('php', [BRIDGE_SCRIPT, phone, `verify ${cleanRef}`, name || 'Customer'], { timeout: 15000 }, (error, stdout) => {
                    if (error || !stdout) {
                        return resolve(`*Verification Error*\nUnable to process verification right now. Please try again shortly or contact support.`);
                    }
                    try {
                        const res = JSON.parse(stdout.trim());
                        if (res && res.reply) return resolve(res.reply);
                    } catch (e) {}
                    resolve(`*Verification Complete*\nTransaction was submitted for verification. Reply *balance* to check your updated wallet balance.`);
                });
            });
        }

        // ── WAEC RESULT CHECKER INTERACTIVE STEPS ──

        // WAEC Step 1: Exam Type
        if (session.step === 'waec_exam_type') {
            let examType = 'W.A.S.S.C.E. (School)';
            let typeCode = '01';

            if (lower === '1' || lower.includes('wassce school')) {
                examType = 'W.A.S.S.C.E. (School)';
                typeCode = '01';
            } else if (lower === '2' || lower.includes('bece school') || lower === 'bece') {
                examType = 'B.E.C.E.';
                typeCode = '07';
            } else if (lower === '3' || lower.includes('novdec') || lower.includes('private wassce')) {
                examType = 'W.A.S.S.C.E. (Private)';
                typeCode = '08';
            } else if (lower === '4' || lower.includes('private bece')) {
                examType = 'B.E.C.E. (Private)';
                typeCode = '09';
            } else {
                return `*Invalid Selection*\n\nPlease reply with a number from *1 to 4*:\n1. *WASSCE* (School)\n2. *BECE* (School)\n3. *WASSCE* (Private / NovDec)\n4. *BECE* (Private)\n\n_(Reply *cancel* to abort)_`;
            }

            session.data.exam_type = examType;
            session.data.type_code = typeCode;
            session.step = 'waec_index';
            session.timestamp = now;

            return `Selected: *${examType}*\n\n*Step 2 of 4: Candidate Index Number*\nPlease enter your *10-Digit WAEC Candidate Index Number*:\n_(e.g. \`0010101001\`)_\n\n_(Reply *cancel* to abort)_`;
        }

        // WAEC Step 2: Index Number
        if (session.step === 'waec_index') {
            const cleanIndex = raw.replace(/\D/g, '');
            if (cleanIndex.length !== 10) {
                return `*Invalid Index Number!*\n\nWAEC Index Numbers must be exactly *10 digits* (e.g. \`0010101001\`).\nYou entered: \`${raw}\` (${cleanIndex.length} digits).\n\nPlease enter a valid 10-digit Index Number:\n_(Or reply *cancel* to abort)_`;
            }

            session.data.index_number = cleanIndex;
            session.step = 'waec_year';
            session.timestamp = now;

            return `Index Number: \`${cleanIndex}\` ✅\n\n*Step 3 of 4: Examination Year*\nPlease enter your *4-Digit Exam Year*:\n_(e.g. \`2024\`, \`2023\`, \`2022\`)_\n\n_(Reply *cancel* to abort)_`;
        }

        // WAEC Step 3: Exam Year
        if (session.step === 'waec_year') {
            const cleanYear = raw.replace(/\D/g, '');
            const currentYear = new Date().getFullYear();
            const yearNum = parseInt(cleanYear, 10);
            if (cleanYear.length !== 4 || isNaN(yearNum) || yearNum < 1995 || yearNum > (currentYear + 1)) {
                return `*Invalid Examination Year!*\n\nPlease enter a valid 4-digit exam year (e.g. \`2024\` or \`2023\`):\n_(Or reply *cancel* to abort)_`;
            }

            session.data.year = cleanYear;
            session.step = 'waec_pin_serial';
            session.timestamp = now;

            return `Exam Year: *${cleanYear}*\n\n*Step 4 of 4: Card Serial Number & PIN*\n━━━━━━━━━━━━━━━━━━━━━\nPlease paste your card details in any format:\n• \`WSC12345678 123456789012\`\n• \`Serial: WSC12345678, PIN: 123456789012\`\n\nOur system connects directly to WAEC servers to display your full result slip. \n\nNeed a card? Buy at: https://apexprime.club/digital_store\n_(Reply *cancel* to abort)_`;
        }

        // WAEC Step 4: Card Serial & PIN
        if (session.step === 'waec_pin_serial') {
            let serial = session.data.serial || '';
            let pin = session.data.pin || '';

            // Extract Serial
            const serialMatch = raw.match(/\b((?:WSC|BCE)[a-zA-Z0-9_\-]{5,20})\b/i) || raw.match(/\b([A-Za-z]{2,5}[0-9]{5,15})\b/i);
            if (serialMatch && !serial) serial = serialMatch[1].toUpperCase();

            // Extract PIN (10 to 14 digits)
            const pinMatch = raw.match(/\b(\d{10,14})\b/);
            if (pinMatch && !pin) pin = pinMatch[1];

            if (!serial && !pin) {
                const alphanumeric = raw.toUpperCase().replace(/[^A-Z0-9]/g, '');
                if (alphanumeric.length >= 6 && /[A-Z]/.test(alphanumeric) && /\d/.test(alphanumeric)) {
                    serial = alphanumeric;
                }
            }

            if (!serial || !pin) {
                if (serial && !pin) {
                    session.data.serial = serial;
                    session.timestamp = now;
                    return `Card Serial: \`${serial}\` ✅\n\nPlease now enter your *Card PIN* (10 to 12 digits):\n_(Reply *cancel* to abort)_`;
                } else if (pin && !serial) {
                    session.data.pin = pin;
                    session.timestamp = now;
                    return `Card PIN: \`${pin}\` ✅\n\nPlease now enter your *Card Serial Number* (e.g. \`WSC12345678\`):\n_(Reply *cancel* to abort)_`;
                }
                return `*Card Serial & PIN Required*\n\nPlease paste both your Card Serial Number and PIN:\n• Example: \`WSC12345678 123456789012\`\n\n_(Reply *cancel* to abort)_`;
            }

            customerSessions.delete(phone);

            // Execute live online WAEC check
            const resultMsg = await checkWaecOnline(
                session.data.exam_type || 'W.A.S.S.C.E. (School)',
                session.data.type_code || '01',
                session.data.index_number,
                session.data.year,
                serial,
                pin
            );

            return resultMsg;
        }
    }

    // ── INITIAL INTENT TRIGGERS (When no active session) ──

    // 1. Trigger "1" / "place order" / "buy"
    if (lower === '1' || lower === '1.' || ['place order', 'order', 'buy', 'buy bundle', 'buy data', 'packages', 'bundle', 'data'].includes(lower)) {
        customerSessions.set(phone, { step: 'order_user_code', data: {}, timestamp: now });
        return `*How to Register & Place Order on Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nFollow these quick steps to register and purchase data bundles, result checkers, or MTN AFA registrations:\n\n*Step 1: Create an Account (Register)*\n1. Visit our website: https://apexprime.club/register\n2. Fill in your *Username*, *Phone Number*, *Email*, and create a *Password*.\n3. Click *Register* to immediately create your account!\n_(Already have an account? Login at: https://apexprime.club/login)_\n\n*Step 2: Fund Your Wallet*\n1. From your dashboard, tap *Fund Wallet / Top-Up* (or go to: https://apexprime.club/topup).\n2. Pay via *Paystack* (Mobile Money or ATM Card) or direct MoMo.\n3. Your wallet will be credited instantly!\n\n*Step 3: Place Your Order*\n1. Tap *Buy Data Bundle* on your user dashboard.\n2. Select your network (*MTN*, *Telecel*, or *AT Ishare*).\n3. Choose your bundle package size (*1GB, 2GB, 5GB, 10GB*, etc.).\n4. Enter the recipient phone number.\n5. Tap *Buy Now* / *Submit*!\n\n*Automated Delivery*: Bundles are dispatched and delivered to the recipient line within seconds!\n\n━━━━━━━━━━━━━━━━━━━━━\n*Quick Shortcuts*:\n• Reply *4* to verify a payment reference & credit your wallet\n• Reply *3* or *status <order_id>* to track order delivery\n• Reply *balance* to check your current wallet balance\n• Or reply with your *User Code* (e.g. \`317\` or \`APEX-317\`) to order directly in chat\n• Reply *menu* to return to the main menu`;
    }

    // Direct 1-line WAEC check command (e.g. "check wassce 0010101001 2024 WSC12345678 123456789012")
    const directWaec = raw.match(/^(?:check\s+|waec\s+)?(wassce|bece)\s+(\d{10})\s+(\d{4})\s+([A-Za-z0-9_\-]+)\s+(\d{10,14})/i);
    if (directWaec) {
        const examName = directWaec[1].toUpperCase().includes('BECE') ? 'B.E.C.E.' : 'W.A.S.S.C.E. (School)';
        const code = directWaec[1].toUpperCase().includes('BECE') ? '07' : '01';
        return await checkWaecOnline(examName, code, directWaec[2], directWaec[3], directWaec[4].toUpperCase(), directWaec[5]);
    }

    // 2. Trigger "2" / "check result" / "result checker" / "checker"
    if (lower === '2' || lower === '2.' || ['check result', 'result', 'results', 'waec', 'checker', 'result checker'].includes(lower)) {
        customerSessions.set(phone, { step: 'waec_exam_type', data: {}, timestamp: now });
        return `*WAEC Result Checker — WASSCE & BECE Guide*\n━━━━━━━━━━━━━━━━━━━━━\nHere is how to buy Result Checker cards and check your WASSCE or BECE results online:\n\n*Where to Buy Result Checker Cards*:\nBuy genuine WASSCE & BECE checker cards with instant card PIN & serial delivery via:\n1. *Apex Prime Digital Store*:\n   https://apexprime.club/digital_store\n2. *Instant Payroute Direct Link (MoMo / Card)*:\n   https://payroute.name/mr-nipah\n\n━━━━━━━━━━━━━━━━━━━━━\n*Steps to Check WASSCE Results Online*:\n1. Go to: https://ghana.waecdirect.org/\n2. Enter your 10-digit *Index Number* (e.g. \`0010101001\`)\n3. Select Exam Type: *W.A.S.S.C.E. (School)* or *(Private)*\n4. Select Exam Year (e.g. *2024*)\n5. Enter your *Card Serial Number* (e.g. \`WSC12345678\`)\n6. Enter your 12-digit *Card PIN*\n7. Click *Submit* to view and print your result slip!\n\n━━━━━━━━━━━━━━━━━━━━━\n*Steps to Check BECE Results Online*:\n1. Go to: https://ghana.waecdirect.org/\n2. Enter your 10-digit *Index Number* (e.g. \`0010101001\`)\n3. Select Exam Type: *B.E.C.E. (School)* or *(Private)*\n4. Select Exam Year (e.g. *2024*)\n5. Enter your *Card Serial Number* (e.g. \`BCE12345678\`)\n6. Enter your 12-digit *Card PIN*\n7. Click *Submit* to view and print your result slip!\n\n━━━━━━━━━━━━━━━━━━━━━\n*Check Result in WhatsApp*:\nReply *wassce* or *bece* to let our bot check your result and calculate your aggregates automatically right here!\n• Reply *my pins* to view checker cards purchased on this number\n• Reply *menu* to return to the main menu`;
    }

    // 3. Trigger "3" / "check status" / "track" / "status"
    if (lower === '3' || lower === '3.' || ['check status', 'status', 'track', 'track order', 'order status'].includes(lower)) {
        customerSessions.set(phone, { step: 'check_status_query', data: {}, timestamp: now });
        return `*Track Order Status — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nPlease enter your *Order ID* (e.g. \`16523\`) or *Recipient Phone Number* to check status:\n\n_(Reply *cancel* to abort)_`;
    }

    // Quick 1-line status check e.g. "status 16523" or "status 0541145310"
    if (lower.startsWith('status ') || lower.startsWith('track ')) {
        const query = raw.replace(/^(status|track)\s+/i, '').trim();
        const statusRes = await callWebsiteApi({ op: 'check_status', search: query });
        if (statusRes && statusRes.success && statusRes.order) {
            const o = statusRes.order;
            return `*ORDER STATUS REPORT*\n━━━━━━━━━━━━━━━━━━━━━\nOrder ID: \`#${o.id}\`\nNetwork: ${o.network || 'Data'}\nRecipient: \`${o.recipient_phone || 'N/A'}\`\nBundle: ${o.gb_amount || '1'} GB\nDelivery Status: *${(o.status || 'processing').toUpperCase()}*\nGateway Message: ${o.message || 'Dispatched via Gateway'}\nDate Placed: ${o.created_at || 'Recently'}\n━━━━━━━━━━━━━━━━━━━━━`;
        } else {
            return `No order found matching \`${query}\`. Please verify your Order ID or phone number.`;
        }
    }

    // Direct 1-line verify command (e.g. "verify APX-1725894123" or "verify 24892019482")
    if (lower.startsWith('verify ') || lower.startsWith('verify:')) {
        const cleanRef = raw.replace(/^verify[:\s]+/i, '').trim().replace(/[^a-zA-Z0-9_\-]/g, '');
        if (cleanRef.length >= 4) {
            customerSessions.delete(phone);
            const verifyApiRes = await callWebsiteApi({ op: 'verify_payment', reference: cleanRef, phone, name });
            if (verifyApiRes && verifyApiRes.success && verifyApiRes.reply) return verifyApiRes.reply;
            return new Promise((resolve) => {
                if (!fs.existsSync(BRIDGE_SCRIPT)) return resolve(`*Verification Error*\nUnable to connect to verification gateway. Please try again or contact support at 0553381853.`);
                execFile('php', [BRIDGE_SCRIPT, phone, `verify ${cleanRef}`, name || 'Customer'], { timeout: 15000 }, (error, stdout) => {
                    if (error || !stdout) return resolve(`*Verification Error*\nUnable to process verification right now. Please try again shortly or contact support.`);
                    try {
                        const res = JSON.parse(stdout.trim());
                        if (res && res.reply) return resolve(res.reply);
                    } catch (e) {}
                    resolve(`*Verification Complete*\nTransaction was submitted for verification. Reply *balance* to check your updated wallet balance.`);
                });
            });
        }
    }

    // 4. Trigger "4" / "verify payment"
    if (lower === '4' || lower === '4.' || ['verify payment', 'verify', 'payment', 'paid', 'check payment'].includes(lower)) {
        customerSessions.set(phone, { step: 'verify_payment_ref', data: {}, timestamp: now });
        return `*Payment Verification — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nTo verify your payment and update your wallet or order:\n\nPlease reply with your *Paystack Reference* or *MoMo Transaction ID*:\n• Example Paystack: \`T1234567890\` or \`APX-1725894123\`\n• Example MoMo ID: \`24892019482\`\n\n_(Reply *cancel* anytime to abort)_`;
    }

    // 5. Trigger "5" / "talk to an agent" / "agent" / "support"
    if (lower === '5' || lower === '5.' || ['talk to an agent', 'talk to agent', 'agent', 'support', 'human'].includes(lower)) {
        return `*Talk to an Agent — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nOur customer support team is here to assist you 24/7!\n\nPhone / WhatsApp: *0553381853*\nDirect WhatsApp: https://wa.me/233553381853\nWebsite: https://apexprime.club\n\nPlease send your message or question right here, and an agent will attend to you shortly!`;
    }

    // 6. Trigger "6" / "other services" (Academic Writing, Website Design, Apple Plans, Merchant Onboarding)
    if (lower === '6' || lower === '6.' || ['other services', 'other service', 'services', 'academic writing', 'website design', 'website designing', 'apple plans', 'apple plan', 'merchant onboarding'].includes(lower)) {
        return `*Apex Prime Tech — Other Services*\n━━━━━━━━━━━━━━━━━━━━━\nWe offer professional, reliable digital & tech services tailored for your academic and business success:\n\n*1. Academic Writing & Research*\n• Term papers, essays, research proposals & thesis/dissertations\n• Literature reviews, editing, formatting & proofreading\n• Data analysis & interpretation (SPSS, Excel, Python, R)\n• 100% original, AI-free & plagiarism-checked content\n\n*2. Website Designing & Development*\n• Modern business, corporate & portfolio websites\n• Online stores & eCommerce portals with MoMo/Card payments\n• Custom web applications, school/hospital management portals\n• Fast cloud hosting, custom domain, professional emails & SSL\n\n*3. Apple Plans & Subscriptions*\n• Apple Developer accounts registration & setup assistance\n• iCloud+ storage upgrade plans & cloud backups\n• Apple Music, Apple Arcade & family sharing setup\n• Apple ID configuration, device setup & region switching\n\n*4. Merchant Onboarding & Agency*\n• Become an Apex Prime Data Bundle & WAEC Reseller Agent\n• Access wholesale pricing to maximize your profit margins\n• Merchant Mobile Money payment gateway integration\n• Dedicated merchant portal with instant automated delivery\n\n━━━━━━━━━━━━━━━━━━━━━\n*How to Order or Get a Quote*:\n• Reply *5* to chat with an agent right now!\n• Direct WhatsApp: 0553381853 (https://wa.me/233553381853)\n• Visit our website: https://apexprime.club\n• Reply *menu* to return to the main menu`;
    }

    // 7. Trigger "7" / "link" / "link bot" / "personal bot" / "phone number"
    if (lower === '7' || lower === '7.' || ['link', 'link bot', 'phone number', 'personal bot', 'activate bot', 'link with phone number', 'link phone'].includes(lower)) {
        return `*Link WhatsApp with Phone Number (Personal Bot)*\n━━━━━━━━━━━━━━━━━━━━━\nActivate your own personal WhatsApp bot directly on your phone number without scanning any QR code!\n\n*Features of Your Personal Bot:*\n• *Anti-Delete Recovery:* View deleted messages & photos forwarded privately to your DM.\n• *Save View-Once:* View-once images & videos are unlocked and saved automatically.\n• *Full-Duration Music:* Download complete songs by typing *.play <song name>*.\n• *Video Downloader:* Automatic TikTok, YouTube, and Instagram reel downloads.\n• *Auto-View & Auto-Like Status:* Automatically view contact statuses and react with emojis.\n• *Apex AI Assistant:* Ask questions anytime with *@Apex_Assistant260* or *.ai*.\n• *100% Private Mode:* The bot runs as your personal tool — it will NEVER send customer auto-replies to your friends or contacts!\n\n━━━━━━━━━━━━━━━━━━━━━\n*How to Link Your WhatsApp in 1 Minute:*\n1. Visit: https://apexprime.club/whatsapp_bot_activation\n2. Click *Link with Phone Number*\n3. Enter your WhatsApp number (e.g. \`0559623850\`)\n4. Copy the *8-digit Pairing Code* shown on screen\n5. Open WhatsApp > tap *Linked Devices* > *Link a Device* > *Link with phone number instead*\n6. Enter the 8-digit code to link instantly!\n\n*Link Your Account Now:*\nhttps://apexprime.club/whatsapp_bot_activation\n\n_(Reply *menu* to return to the main menu)_`;
    }

    // 8. Trigger "8" / "buy from me"
    if (lower === '8' || lower === '8.' || ['buy from me', 'buy fromme', 'buyfromme'].includes(lower)) {
        customerSessions.set(phone, { step: 'order_user_code', data: {}, timestamp: now });
        return `*Buy From Me — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nPlease enter your *User Code* to continue:\n• Example: \`APEX-317\` or \`317\`\n\n_(Your User Code is your Apex Prime account ID on our portal)_\n_(Reply *cancel* anytime to abort)_`;
    }

    // Trigger "balance" / "wallet"
    if (lower === 'balance' || lower === 'wallet') {
        const userRes = await callWebsiteApi({ op: 'lookup_user', search: phone });
        if (userRes && userRes.success && userRes.user) {
            const u = userRes.user;
            return `*Apex Prime Wallet Balance*\n━━━━━━━━━━━━━━━━━━━━━\nUser: *${u.username}* (\`APEX-${u.id}\`)\nPhone: \`${u.phone}\`\nBalance: *GHS ${parseFloat(u.wallet_balance || 0).toFixed(2)}*\nTier: *${(u.role || 'client').toUpperCase()}*\n━━━━━━━━━━━━━━━━━━━━━\nTop up your wallet anytime at: https://apexprime.club/topup`;
        } else {
            return `*Apex Prime Wallet Balance*\n━━━━━━━━━━━━━━━━━━━━━\nPlease enter your *User Code* (e.g. \`317\` or \`APEX-317\`) to check your balance, or login at:\nhttps://apexprime.club/login`;
        }
    }

    // If user enters just a User Code (e.g. "317" or "APEX-317" or "apex317") directly
    if (/^(?:apex[\s\-_]*)?\d{1,6}$/i.test(raw)) {
        const userRes = await callWebsiteApi({ op: 'lookup_user', search: raw });
        if (userRes && userRes.success && userRes.user) {
            const u = userRes.user;
            const bal = parseFloat(u.wallet_balance || 0);
            const role = (u.role || 'client').toUpperCase();

            if (bal < 3.50) {
                customerSessions.set(phone, {
                    step: 'order_topup_txid',
                    data: { user_id: u.id, username: u.username, wallet_balance: bal, role: u.role || 'client' },
                    timestamp: now
                });
                return `*User Verified*: *${u.username}* (\`APEX-${u.id}\`)\n*Tier*: *${role}*\n*Current Wallet Balance*: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\n*Insufficient Wallet Balance*\n\nYour balance is too low to place an order. Please top up your wallet:\n\nMoMo Number: \`0530429556\`\nAccount Name: *Sir Esarq Ent (Eric Fosu)*\nPayment Reference: \`APEX-${u.id}\`\n\nAfter sending money, reply with your *Transaction ID* right here to automatically credit your wallet:\n\n_(Reply *cancel* to abort)_`;
            }

            customerSessions.set(phone, {
                step: 'order_select_network',
                data: { user_id: u.id, username: u.username, wallet_balance: bal, role: u.role || 'client' },
                timestamp: now
            });
            return `*User Verified*: *${u.username}* (\`APEX-${u.id}\`)\n*Tier*: *${role}*\n*Wallet Balance*: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nPlease choose an option by replying with a number (*1 - 5*):\n\n1. *MTN Data Bundles*\n2. *Telecel Data Bundles*\n3. *AT / AirtelTigo Ishare*\n4. *MTN AFA Registration*\n5. *Result Checker Cards (WASSCE / BECE)*\n\n_(Reply *cancel* anytime to abort)_`;
        }
    }

    return null;
}

/**
 * Call full interactive session engine connected to Apex Prime live database,
 * prioritizing the local PHP CLI bridge with MySQL connection, and falling back
 * to the Node.js interactive session engine.
 */
async function processMessageViaBridge(phone, text, name) {
    // 1. Prioritize PHP CLI bridge (executes full WhatsAppBot.php connected to database)
    if (fs.existsSync(BRIDGE_SCRIPT)) {
        try {
            const bridgeRes = await new Promise((resolve) => {
                execFile('php', [BRIDGE_SCRIPT, phone, text, name || 'Customer'], { timeout: 15000 }, (error, stdout) => {
                    if (error || !stdout) return resolve(null);
                    try {
                        const res = JSON.parse(stdout.trim());
                        if (res && res.handled && res.reply) return resolve(res);
                    } catch (e) {}
                    resolve(null);
                });
            });
            if (bridgeRes) return bridgeRes;
        } catch (bridgeErr) {
            console.error('[PHP Bridge Error]:', bridgeErr.message);
        }
    }

    // 2. Fallback to JavaScript customer interactive session engine
    try {
        const interactiveReply = await handleCustomerInteractiveSession(phone, text, name);
        if (interactiveReply) {
            return { handled: true, reply: interactiveReply };
        }
    } catch (e) {
        console.error('[Interactive Session Error]:', e.message);
    }

    return null;
}

/**
 * Load commands and userbot configuration from bot_commands.json
 */
function loadCommandsConfig() {
    try {
        if (fs.existsSync(COMMANDS_FILE)) {
            const raw = fs.readFileSync(COMMANDS_FILE, 'utf8');
            const data = JSON.parse(raw);
            if (!data.userbot_settings) {
                data.userbot_settings = {
                    autoview: false,
                    autolike: false,
                    autolike_emoji: '❤️',
                    savedviews: false,
                    recoverydeleted: false,
                    autosavecontact: false
                };
            }
            return data;
        }
    } catch (err) {
        console.error('[Config] Error loading bot_commands.json:', err.message);
    }
    return {
        bot_enabled: true,
        fallback_enabled: true,
        fallback_message: "Hello {name}! Welcome to Apex Prime.\nType *menu* to see available commands.",
        ignored_numbers: [],
        userbot_settings: {
            autoview: false,
            autolike: false,
            autolike_emoji: '❤️',
            savedviews: false,
            recoverydeleted: false,
            autosavecontact: false
        },
        commands: []
    };
}

/**
 * Save configuration to bot_commands.json
 */
function saveCommandsConfig(cfg) {
    try {
        fs.writeFileSync(COMMANDS_FILE, JSON.stringify(cfg, null, 2), 'utf8');
        return true;
    } catch (err) {
        console.error('[Config] Error saving bot_commands.json:', err.message);
        return false;
    }
}

/**
 * Get normalized Owner JID
 */
function getOwnerJid(sock) {
    if (!sock?.user?.id) return null;
    const phone = sock.user.id.split(':')[0].replace(/\D/g, '');
    return `${phone}@s.whatsapp.net`;
}

/**
 * Check if a sender is an Admin or the Owner
 */
function isAdminOrOwner(phone, jid, isFromMe, config) {
    if (isFromMe) return true;
    const clean = String(phone).replace(/\D/g, '');
    if (!clean) return false;

    // The account owner themselves (active WhatsApp session)
    if (connectedPhone) {
        const cleanConn = String(connectedPhone).replace(/\D/g, '');
        if (cleanConn && (clean === cleanConn || (clean.length >= 9 && cleanConn.length >= 9 && clean.slice(-9) === cleanConn.slice(-9)))) {
            return true;
        }
    }

    const ignored = config.ignored_numbers || [];
    return ignored.some(ign => {
        const cleanIgn = String(ign).replace(/\D/g, '');
        if (!cleanIgn) return false;
        return clean === cleanIgn || (clean.length >= 9 && cleanIgn.length >= 9 && clean.slice(-9) === cleanIgn.slice(-9));
    });
}

/**
 * Download media stream into a Buffer
 */
async function downloadMediaBuffer(mediaObj, type) {
    if (!mediaObj) return null;
    try {
        const stream = await downloadContentFromMessage(mediaObj, type);
        let buffer = Buffer.from([]);
        for await (const chunk of stream) {
            buffer = Buffer.concat([buffer, chunk]);
        }
        return buffer;
    } catch (e) {
        console.error(`[Media Download Error ${type}]:`, e.message);
        return null;
    }
}

/**
 * Extract View-Once Media from a message
 */
function extractViewOnce(msg) {
    const rawMsg = msg.message;
    if (!rawMsg) return null;

    let inner = null;
    if (rawMsg.viewOnceMessage?.message) {
        inner = rawMsg.viewOnceMessage.message;
    } else if (rawMsg.viewOnceMessageV2?.message) {
        inner = rawMsg.viewOnceMessageV2.message;
    } else if (rawMsg.viewOnceMessageV2Extension?.message) {
        inner = rawMsg.viewOnceMessageV2Extension.message;
    } else if (rawMsg.imageMessage?.viewOnce) {
        inner = { imageMessage: rawMsg.imageMessage };
    } else if (rawMsg.videoMessage?.viewOnce) {
        inner = { videoMessage: rawMsg.videoMessage };
    } else if (rawMsg.audioMessage?.viewOnce) {
        inner = { audioMessage: rawMsg.audioMessage };
    }

    if (!inner) return null;
    if (inner.imageMessage) return { type: 'image', media: inner.imageMessage };
    if (inner.videoMessage) return { type: 'video', media: inner.videoMessage };
    if (inner.audioMessage) return { type: 'audio', media: inner.audioMessage };
    return null;
}

/**
 * Cache message in memory for anti-delete recovery
 */
async function cacheMessage(msg) {
    if (!msg?.key?.id || msg.key.remoteJid === 'status@broadcast') return;
    if (msg.message?.protocolMessage) return;

    const senderPhone = (msg.key.participant || msg.key.remoteJid || '').replace(/@.+/, '');
    const pushName = msg.pushName || 'Unknown';
    const rawMsg = msg.message;
    if (!rawMsg) return;

    let text = rawMsg.conversation
        || rawMsg.extendedTextMessage?.text
        || rawMsg.imageMessage?.caption
        || rawMsg.videoMessage?.caption
        || rawMsg.documentMessage?.caption
        || '';

    let mediaType = null;
    let mediaBuffer = null;

    // Cache small media (images, stickers, audio) for deleted recovery
    try {
        if (rawMsg.imageMessage && (!rawMsg.imageMessage.fileLength || rawMsg.imageMessage.fileLength < 5 * 1024 * 1024)) {
            mediaType = 'image';
            mediaBuffer = await downloadMediaBuffer(rawMsg.imageMessage, 'image');
        } else if (rawMsg.stickerMessage) {
            mediaType = 'sticker';
            mediaBuffer = await downloadMediaBuffer(rawMsg.stickerMessage, 'sticker');
        } else if (rawMsg.audioMessage) {
            mediaType = 'audio';
            mediaBuffer = await downloadMediaBuffer(rawMsg.audioMessage, 'audio');
        }
    } catch (e) {}

    // Limit cache size
    if (messageStore.size > MAX_CACHE_MESSAGES) {
        const oldestKey = messageStore.keys().next().value;
        messageStore.delete(oldestKey);
    }

    messageStore.set(msg.key.id, {
        id: msg.key.id,
        from: msg.key.remoteJid,
        senderPhone,
        pushName,
        timestamp: msg.messageTimestamp || Math.floor(Date.now() / 1000),
        text,
        mediaType,
        mediaBuffer
    });
}

/**
 * Save new contact to saved_contacts.json and contacts_export.vcf
 */
function saveNewContact(phone, name) {
    if (!phone) return null;
    const cleanPhone = phone.replace(/\D/g, '');
    if (cleanPhone.length < 9) return null;

    const contactsFile = path.resolve(__dirname, 'saved_contacts.json');
    const vcfFile = path.resolve(__dirname, 'contacts_export.vcf');

    let contacts = [];
    try {
        if (fs.existsSync(contactsFile)) {
            contacts = JSON.parse(fs.readFileSync(contactsFile, 'utf8'));
        }
    } catch (e) {}

    const exists = contacts.some(c => c.phone === cleanPhone);
    if (!exists) {
        const contactName = name && name !== 'Customer' && name !== 'Unknown' ? name : `Apex Client ${cleanPhone.slice(-4)}`;
        contacts.push({ phone: cleanPhone, name: contactName, addedAt: new Date().toISOString() });
        fs.writeFileSync(contactsFile, JSON.stringify(contacts, null, 2));

        const vcard = `BEGIN:VCARD\r\nVERSION:3.0\r\nFN:${contactName}\r\nTEL;TYPE=CELL:+${cleanPhone}\r\nNOTE:Auto-saved by Apex Prime WhatsApp Bot\r\nEND:VCARD\r\n`;
        fs.appendFileSync(vcfFile, vcard);
        console.log(`[Auto-Save Contact] Saved: ${contactName} (+${cleanPhone})`);
        return { name: contactName, phone: cleanPhone, total: contacts.length };
    }
    return null;
}

/**
 * Match an incoming text message against configured commands
 */
function findMatchingResponse(messageText, senderName, senderPhone) {
    const config = loadCommandsConfig();
    if (!config.bot_enabled) return null;

    const rawInput = (messageText || '').trim().toLowerCase();
    if (!rawInput) return null;

    // Clean punctuation around single numbers e.g. "1." -> "1"
    const cleanInput = rawInput.replace(/^[#\.\s]+|[#\.\s]+$/g, '');
    const commands = config.commands || [];

    // Phase 1: Check for exact trigger matches first (high priority for "1", "2", "3", "4", etc.)
    for (const cmd of commands) {
        if (!cmd.is_active) continue;
        const triggers = (cmd.command_trigger || '')
            .split(',')
            .map(t => t.trim().toLowerCase())
            .filter(Boolean);

        if (triggers.includes(cleanInput) || triggers.includes(rawInput)) {
            return formatResponse(cmd.response_text, senderName, senderPhone);
        }
    }

    // Phase 2: Check matching rules (starts_with, contains)
    for (const cmd of commands) {
        if (!cmd.is_active) continue;

        const triggers = (cmd.command_trigger || '')
            .split(',')
            .map(t => t.trim().toLowerCase())
            .filter(Boolean);

        const matchType = cmd.match_type || 'contains';
        let isMatch = false;

        if (matchType === 'exact') {
            isMatch = triggers.some(t => cleanInput === t || rawInput === t);
        } else if (matchType === 'starts_with') {
            isMatch = triggers.some(t => cleanInput.startsWith(t) || rawInput.startsWith(t));
        } else {
            // contains (avoid false positives on short triggers <= 2 chars like "1")
            isMatch = triggers.some(t => {
                if (t.length <= 2) return cleanInput === t;
                return cleanInput.includes(t) || rawInput.includes(t);
            });
        }

        if (isMatch) {
            return formatResponse(cmd.response_text, senderName, senderPhone);
        }
    }

    // If no command matched and fallback is enabled
    if (config.fallback_enabled && config.fallback_message) {
        return formatResponse(config.fallback_message, senderName, senderPhone);
    }

    return null;
}

/**
 * Replace dynamic placeholders in response text
 */
function formatResponse(template, name, phone) {
    if (!template) return '';
    return template
        .replace(/\{name\}/gi, name || 'Customer')
        .replace(/\{phone\}/gi, phone || '')
        .replace(/\{balance_info\}/gi, '')
        .trim();
}

/**
 * Handle personal userbot owner commands sent in DM or self-chat
 */
async function handleOwnerCommands(sock, msg, from, text, senderPhone, pushName, isFromMe) {
    const raw = (text || '').trim();
    const cmd = raw.toLowerCase().replace(/\s+/g, ' ');
    const config = loadCommandsConfig();
    const ub = config.userbot_settings = config.userbot_settings || {
        autoview: false,
        autolike: false,
        autolike_emoji: '❤️',
        savedviews: false,
        recoverydeleted: false,
        autosavecontact: false
    };

    let replyText = null;

    // 1. autoview on / off
    if (cmd === 'autoview on' || cmd === '.autoview on') {
        ub.autoview = true;
        saveCommandsConfig(config);
        replyText = `👁️ *Auto-View Statuses: ACTIVATED (🟢 ON)*\nThe bot will now automatically view and mark as read all contact status updates.`;
    } else if (cmd === 'autoview off' || cmd === '.autoview off') {
        ub.autoview = false;
        saveCommandsConfig(config);
        replyText = `👁️ *Auto-View Statuses: DEACTIVATED (🔴 OFF)*\nStatus auto-viewing is now turned off.`;
    }

    // 2. autolike on / off
    else if (cmd === 'autolike on' || cmd === '.autolike on') {
        ub.autolike = true;
        saveCommandsConfig(config);
        replyText = `❤️ *Auto-Like Statuses: ACTIVATED (🟢 ON)*\nThe bot will automatically react with ${ub.autolike_emoji || '❤️'} to viewed status updates.`;
    } else if (cmd === 'autolike off' || cmd === '.autolike off') {
        ub.autolike = false;
        saveCommandsConfig(config);
        replyText = `❤️ *Auto-Like Statuses: DEACTIVATED (🔴 OFF)*\nStatus auto-reactions are now turned off.`;
    } else if (cmd.startsWith('autolike emoji ') || cmd.startsWith('.autolike emoji ') || cmd.startsWith('.statusemoji ') || cmd.startsWith('statusemoji ') || cmd.startsWith('.setemoji ') || cmd.startsWith('setemoji ')) {
        const parts = raw.trim().split(/\s+/);
        const emoji = parts[parts.length - 1] || '❤️';
        ub.autolike_emoji = emoji;
        saveCommandsConfig(config);
        replyText = `❤️ *Auto-Like Status Emoji Updated:* ${emoji}\nAll future status updates will be reacted to with ${emoji}! (Ensure *autolike on* is active)`;
    }

    // 3. recoverydeleted on / off
    else if (cmd === 'recoverydeleted on' || cmd === 'recovery deleted on' || cmd === 'antidelete on' || cmd === '.recoverydeleted on') {
        ub.recoverydeleted = true;
        saveCommandsConfig(config);
        replyText = `🗑️ *Anti-Delete Recovery: ACTIVATED (🟢 ON)*\nAny deleted messages or media in your chats will be captured and forwarded directly to your personal DM!`;
    } else if (cmd === 'recoverydeleted off' || cmd === 'recovery deleted off' || cmd === 'antidelete off' || cmd === '.recoverydeleted off') {
        ub.recoverydeleted = false;
        saveCommandsConfig(config);
        replyText = `🗑️ *Anti-Delete Recovery: DEACTIVATED (🔴 OFF)*\nDeleted message tracking is now turned off.`;
    }

    // 4. savedviews on / off (Anti-View-Once)
    else if (cmd === 'savedviews on' || cmd === 'savedviews messages once images' || cmd === 'antiviewonce on' || cmd === 'viewonce on' || cmd === '.savedviews on') {
        ub.savedviews = true;
        saveCommandsConfig(config);
        replyText = `📸 *Anti View-Once Media Save: ACTIVATED (🟢 ON)*\nAny View-Once images or videos sent to you will be permanently unlocked and forwarded to your personal DM!`;
    } else if (cmd === 'savedviews off' || cmd === 'antiviewonce off' || cmd === 'viewonce off' || cmd === '.savedviews off') {
        ub.savedviews = false;
        saveCommandsConfig(config);
        replyText = `📸 *Anti View-Once Media Save: DEACTIVATED (🔴 OFF)*\nView-once media saving is now turned off.`;
    }

    // 5. auto save contact on / off
    else if (cmd === 'auto save contact on' || cmd === 'autosavecontact on' || cmd === 'savecontact on' || cmd === '.autosavecontact on') {
        ub.autosavecontact = true;
        saveCommandsConfig(config);
        replyText = `📇 *Auto-Save Contacts: ACTIVATED (🟢 ON)*\nAll new incoming numbers will automatically be logged and added to your contacts export VCF file!`;
    } else if (cmd === 'auto save contact off' || cmd === 'autosavecontact off' || cmd === 'savecontact off' || cmd === '.autosavecontact off') {
        ub.autosavecontact = false;
        saveCommandsConfig(config);
        replyText = `📇 *Auto-Save Contacts: DEACTIVATED (🔴 OFF)*\nAuto contact saving is now turned off.`;
    }

    // Personal Bot Mode / Customer Auto-Reply Toggle
    else if (cmd === 'personalmode on' || cmd === '.personalmode on' || cmd === 'personal on' || cmd === 'autoreply off' || cmd === '.autoreply off') {
        ub.personal_mode_only = true;
        ub.autoreply = false;
        saveCommandsConfig(config);
        saveSessionInfo({ personal_bot_mode: true, linked_via: 'phone_number' });
        activeConnectionMethod = 'phone_number';
        replyText = `🔒 *Personal Bot Mode: ACTIVATED (🟢 ON)*\nCustomer auto-replies to other people are SILENCED. The bot will only perform personal utilities (Anti-Delete, Status Saver, Music, Video Downloads, and Owner Commands).`;
    } else if (cmd === 'personalmode off' || cmd === '.personalmode off' || cmd === 'personal off' || cmd === 'autoreply on' || cmd === '.autoreply on') {
        ub.personal_mode_only = false;
        ub.autoreply = true;
        saveCommandsConfig(config);
        saveSessionInfo({ personal_bot_mode: false, linked_via: 'qr_code' });
        activeConnectionMethod = 'qr_code';
        replyText = `🏪 *Business SQR Auto-Reply Mode: ACTIVATED (🟢 ON)*\nThe bot will now auto-reply to incoming customer messages with store menus & guides.`;
    }

    // 6. .tagall / .everyone (Group Tagging)
    else if (cmd.startsWith('.tagall') || cmd.startsWith('tagall') || cmd.startsWith('.everyone') || cmd.startsWith('everyone')) {
        if (!from.endsWith('@g.us')) {
            replyText = `❌ *Group Only:* The *.tagall* command can only be used inside WhatsApp groups!`;
        } else {
            try {
                const groupMeta = await sock.groupMetadata(from);
                const participants = groupMeta.participants || [];
                const mentions = participants.map(p => p.id);
                const extraText = raw.replace(/^(\.tagall|tagall|\.everyone|everyone)/i, '').trim();

                let tagMsg = `📢 *GROUP ANNOUNCEMENT* 📢\n👥 *Group:* ${groupMeta.subject}\n`;
                if (extraText) {
                    tagMsg += `💬 *Message:* ${extraText}\n`;
                }
                tagMsg += `\n━━━━━━━━━━━━━━━━━━━━━\n👥 *Tagged Members (${participants.length}):*\n`;
                for (const p of participants) {
                    tagMsg += `• @${p.id.replace(/@.+/, '')}\n`;
                }
                tagMsg += `━━━━━━━━━━━━━━━━━━━━━`;
                await sock.sendMessage(from, { text: tagMsg, mentions }, { quoted: msg });
                return true;
            } catch (err) {
                replyText = `❌ Failed to tag group members: ${err.message}`;
            }
        }
    }

    // 7. .hidetag (Silent Group Mention)
    else if (cmd.startsWith('.hidetag') || cmd.startsWith('hidetag')) {
        if (!from.endsWith('@g.us')) {
            replyText = `❌ *Group Only:* The *.hidetag* command can only be used inside WhatsApp groups!`;
        } else {
            try {
                const groupMeta = await sock.groupMetadata(from);
                const participants = groupMeta.participants || [];
                const mentions = participants.map(p => p.id);
                const message = raw.replace(/^(\.hidetag|hidetag)/i, '').trim() || 'Attention everyone!';

                await sock.sendMessage(from, { text: `📢 *Notification:*\n\n${message}`, mentions }, { quoted: msg });
                return true;
            } catch (err) {
                replyText = `❌ Failed to send hidetag: ${err.message}`;
            }
        }
    }

    // 8. .vv (On-Demand View-Once Unlocker)
    else if (cmd === '.vv' || cmd === 'vv' || cmd === '.viewonce' || cmd === 'viewonce') {
        const quoted = msg.message?.extendedTextMessage?.contextInfo?.quotedMessage;
        let vo = null;
        if (quoted) {
            vo = extractViewOnce({ message: quoted });
        }
        if (!vo) {
            vo = extractViewOnce(msg);
        }

        if (vo) {
            try {
                const buffer = await downloadMediaBuffer(vo.media, vo.type);
                if (buffer) {
                    const caption = `🔓 *[Unlocked View-Once Media]*`;
                    if (vo.type === 'image') {
                        await sock.sendMessage(from, { image: buffer, caption }, { quoted: msg });
                    } else if (vo.type === 'video') {
                        await sock.sendMessage(from, { video: buffer, caption }, { quoted: msg });
                    } else if (vo.type === 'audio') {
                        await sock.sendMessage(from, { audio: buffer, mimetype: 'audio/mp4', ptt: true }, { quoted: msg });
                    }
                    return true;
                } else {
                    replyText = `❌ Could not download media buffer. It may have expired.`;
                }
            } catch (e) {
                replyText = `❌ Error retrieving view-once media: ${e.message}`;
            }
        } else {
            replyText = `⚠️ *How to use .vv:* Reply directly to any View-Once image, video, or audio with *.vv* to unlock and re-send it!`;
        }
    }

    // 9. .readmore (WhatsApp Read More Generator)
    else if (cmd.startsWith('.readmore ') || cmd.startsWith('readmore ')) {
        const content = raw.replace(/^(\.readmore|readmore)/i, '').trim();
        const readMoreChar = String.fromCharCode(8206).repeat(4001);
        if (content.includes('|')) {
            const parts = content.split('|');
            const preview = parts[0].trim();
            const hidden = parts.slice(1).join('|').trim();
            replyText = `${preview} ${readMoreChar}\n\n${hidden}`;
        } else {
            replyText = `${content} ${readMoreChar}\n\n👉 Surprise! This message was hidden behind Read More.`;
        }
    }

    // 10. .commands / .help / .menu (Alexa Covert Menu)
    else if (cmd === '.commands' || cmd === 'commands' || cmd === '.help' || cmd === '.menu' || cmd === '.alexa' || cmd === 'alexa') {
        const isPers = isPersonalBotSession();
        replyText = `*ALEXA COVERT — PERSONAL ASSISTANT*\n━━━━━━━━━━━━━━━━━━━━━\nHello! I am your personal multi-device WhatsApp assistant.\n\n*STATUS & SETTINGS:*\n• Bot Mode: ${isPers ? '🔒 *Personal Bot*' : '🏪 *Business SQR Bot*'}\n• Customer Auto-Reply: ${isPers ? '🔴 *OFF (Chats Protected)*' : '🟢 *ON (Store Menu active)*'}\n• Auto-View Status: ${ub.autoview ? '🟢 *ON*' : '🔴 *OFF*'}\n• Auto-Like Status: ${ub.autolike ? `🟢 *ON* (${ub.autolike_emoji || '❤️'})` : '🔴 *OFF*'}\n• Anti-Delete: ${ub.recoverydeleted ? '🟢 *ON*' : '🔴 *OFF*'}\n• Save View-Once: ${ub.savedviews ? '🟢 *ON*' : '🔴 *OFF*'}\n• Auto-Save Contacts: ${ub.autosavecontact ? '🟢 *ON*' : '🔴 *OFF*'}\n\n━━━━━━━━━━━━━━━━━━━━━\n🎵 *MUSIC & AUDIO:*\n• *.play <song name>* (e.g. *.play Burna Boy City Boys*)\n  _Downloads 100% full-duration song directly (never 29s preview!)._\n• *.lyrics <song name>* (e.g. *.lyrics Coldplay Yellow*)\n  _Fetches full song lyrics._\n• *.tts <text>* (e.g. *.tts Welcome to Ghana*)\n  _Converts text to realistic WhatsApp voice audio._\n\n━━━━━━━━━━━━━━━━━━━━━\n📥 *MEDIA DOWNLOADERS:*\n• *TikTok Auto-Download:* Just paste any TikTok link!\n• *.tiktok <url>* — Watermark-free HD TikTok video.\n• *.yt <url>* or *.youtube <url>* — YouTube video & shorts.\n• *.ig <url>* — Instagram Reels & videos.\n\n━━━━━━━━━━━━━━━━━━━━━\n🛠️ *UTILITIES & TOOLS:*\n• *.s* or *.sticker* — Reply to any photo to make a sticker.\n• *.save* or *.status* — Reply to any status/media to save it.\n• *.vv* — Reply to any View-Once photo/video to unlock it.\n• *.ai <question>* — Ask Alexa Covert AI anything!\n• *.readmore <Header> | <Secret>* — Read More prank.\n\n━━━━━━━━━━━━━━━━━━━━━\n👥 *GROUP COMMANDS:*\n• *.tagall [message]* — Mention every member in group.\n• *.hidetag <message>* — Notify all members silently.\n\n━━━━━━━━━━━━━━━━━━━━━\n⚙️ *TOGGLE SETTINGS:*\n• *personalmode on* | *personalmode off*\n• *autoreply on* | *autoreply off*\n• *autoview on* | *autoview off*\n• *autolike on* | *autolike off*\n• *.statusemoji <emoji>*\n• *recoverydeleted on* | *recoverydeleted off*\n• *savedviews on* | *savedviews off*\n• *auto save contact on* | *auto save contact off*\n• *.status* | *.ping* | *.getcontacts* | *.clearcache*\n━━━━━━━━━━━━━━━━━━━━━`;
    }

    // 15. .play <song> (Full Song Music Downloader)
    else if (cmd.startsWith('.play ') || cmd.startsWith('play ') || cmd.startsWith('.music ') || cmd.startsWith('music ') || cmd.startsWith('.song ') || cmd.startsWith('song ')) {
        const query = raw.replace(/^(\.play|play|\.music|music|\.song|song)\s+/i, '').trim();
        if (!query) {
            replyText = `🎵 *How to Play Full Music:*\nType *.play <song or artist name>*\nExample: *.play Burna Boy City Boys* or *.play KiDi Touch It*\n_Downloads the 100% complete full-duration song directly to your WhatsApp!_`;
        } else {
            try {
                await sock.sendPresenceUpdate('recording', from);
                await sock.sendMessage(from, { text: `🔍 *Apex Music searching full song:* "${query}"...\n⏳ _Fetching complete audio track (full duration)..._` }, { quoted: msg });
                
                const song = await downloadFullSong(query);
                if (!song) {
                    replyText = `❌ Could not find full song for "${query}". Try searching with both artist name and song title (e.g. *.play Burna Boy City Boys*).`;
                } else {
                    const mins = Math.floor(song.durationSec / 60);
                    const secs = String(song.durationSec % 60).padStart(2, '0');
                    const sizeMb = (song.audioBuffer.length / (1024 * 1024)).toFixed(2);

                    let coverBuf = null;
                    if (song.coverUrl) {
                        try { coverBuf = await fetchBuffer(song.coverUrl, { timeout: 15000 }); } catch (e) {}
                    }

                    const caption = `🎶 *${song.title}*\n👤 *Artist:* ${song.artist}\n⏱️ *Duration:* ${mins}:${secs} (Full Song)\n📦 *Size:* ${sizeMb} MB\n━━━━━━━━━━━━━━━━━━━━━\n⚡ *Apex Music · 100% Full Duration*`;

                    if (coverBuf) {
                        await sock.sendMessage(from, { image: coverBuf, caption }, { quoted: msg });
                    }

                    await sock.sendMessage(from, {
                        audio: song.audioBuffer,
                        mimetype: 'audio/mpeg',
                        fileName: song.fileName,
                        ptt: false
                    }, { quoted: msg });
                    return true;
                }
            } catch (playErr) {
                console.error('[Music Play Error]:', playErr.message);
                replyText = `❌ Failed to download full music: ${playErr.message}`;
            }
        }
    }

    // 16. .lyrics <song>
    else if (cmd.startsWith('.lyrics ') || cmd.startsWith('lyrics ')) {
        const query = raw.replace(/^(\.lyrics|lyrics)\s+/i, '').trim();
        if (!query) {
            replyText = `📜 *How to get Lyrics:*\nType *.lyrics <song name>*\nExample: *.lyrics Coldplay Yellow*`;
        } else {
            try {
                await sock.sendMessage(from, { text: `🔎 *Fetching lyrics for:* "${query}"...` }, { quoted: msg });
                let lyrics = null;
                let songTitle = query;
                let artistName = '';

                // First try Deezer to get exact artist and title
                try {
                    const dz = await fetchJson(`https://api.deezer.com/search?q=${encodeURIComponent(query)}&limit=1`);
                    if (dz?.data?.[0]) {
                        songTitle = dz.data[0].title;
                        artistName = dz.data[0].artist?.name || '';
                        const ovhRes = await fetchJson(`https://api.lyrics.ovh/v1/${encodeURIComponent(artistName)}/${encodeURIComponent(songTitle)}`);
                        if (ovhRes?.lyrics) lyrics = ovhRes.lyrics;
                    }
                } catch (e) {}

                if (!lyrics) {
                    const ovhDirect = await fetchJson(`https://api.lyrics.ovh/v1/${encodeURIComponent(query)}/${encodeURIComponent(query)}`);
                    if (ovhDirect?.lyrics) lyrics = ovhDirect.lyrics;
                }

                if (lyrics) {
                    replyText = `📜 *LYRICS — ${songTitle.toUpperCase()}* ${artistName ? `(${artistName})` : ''}\n━━━━━━━━━━━━━━━━━━━━━\n\n${lyrics.trim()}\n\n━━━━━━━━━━━━━━━━━━━━━\n⚡ *Powered by Alexa Covert*`;
                } else {
                    replyText = `❌ Could not find lyrics for "${query}". Try searching with both artist and song name (e.g. *.lyrics Adele Hello*).`;
                }
            } catch (err) {
                replyText = `❌ Error retrieving lyrics: ${err.message}`;
            }
        }
    }

    // 17. .tiktok <url> (TikTok Downloader)
    else if (cmd.startsWith('.tiktok ') || cmd.startsWith('tiktok ') || cmd.startsWith('.tt ') || cmd.startsWith('tt ')) {
        const urlMatch = raw.match(/https?:\/\/[^\s]+/i);
        let url = urlMatch ? urlMatch[0] : null;
        if (!url) {
            replyText = `📥 *TikTok Downloader*\nPlease provide a valid TikTok link!\nExample: *.tiktok https://vt.tiktok.com/...*`;
        } else {
            try {
                await sock.sendMessage(from, { text: `⏳ *Alexa Covert downloading TikTok video...*` }, { quoted: msg });
                
                // Expand shortened URLs like vt.tiktok.com or vm.tiktok.com
                if (url.includes('vt.tiktok.com') || url.includes('vm.tiktok.com')) {
                    try {
                        const expanded = await resolveRedirectUrl(url);
                        if (expanded) url = expanded;
                    } catch (e) {}
                }

                // Query TikWM API
                let tikRes = await fetchJson(`https://www.tikwm.com/api/?url=${encodeURIComponent(url)}`);
                if ((!tikRes || tikRes.code !== 0) && url.includes('tiktok.com')) {
                    // Try with non-www tikwm
                    try {
                        tikRes = await fetchJson(`https://tikwm.com/api/?url=${encodeURIComponent(url)}`);
                    } catch (e) {}
                }

                if (tikRes && tikRes.code === 0 && tikRes.data) {
                    const data = tikRes.data;
                    const videoUrl = data.play || data.wmplay || data.hdplay;
                    const caption = `🎬 *TikTok Video Downloaded*\n━━━━━━━━━━━━━━━━━━━━━\n📝 *Title:* ${data.title || 'TikTok Video'}\n👤 *Author:* ${data.author?.nickname || 'Unknown'} (@${data.author?.unique_id || ''})\n❤️ *Likes:* ${data.digg_count || 0} | 💬 *Comments:* ${data.comment_count || 0}\n━━━━━━━━━━━━━━━━━━━━━\n⚡ *Alexa Covert Downloader*`;

                    if (videoUrl) {
                        const videoBuf = await fetchBuffer(videoUrl, { timeout: 60000 });
                        if (videoBuf && videoBuf.length > 1000) {
                            await sock.sendMessage(from, { video: videoBuf, caption }, { quoted: msg });
                            return true;
                        }
                    }
                }
                replyText = `❌ Could not extract TikTok video. Please ensure the video is public and the link is correct.`;
            } catch (e) {
                console.error('[TikTok Error]:', e.message);
                replyText = `❌ TikTok download failed: ${e.message}`;
            }
        }
    }

    // 18. .yt / .youtube <url> (YouTube Downloader)
    else if (cmd.startsWith('.yt ') || cmd.startsWith('yt ') || cmd.startsWith('.youtube ') || cmd.startsWith('youtube ')) {
        const urlMatch = raw.match(/https?:\/\/[^\s]+/i);
        const url = urlMatch ? urlMatch[0] : null;
        if (!url) {
            replyText = `📥 *YouTube Downloader*\nPlease provide a valid YouTube link!\nExample: *.yt https://youtu.be/...*`;
        } else {
            try {
                await sock.sendMessage(from, { text: `⏳ *Alexa Covert downloading YouTube video...*` }, { quoted: msg });
                const idMatch = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/))([\w\-]{11})/i);
                const videoId = idMatch ? idMatch[1] : null;

                if (!videoId) {
                    replyText = `❌ Invalid YouTube URL. Could not extract video ID.`;
                } else {
                    const pipedRes = await fetchJson(`https://api.piped.private.coffee/streams/${videoId}`, { timeout: 25000 });
                    if (pipedRes && pipedRes.videoStreams && pipedRes.videoStreams.length > 0) {
                        const stream = pipedRes.videoStreams.find(s => s.format === 'MPEG_4' && !s.videoOnly) || pipedRes.videoStreams[0];
                        if (stream?.url) {
                            const videoBuf = await fetchBuffer(stream.url, { timeout: 60000 });
                            if (videoBuf && videoBuf.length > 1000) {
                                const caption = `🎬 *${pipedRes.title || 'YouTube Video'}*\n👤 *Uploader:* ${pipedRes.uploader || 'YouTube'}\n⚡ *Alexa Covert Downloader*`;
                                await sock.sendMessage(from, { video: videoBuf, caption }, { quoted: msg });
                                return true;
                            }
                        }
                    }
                    replyText = `❌ Video streams temporarily unavailable. You can watch online at: ${url}`;
                }
            } catch (ytErr) {
                console.error('[YouTube Error]:', ytErr.message);
                replyText = `❌ YouTube download failed: ${ytErr.message}`;
            }
        }
    }

    // 18b. .ig / .instagram <url> (Instagram Downloader)
    else if (cmd.startsWith('.ig ') || cmd.startsWith('ig ') || cmd.startsWith('.instagram ') || cmd.startsWith('instagram ')) {
        const urlMatch = raw.match(/https?:\/\/[^\s]+/i);
        let url = urlMatch ? urlMatch[0] : null;
        if (!url) {
            replyText = `📥 *Instagram Downloader*\nPlease provide a valid Instagram link!\nExample: *.ig https://www.instagram.com/reel/...*`;
        } else {
            try {
                await sock.sendMessage(from, { text: `⏳ *Alexa Covert downloading Instagram media...*` }, { quoted: msg });
                
                // Instagram public oembed fallback / info
                replyText = `📱 *Instagram Media*\nTo download this post/reel, open the link directly:\n🔗 ${url}\n\n⚡ *Alexa Covert Downloader*`;
            } catch (igErr) {
                replyText = `❌ Instagram download failed: ${igErr.message}`;
            }
        }
    }

    // 19. .tts <text> (Text-to-Speech)
    else if (cmd.startsWith('.tts ') || cmd.startsWith('tts ')) {
        const textToSpeak = raw.replace(/^(\.tts|tts)\s+/i, '').trim();
        if (!textToSpeak) {
            replyText = `🎙️ *Text to Speech*\nUsage: *.tts <your message>*\nExample: *.tts Hello everyone, welcome to Alexa Covert!*`;
        } else {
            try {
                const ttsUrl = `https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&tl=en&q=${encodeURIComponent(textToSpeak.slice(0, 200))}`;
                const audioBuf = await fetchBuffer(ttsUrl);
                await sock.sendMessage(from, {
                    audio: audioBuf,
                    mimetype: 'audio/mp4',
                    ptt: true
                }, { quoted: msg });
                return true;
            } catch (ttsErr) {
                replyText = `❌ TTS failed: ${ttsErr.message}`;
            }
        }
    }

    // 20. .ai / .alexa / @Apex_Assistant260 <prompt> (AI Assistant)
    else if (cmd.startsWith('.ai ') || cmd.startsWith('ai ') || cmd.startsWith('@apex_assistant260 ') || cmd.startsWith('apex_assistant260 ') || (cmd.startsWith('.alexa ') && !cmd.endsWith('on') && !cmd.endsWith('off'))) {
        const prompt = raw.replace(/^(\.ai|ai|\.alexa|alexa|@apex_assistant260|apex_assistant260)\s+/i, '').trim();
        if (!prompt) {
            replyText = `🤖 *Apex AI Assistant* (@Apex_Assistant260)\nAsk me anything or request songs!\nExample: *@Apex_Assistant260 play Burna Boy City Boys* or *.ai What are the current MTN data prices?*`;
        } else if (/^(?:play|music|song)\s+(.+)$/i.test(prompt)) {
            const musicQuery = prompt.replace(/^(?:play|music|song)\s+/i, '').trim();
            try {
                await sock.sendPresenceUpdate('recording', from);
                await sock.sendMessage(from, { text: `🎵 *Apex AI Music:* Searching full song "${musicQuery}"...\n⏳ _Fetching complete audio track (full duration)..._` }, { quoted: msg });
                const song = await downloadFullSong(musicQuery);
                if (song) {
                    const mins = Math.floor(song.durationSec / 60);
                    const secs = String(song.durationSec % 60).padStart(2, '0');
                    const sizeMb = (song.audioBuffer.length / (1024 * 1024)).toFixed(2);
                    let coverBuf = null;
                    if (song.coverUrl) {
                        try { coverBuf = await fetchBuffer(song.coverUrl, { timeout: 15000 }); } catch (e) {}
                    }
                    const caption = `🎶 *${song.title}*\n👤 *Artist:* ${song.artist}\n⏱️ *Duration:* ${mins}:${secs} (Full Song)\n📦 *Size:* ${sizeMb} MB\n━━━━━━━━━━━━━━━━━━━━━\n⚡ *Apex AI Music Player · 100% Full Duration*`;
                    if (coverBuf) {
                        await sock.sendMessage(from, { image: coverBuf, caption }, { quoted: msg });
                    }
                    await sock.sendMessage(from, {
                        audio: song.audioBuffer,
                        mimetype: 'audio/mpeg',
                        fileName: song.fileName,
                        ptt: false
                    }, { quoted: msg });
                    return true;
                } else {
                    replyText = `❌ Could not find full song for "${musicQuery}". Try adding the artist name.`;
                }
            } catch (mErr) {
                replyText = `❌ Music error: ${mErr.message}`;
            }
        } else {
            try {
                await sock.sendPresenceUpdate('composing', from);
                const bridgeRes = await processMessageViaBridge(senderPhone, '.ai ' + prompt, pushName);
                if (bridgeRes && bridgeRes.reply) {
                    replyText = bridgeRes.reply;
                } else {
                    replyText = `🤖 *Apex AI Assistant* (@Apex_Assistant260)\nI am here to help! Ask me anything, or visit https://apexprime.club for our services.`;
                }
            } catch (aiErr) {
                replyText = `❌ AI response error: ${aiErr.message}`;
            }
        }
    }

    // 21. .sticker / .s (Sticker Maker)
    else if (cmd === '.s' || cmd === 's' || cmd === '.sticker' || cmd === 'sticker') {
        const quoted = msg.message?.extendedTextMessage?.contextInfo?.quotedMessage;
        const targetImg = quoted?.imageMessage || msg.message?.imageMessage;
        if (targetImg) {
            try {
                const imgBuf = await downloadMediaBuffer(targetImg, 'image');
                if (imgBuf) {
                    await sock.sendMessage(from, { sticker: imgBuf }, { quoted: msg });
                    return true;
                }
            } catch (sErr) {
                replyText = `❌ Could not convert image to sticker: ${sErr.message}`;
            }
        } else {
            replyText = `🖼️ *Sticker Maker:*\nReply to any photo with *.s* or *.sticker* to instantly turn it into a WhatsApp sticker!`;
        }
    }

    // 22. .save / .status (Status & Media Saver)
    else if (cmd === '.save' || cmd === 'save') {
        const quoted = msg.message?.extendedTextMessage?.contextInfo?.quotedMessage;
        if (quoted) {
            try {
                if (quoted.imageMessage) {
                    const buf = await downloadMediaBuffer(quoted.imageMessage, 'image');
                    await sock.sendMessage(from, { image: buf, caption: `💾 *Saved by Alexa Covert*` }, { quoted: msg });
                    return true;
                } else if (quoted.videoMessage) {
                    const buf = await downloadMediaBuffer(quoted.videoMessage, 'video');
                    await sock.sendMessage(from, { video: buf, caption: `💾 *Saved by Alexa Covert*` }, { quoted: msg });
                    return true;
                } else if (quoted.audioMessage) {
                    const buf = await downloadMediaBuffer(quoted.audioMessage, 'audio');
                    await sock.sendMessage(from, { audio: buf, mimetype: 'audio/mp4', ptt: quoted.audioMessage.ptt }, { quoted: msg });
                    return true;
                }
            } catch (e) {
                replyText = `❌ Could not save media: ${e.message}`;
            }
        } else {
            replyText = `💾 *Status & Media Saver:*\nReply directly to any image, video, or audio with *.save* to download and keep it!`;
        }
    }

    if (replyText) {
        const targetJid = from === 'status@broadcast' ? getOwnerJid(sock) : from;
        if (targetJid) {
            try {
                await sock.sendMessage(targetJid, { text: replyText }, { quoted: msg });
                console.log(`[Owner Command] Executed "${raw}" from ${senderPhone}`);
                return true;
            } catch (sendErr) {
                console.error('[Owner Command Error]:', sendErr.message);
            }
        }
    }

    return false;
}

/**
 * Main function to start the WhatsApp bot socket
 */
async function startBot() {
    if (!makeWASocket) {
        await loadBaileys();
    }

    console.log('\n=========================================');
    console.log('   APEX PRIME WHATSAPP QR CODE BOT       ');
    console.log('=========================================\n');

    // Ensure auth directory exists
    if (!fs.existsSync(AUTH_DIR)) {
        fs.mkdirSync(AUTH_DIR, { recursive: true });
    }

    // Clean up any previously existing socket before creating a new one
    if (currentBaileysSocket) {
        try {
            currentBaileysSocket.ev.removeAllListeners();
            currentBaileysSocket.ws?.terminate();
        } catch (e) {}
        currentBaileysSocket = null;
    }

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version, isLatest } = await fetchLatestBaileysVersion();
    console.log(`[WhatsApp] Using Baileys version ${version.join('.')} (isLatest: ${isLatest})`);

    const sock = makeWASocket({
        version,
        auth: state,
        logger: pino({ level: 'silent' }),
        printQRInTerminal: false,
        browser: Browsers ? Browsers.ubuntu('Chrome') : ['Ubuntu', 'Chrome', '22.04.4'],
        syncFullHistory: false,
        connectTimeoutMs: 60000,
        defaultQueryTimeoutMs: 60000,
        keepAliveIntervalMs: 25000,
        retryRequestDelayMs: 2000
    });
    currentBaileysSocket = sock;

    // Save session credentials whenever updated
    sock.ev.on('creds.update', saveCreds);

    // Handle connection status updates
    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            // If a pairing code was requested (event-driven flow), intercept the QR event
            // and call requestPairingCode() HERE — this is the correct Baileys timing.
            if (pendingPairingPhone && pendingPairingResolve) {
                const phoneForPairing = pendingPairingPhone;
                const resolveCode = pendingPairingResolve;
                const rejectCode = pendingPairingReject;
                // Clear pending so we don't call again on subsequent QR refreshes
                pendingPairingPhone = null;
                pendingPairingResolve = null;
                pendingPairingReject = null;

                console.log(`[Pairing Code] QR event intercepted. Requesting pairing code for +${phoneForPairing}...`);
                sock.requestPairingCode(phoneForPairing).then(code => {
                    let formattedCode = String(code).trim();
                    if (formattedCode.length === 8 && !formattedCode.includes('-')) {
                        formattedCode = formattedCode.slice(0, 4) + '-' + formattedCode.slice(4);
                    }
                    activePairingCode = formattedCode;
                    activePairingPhone = phoneForPairing;
                    pairingTimestamp = Date.now();

                    try {
                        fs.writeFileSync(PAIRING_STATE_FILE, JSON.stringify({
                            success: true,
                            pairing_code: formattedCode,
                            phone: phoneForPairing,
                            timestamp: pairingTimestamp,
                            expires_at: pairingTimestamp + (120 * 1000)
                        }, null, 2));
                    } catch (e) {}

                    console.log(`[Pairing Code] ✅ Successfully generated for +${phoneForPairing}: ${formattedCode}`);
                    resolveCode(formattedCode);
                }).catch(err => {
                    console.error(`[Pairing Code] ❌ Error for +${phoneForPairing}:`, err.message);
                    rejectCode(err);
                });
                return; // Don't show QR when pairing code is being used
            }

            // Normal QR flow (no pairing code requested)
            if (!activePairingCode) {
                botStatus = 'scan_qr';
                console.log('\n-----------------------------------------------------');
                console.log(' SCAN THE QR CODE BELOW WITH YOUR WHATSAPP TO CONNECT:');
                console.log(' 1. Open WhatsApp on your phone');
                console.log(' 2. Go to Linked Devices > Link a Device');
                console.log(' 3. Point your camera at this QR code:');
                console.log('-----------------------------------------------------\n');
                try {
                    qrcode.generate(qr, { small: true });
                } catch (e) {}

                try {
                    QRCodeImage.toFile(path.resolve(__dirname, 'qr.png'), qr, { scale: 8 });
                    QRCodeImage.toDataURL(qr, (err, url) => {
                        if (!err && url) currentQrDataUrl = url;
                    });
                } catch (err) {}
            }
        }

        if (connection === 'close') {
            botStatus = 'reconnecting';
            const statusCode = (lastDisconnect?.error)?.output?.statusCode;
            const isRegistered = Boolean(state.creds?.registered);

            console.log(`[WhatsApp] Connection closed. Status code: ${statusCode}. registered: ${isRegistered}`);

            // Clean up the closed socket completely
            try {
                sock.ev.removeAllListeners();
                sock.ws?.terminate();
            } catch (e) {}
            if (currentBaileysSocket === sock) {
                currentBaileysSocket = null;
            }

            // Status code 515 = DisconnectReason.restartRequired (standard after pairing handshake)
            const isRestartRequired = (statusCode === DisconnectReason.restartRequired || statusCode === 515);
            const isActualLogout = isRegistered && (statusCode === DisconnectReason.loggedOut) && !isRestartRequired;
            const isStalePairing = !isRegistered && (statusCode === 401 || statusCode === 405);

            if (isActualLogout || isStalePairing) {
                console.log('[WhatsApp] Clearing auth directory to allow fresh connection...');
                try {
                    fs.rmSync(AUTH_DIR, { recursive: true, force: true });
                } catch (e) {}
                activePairingCode = null;
                activePairingPhone = null;
                activeConnectionMethod = 'qr_code';
                try {
                    if (fs.existsSync(PAIRING_STATE_FILE)) fs.unlinkSync(PAIRING_STATE_FILE);
                } catch (e) {}
                try {
                    if (fs.existsSync(SESSION_INFO_FILE)) fs.unlinkSync(SESSION_INFO_FILE);
                } catch (e) {}
                setTimeout(() => {
                    startBot().catch(e => console.error('[Restart Error]:', e.message));
                }, 2000);
            } else {
                const retryDelay = isRestartRequired ? 1500 : (statusCode === 440 ? 5000 : 3000);
                setTimeout(() => {
                    startBot().catch(e => console.error('[Restart Error]:', e.message));
                }, retryDelay);
            }
        } else if (connection === 'open') {
            botStatus = 'connected';
            currentQrDataUrl = null;
            activePairingCode = null;
            connectedPhone = sock.user?.id ? sock.user.id.split(':')[0] : 'Online';

            // Detect whether this session was linked via Phone Number (pairing code) or QR
            const existingInfo = loadSessionInfo();
            const isPersonal = (activeConnectionMethod === 'phone_number') ||
                               (existingInfo?.linked_via === 'phone_number') ||
                               Boolean(activePairingPhone);
            const connectionMethod = isPersonal ? 'phone_number' : 'qr_code';
            activeConnectionMethod = connectionMethod;

            // Persist session state
            saveSessionInfo({
                linked_via: connectionMethod,
                phone: connectedPhone,
                personal_bot_mode: isPersonal,
                status: 'connected',
                connected_at: new Date().toISOString()
            });

            // Write connected state to pairing_state.json for web dashboard
            try {
                fs.writeFileSync(PAIRING_STATE_FILE, JSON.stringify({
                    success: true,
                    status: 'connected',
                    linked_via: connectionMethod,
                    personal_bot_mode: isPersonal,
                    phone: connectedPhone,
                    connected_at: new Date().toISOString()
                }, null, 2));
            } catch (e) {}

            console.log('\n======================================================');
            console.log(' [SUCCESS] Alexa Covert Bot is CONNECTED & ONLINE!     ');
            console.log(` Connected to Account: +${connectedPhone}               `);
            console.log(` Mode: ${isPersonal ? '🔒 PERSONAL BOT (Auto-replies to contacts SILENCED)' : '🏪 BUSINESS SQR BOT (Store auto-reply ACTIVE)'}`);
            console.log('======================================================\n');
        }
    });

    // Handle incoming messages & status events
    sock.ev.on('messages.upsert', async ({ messages, type }) => {
        if (type !== 'notify') return;

        const config = loadCommandsConfig();
        const ub = config.userbot_settings || {};

        for (const msg of messages) {
            const from = msg.key.remoteJid || '';
            const isFromMe = Boolean(msg.key.fromMe);
            const senderPhone = (msg.key.participant || from).replace(/@.+/, '');
            const pushName = msg.pushName || 'Customer';

            // 1. Handle Status Broadcast Updates (WhatsApp Stories)
            if (from === 'status@broadcast') {
                const participant = msg.key.participant || msg.participant;

                // Auto-View Status
                if (ub.autoview) {
                    try {
                        await sock.readMessages([msg.key]);
                        console.log(`[Auto-View] Viewed status from: ${participant}`);
                    } catch (e) {
                        console.error('[Auto-View Error]:', e.message);
                    }
                }

                // Auto-Like Status
                if (ub.autolike) {
                    try {
                        const emoji = ub.autolike_emoji || '❤️';
                        await sock.sendMessage('status@broadcast', {
                            react: {
                                text: emoji,
                                key: msg.key
                            }
                        }, {
                            statusJidList: participant ? [participant] : []
                        });
                        console.log(`[Auto-Like] Reacted ${emoji} to status from: ${participant}`);
                    } catch (e) {
                        console.error('[Auto-Like Error]:', e.message);
                    }
                }
                continue;
            }

            // 2. Handle Anti-Delete: Message Revocation / Deletion
            const protoMsg = msg.message?.protocolMessage;
            if (protoMsg && (protoMsg.type === 0 || protoMsg.type === proto?.Message?.ProtocolMessage?.Type?.REVOKE)) {
                const revokedId = protoMsg.key?.id;
                if (revokedId && messageStore.has(revokedId) && ub.recoverydeleted) {
                    const cached = messageStore.get(revokedId);
                    const ownerJid = getOwnerJid(sock);
                    if (ownerJid) {
                        const sentTime = new Date(cached.timestamp * 1000).toLocaleTimeString();
                        const deletedTime = new Date().toLocaleTimeString();

                        if (cached.mediaBuffer && cached.mediaType) {
                            await sock.sendMessage(ownerJid, {
                                [cached.mediaType]: cached.mediaBuffer,
                                caption: `🗑️ *[Deleted Media Recovered]*\n━━━━━━━━━━━━━━━━━━━━━\n👤 *From:* ${cached.pushName} (${cached.senderPhone})\n📍 *Chat:* ${cached.from}\n🕒 *Sent At:* ${sentTime}\n⏰ *Deleted At:* ${deletedTime}\n📝 *Original Caption:* ${cached.text || 'None'}\n━━━━━━━━━━━━━━━━━━━━━`
                            });
                        } else {
                            const report = `🗑️ *[Deleted Message Recovered]*\n━━━━━━━━━━━━━━━━━━━━━\n👤 *From:* ${cached.pushName} (${cached.senderPhone})\n📍 *Chat:* ${cached.from}\n🕒 *Sent At:* ${sentTime}\n⏰ *Deleted At:* ${deletedTime}\n━━━━━━━━━━━━━━━━━━━━━\n💬 *Deleted Content:*\n"${cached.text || '[No text / Media]'}"\n━━━━━━━━━━━━━━━━━━━━━`;
                            await sock.sendMessage(ownerJid, { text: report });
                        }
                        console.log(`[Anti-Delete] Recovered message ${revokedId} from ${cached.senderPhone}`);
                    }
                }
                continue;
            }

            // Handle WhatsApp Group AI mentions or commands (.ai, @ai, or bot mention)
            if (from.endsWith('@g.us')) {
                const aiCfg = config.ai_assistant || {};
                const botJid = sock.user?.id ? sock.user.id.split(':')[0] + '@s.whatsapp.net' : '';
                const mentions = msg.message?.extendedTextMessage?.contextInfo?.mentionedJid || [];
                const isMentioned = botJid && mentions.includes(botJid);

                const groupText = msg.message?.conversation
                    || msg.message?.extendedTextMessage?.text
                    || '';
                const trimmedGroup = groupText.trim();
                const isAiCommand = /^(?:\.ai|\/ai|ai:|@ai|@apex_assistant260|apex_assistant260)\b/i.test(trimmedGroup) || isMentioned;

                if (aiCfg.allow_groups !== false && isAiCommand && trimmedGroup) {
                    const prompt = trimmedGroup.replace(/^(?:\.ai|\/ai|ai:|@ai|@apex_assistant260|apex_assistant260)\s*/i, '').replace(new RegExp(`@${botJid.replace(/@.+/, '')}`, 'g'), '').trim();
                    if (prompt) {
                        if (/^(?:play|music|song)\s+(.+)$/i.test(prompt)) {
                            const musicQuery = prompt.replace(/^(?:play|music|song)\s+/i, '').trim();
                            try {
                                await sock.sendPresenceUpdate('recording', from);
                                await sock.sendMessage(from, { text: `🎵 *Apex AI Music:* Searching full song "${musicQuery}"...\n⏳ _Fetching complete audio track (full duration)..._` }, { quoted: msg });
                                const song = await downloadFullSong(musicQuery);
                                if (song) {
                                    const mins = Math.floor(song.durationSec / 60);
                                    const secs = String(song.durationSec % 60).padStart(2, '0');
                                    const sizeMb = (song.audioBuffer.length / (1024 * 1024)).toFixed(2);
                                    let coverBuf = null;
                                    if (song.coverUrl) {
                                        try { coverBuf = await fetchBuffer(song.coverUrl, { timeout: 15000 }); } catch (e) {}
                                    }
                                    const caption = `🎶 *${song.title}*\n👤 *Artist:* ${song.artist}\n⏱️ *Duration:* ${mins}:${secs} (Full Song)\n📦 *Size:* ${sizeMb} MB\n━━━━━━━━━━━━━━━━━━━━━\n⚡ *Apex AI Music Player · 100% Full Duration*`;
                                    if (coverBuf) {
                                        await sock.sendMessage(from, { image: coverBuf, caption }, { quoted: msg });
                                    }
                                    await sock.sendMessage(from, {
                                        audio: song.audioBuffer,
                                        mimetype: 'audio/mpeg',
                                        fileName: song.fileName,
                                        ptt: false
                                    }, { quoted: msg });
                                } else {
                                    await sock.sendMessage(from, { text: `❌ Could not find full song for "${musicQuery}". Try adding the artist name.` }, { quoted: msg });
                                }
                            } catch (mErr) {
                                console.error('[Group Music Error]:', mErr.message);
                            }
                        } else {
                            try {
                                await sock.sendPresenceUpdate('composing', from);
                                const bridgeRes = await processMessageViaBridge(senderPhone, '.ai ' + prompt, pushName);
                                const reply = bridgeRes ? (typeof bridgeRes === 'string' ? bridgeRes : bridgeRes.reply) : null;
                                if (reply) {
                                    await sock.sendMessage(from, { text: reply }, { quoted: msg });
                                }
                            } catch (e) {
                                console.error('[Group AI Error]:', e.message);
                            }
                        }
                    }
                }
                continue;
            }

            // Strictly ignore Community Newsletters & non-group broadcasts
            if (from.endsWith('@newsletter') || from.includes('-')) {
                continue;
            }

            // 3. Handle View-Once Messages (Saved Views)
            const vo = extractViewOnce(msg);
            if (vo && ub.savedviews && !isFromMe) {
                try {
                    const buffer = await downloadMediaBuffer(vo.media, vo.type);
                    const ownerJid = getOwnerJid(sock);
                    const dateStr = new Date().toLocaleString();
                    const originalCaption = vo.media.caption || 'None';

                    if (ownerJid && buffer) {
                        if (vo.type === 'image') {
                            await sock.sendMessage(ownerJid, {
                                image: buffer,
                                caption: `📸 *[Anti View-Once Photo Saved]*\n━━━━━━━━━━━━━━━━━━━━━\n👤 *From:* ${pushName} (${senderPhone})\n📅 *Time:* ${dateStr}\n📝 *Original Caption:* ${originalCaption}\n━━━━━━━━━━━━━━━━━━━━━`
                            });
                        } else if (vo.type === 'video') {
                            await sock.sendMessage(ownerJid, {
                                video: buffer,
                                caption: `🎥 *[Anti View-Once Video Saved]*\n━━━━━━━━━━━━━━━━━━━━━\n👤 *From:* ${pushName} (${senderPhone})\n📅 *Time:* ${dateStr}\n📝 *Original Caption:* ${originalCaption}\n━━━━━━━━━━━━━━━━━━━━━`
                            });
                        } else if (vo.type === 'audio') {
                            await sock.sendMessage(ownerJid, {
                                audio: buffer,
                                mimetype: 'audio/mp4',
                                ptt: true
                            });
                            await sock.sendMessage(ownerJid, {
                                text: `🎵 *[Anti View-Once Voice Saved]*\n👤 *From:* ${pushName} (${senderPhone})\n📅 *Time:* ${dateStr}`
                            });
                        }
                        console.log(`[Anti View-Once] Saved and forwarded ${vo.type} from: ${senderPhone}`);
                    }
                } catch (voErr) {
                    console.error('[Anti View-Once Error]:', voErr.message);
                }
            }

            // 4. Cache incoming message for anti-delete tracking
            if (ub.recoverydeleted && !isFromMe) {
                cacheMessage(msg).catch(() => {});
            }

            // Extract message text across different message types
            const messageContent = msg.message;
            if (!messageContent) continue;

            const text = messageContent.conversation
                || messageContent.extendedTextMessage?.text
                || messageContent.buttonsResponseMessage?.selectedButtonId
                || messageContent.listResponseMessage?.singleSelectReply?.selectedRowId
                || messageContent.templateButtonReplyMessage?.selectedId
                || '';

            // 5. Handle Personal DM / Owner Commands (.commands, autoview on, etc.)
            const isAdmin = isAdminOrOwner(senderPhone, from, isFromMe, config);
            if (isAdmin && text.trim()) {
                const handled = await handleOwnerCommands(sock, msg, from, text, senderPhone, pushName, isFromMe);
                if (handled) {
                    continue; // Done handling owner command
                }
            }

            // If message is from the bot itself and not an owner command, ignore
            if (isFromMe) continue;

            // 6. Auto-Save Contact for new incoming chat
            if (ub.autosavecontact) {
                saveNewContact(senderPhone, pushName);
            }

            // 7. Check if sender is in ignored_numbers list (bot turned off for this number)
            const ignoredNumbers = config.ignored_numbers || [];
            const cleanFrom = senderPhone.replace(/\D/g, '');
            const isIgnored = ignoredNumbers.some(ign => {
                const cleanIgn = String(ign).replace(/\D/g, '');
                if (!cleanIgn) return false;
                return cleanFrom === cleanIgn || (cleanFrom.length >= 9 && cleanIgn.length >= 9 && cleanFrom.slice(-9) === cleanIgn.slice(-9));
            });

            if (isIgnored) {
                console.log(`[Bot Muted] Auto-reply disabled for: ${senderPhone} (${pushName})`);
                continue;
            }

            if (!text.trim()) continue;

            console.log(`[Incoming Message] From: ${pushName} (${senderPhone}) | Body: "${text}"`);

            // 8. Auto-Detect TikTok URL in any message (Alexa Covert Video Downloader)
            const tikMatch = text.match(/https?:\/\/(?:www\.|vm\.|vt\.)?tiktok\.com\/[^\s]+/i);
            if (tikMatch && tikMatch[0]) {
                try {
                    let tikUrl = tikMatch[0];
                    await sock.sendMessage(from, { text: `⏳ *Alexa Covert downloading TikTok video...*` }, { quoted: msg });
                    if (tikUrl.includes('vt.tiktok.com') || tikUrl.includes('vm.tiktok.com')) {
                        try {
                            const exp = await resolveRedirectUrl(tikUrl);
                            if (exp) tikUrl = exp;
                        } catch (e) {}
                    }

                    let tikRes = await fetchJson(`https://www.tikwm.com/api/?url=${encodeURIComponent(tikUrl)}`);
                    if ((!tikRes || tikRes.code !== 0) && tikUrl.includes('tiktok.com')) {
                        try {
                            tikRes = await fetchJson(`https://tikwm.com/api/?url=${encodeURIComponent(tikUrl)}`);
                        } catch (e) {}
                    }

                    if (tikRes && tikRes.code === 0 && tikRes.data) {
                        const data = tikRes.data;
                        const videoUrl = data.play || data.wmplay || data.hdplay;
                        const caption = `🎬 *TikTok Video Downloaded*\n━━━━━━━━━━━━━━━━━━━━━\n📝 *Title:* ${data.title || 'TikTok Video'}\n👤 *Author:* ${data.author?.nickname || 'Unknown'} (@${data.author?.unique_id || ''})\n❤️ *Likes:* ${data.digg_count || 0} | 💬 *Comments:* ${data.comment_count || 0}\n━━━━━━━━━━━━━━━━━━━━━\n⚡ *Alexa Covert Downloader*`;

                        if (videoUrl) {
                            const videoBuf = await fetchBuffer(videoUrl, { timeout: 60000 });
                            if (videoBuf && videoBuf.length > 1000) {
                                await sock.sendMessage(from, { video: videoBuf, caption }, { quoted: msg });
                                continue;
                            }
                        }
                    }
                } catch (e) {
                    console.error('[Auto TikTok Error]:', e.message);
                }
            }

            // 9. Auto-Detect YouTube / Shorts URL in any message (Alexa Covert YouTube Downloader)
            const ytMatch = text.match(/https?:\/\/(?:www\.)?(?:youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/)|youtu\.be\/)([\w\-]{11})/i);
            if (ytMatch && ytMatch[1]) {
                try {
                    const videoId = ytMatch[1];
                    await sock.sendMessage(from, { text: `⏳ *Alexa Covert downloading YouTube video...*` }, { quoted: msg });
                    const pipedRes = await fetchJson(`https://api.piped.private.coffee/streams/${videoId}`, { timeout: 25000 });
                    if (pipedRes && pipedRes.videoStreams && pipedRes.videoStreams.length > 0) {
                        const stream = pipedRes.videoStreams.find(s => s.format === 'MPEG_4' && !s.videoOnly) || pipedRes.videoStreams[0];
                        if (stream?.url) {
                            const videoBuf = await fetchBuffer(stream.url, { timeout: 60000 });
                            if (videoBuf && videoBuf.length > 1000) {
                                const caption = `🎬 *${pipedRes.title || 'YouTube Video'}*\n👤 *Uploader:* ${pipedRes.uploader || 'YouTube'}\n⚡ *Alexa Covert Downloader*`;
                                await sock.sendMessage(from, { video: videoBuf, caption }, { quoted: msg });
                                continue;
                            }
                        }
                    }
                } catch (e) {
                    console.error('[Auto YouTube Error]:', e.message);
                }
            }

            // 10. Check if user ran a personal assistant command directly (.play, .lyrics, .ai, etc.)
            const trimmedCmd = text.trim();
            if (/^(?:\.play|play|\.lyrics|lyrics|\.ai|ai|\.tts|tts|\.tiktok|tiktok|\.yt|yt|\.youtube|youtube|\.ig|ig|\.instagram|instagram|\.s|s|\.sticker|sticker|\.save|save)\b/i.test(trimmedCmd)) {
                const handledAssistant = await handleOwnerCommands(sock, msg, from, trimmedCmd, senderPhone, pushName, false);
                if (handledAssistant) continue;
            }

            // 11. Personal Bot Mode Protection (Linked with Phone Number)
            // When linked with a personal phone number, only the personal bot tools work.
            // DO NOT auto-reply to incoming messages from contacts/people!
            if (isPersonalBotSession()) {
                console.log(`[Personal Bot Mode] Message from ${pushName} (${senderPhone}) received — store auto-reply suppressed (personal account protection).`);
                continue;
            }

            // 12. Process via full PHP WhatsAppBot engine (AI, Sessions, WAEC Check, SQL DB Verify)
            try { await sock.sendPresenceUpdate('composing', from); } catch (e) {}
            const bridgeRes = await processMessageViaBridge(senderPhone, text, pushName);
            let reply = bridgeRes ? (typeof bridgeRes === 'string' ? bridgeRes : bridgeRes.reply) : null;

            // 9. Fallback to bot_commands.json if bridge returned null
            if (!reply) {
                reply = findMatchingResponse(text, pushName, senderPhone);
            }

            if (reply) {
                try {
                    await sock.sendMessage(from, { text: reply }, { quoted: msg });
                    try { await sock.sendPresenceUpdate('paused', from); } catch (e) {}
                    console.log(`[Reply Sent] -> To: ${senderPhone}`);

                    // 10. If an official WAEC Result Slip PDF document was generated, send it directly!
                    if (bridgeRes && bridgeRes.pdf_file && fs.existsSync(bridgeRes.pdf_file)) {
                        try {
                            const pdfBuf = fs.readFileSync(bridgeRes.pdf_file);
                            const candName = bridgeRes.candidate_name ? ` (${bridgeRes.candidate_name})` : '';
                            await sock.sendMessage(from, {
                                document: pdfBuf,
                                mimetype: 'application/pdf',
                                fileName: bridgeRes.pdf_name || 'Official_WAEC_Result_Slip.pdf',
                                caption: `🎓 *Official WAEC Provisional Results Slip (PDF)*${candName}\n📄 Candidate: *${bridgeRes.candidate_name || 'Candidate'}*\n🆔 Index: \`${bridgeRes.index_number || ''}\`\n━━━━━━━━━━━━━━━━━━━━━\n💡 Open or print this official PDF for school/tertiary admission verification.`
                            }, { quoted: msg });
                            console.log(`[Result Slip PDF Sent] -> To: ${senderPhone} (${bridgeRes.pdf_name})`);
                        } catch (docErr) {
                            console.error('[Error sending result PDF document]:', docErr.message);
                        }
                    }
                } catch (sendErr) {
                    console.error(`[Error] Failed to send reply to ${senderPhone}:`, sendErr.message);
                }
            }
        }
    });
}

// Built-in Web Server for cPanel / Cloud Hosting & Web QR Scanner / Alexa Covert Pairing
const PORT = process.env.PORT || 3000;
const server = http.createServer(async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        return res.end();
    }

    const urlPath = (req.url || '').split('?')[0].replace(/\/+$/, '');

    // API endpoint for health check or status
    if (urlPath.endsWith('/status') || urlPath.endsWith('/api/status')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
            status: botStatus,
            phone: connectedPhone,
            pairing_code: activePairingCode,
            pairing_phone: activePairingPhone
        }));
    }

    // Diagnostic endpoint to test live website database sync from Render
    if (urlPath.endsWith('/api/test-db') || urlPath.endsWith('/test-db')) {
        const fullUrl = new URL(req.url || '', `http://${req.headers.host || 'localhost'}`);
        const searchCode = fullUrl.searchParams.get('search') || '317';
        const testRes = await callWebsiteApi({ op: 'lookup_user', search: searchCode });
        res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
        return res.end(JSON.stringify({
            status: 'online',
            search_tested: searchCode,
            result: testRes
        }, null, 2));
    }

    // POST /api/request-pairing-code (Alexa Covert Pairing Endpoint)
    if ((urlPath.endsWith('/api/request-pairing-code') || urlPath.endsWith('/request-pairing-code')) && req.method === 'POST') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', async () => {
            try {
                const parsed = JSON.parse(body || '{}');
                const phone = parsed.phone || parsed.phoneNumber;
                if (!phone) {
                    res.writeHead(400, { 'Content-Type': 'application/json' });
                    return res.end(JSON.stringify({ success: false, message: 'Phone number is required' }));
                }

                const pairingCode = await generatePairingCode(phone);
                const respBody = JSON.stringify({
                    success: true,
                    pairing_code: pairingCode,
                    phone,
                    status: 'pairing'
                });
                res.writeHead(200, {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(respBody),
                    'Connection': 'close'
                });
                return res.end(respBody);
            } catch (err) {
                const errBody = JSON.stringify({ success: false, message: err.message });
                res.writeHead(500, {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(errBody),
                    'Connection': 'close'
                });
                return res.end(errBody);
            }
        });
        return;
    }

    // POST /api/logout (Disconnect current session)
    if ((urlPath.endsWith('/api/logout') || urlPath.endsWith('/api/disconnect')) && req.method === 'POST') {
        try {
            if (currentBaileysSocket) {
                await currentBaileysSocket.logout().catch(() => {});
            }
            try { fs.rmSync(AUTH_DIR, { recursive: true, force: true }); } catch (e) {}
            try { if (fs.existsSync(PAIRING_STATE_FILE)) fs.unlinkSync(PAIRING_STATE_FILE); } catch (e) {}
            activePairingCode = null;
            activePairingPhone = null;
            botStatus = 'scan_qr';
            connectedPhone = null;

            res.writeHead(200, { 'Content-Type': 'application/json' });
            return res.end(JSON.stringify({ success: true, message: 'Session unlinked successfully' }));
        } catch (e) {
            res.writeHead(500, { 'Content-Type': 'application/json' });
            return res.end(JSON.stringify({ success: false, message: e.message }));
        }
    }

    // Main Web Dashboard
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    if (botStatus === 'connected') {
        res.end(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Bot - 24/7 Online</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b141a; color: #e9edef; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: #111b21; border: 1px solid #202c33; border-radius: 16px; padding: 36px; max-width: 440px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .badge { display: inline-flex; align-items: center; gap: 8px; background: rgba(37, 211, 102, 0.15); color: #25d366; font-weight: 600; font-size: 14px; padding: 6px 16px; border-radius: 999px; margin-bottom: 20px; }
        .pulse { width: 10px; height: 10px; border-radius: 50%; background: #25d366; box-shadow: 0 0 10px #25d366; animation: blink 1.5s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        h1 { margin: 0 0 10px; font-size: 24px; color: #fff; }
        p { color: #8696a0; font-size: 14px; line-height: 1.6; margin: 0 0 20px; }
        .info { background: #202c33; border-radius: 10px; padding: 12px 16px; font-family: monospace; font-size: 13px; color: #00a884; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge"><span class="pulse"></span> 24/7 CLOUD ACTIVE</div>
        <h1>WhatsApp Bot Connected!</h1>
        <p>The bot is running 24/7 on the cloud server. Your laptop can be powered off or disconnected without interruption.</p>
        <div class="info">Connected Account: +${connectedPhone || 'Active'}</div>
    </div>
</body>
</html>`);
    } else if (botStatus === 'scan_qr' && currentQrDataUrl) {
        res.end(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="12">
    <title>Link WhatsApp Bot</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b141a; color: #e9edef; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: #111b21; border: 1px solid #202c33; border-radius: 16px; padding: 32px; max-width: 420px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        h2 { margin: 0 0 10px; font-size: 22px; color: #fff; }
        ol { text-align: left; font-size: 13px; color: #8696a0; line-height: 1.8; margin: 16px 0 20px 0; padding-left: 20px; }
        .qr-wrap { background: #fff; padding: 16px; border-radius: 12px; display: inline-block; margin-bottom: 15px; }
        .qr-wrap img { display: block; width: 260px; height: 260px; }
        .hint { font-size: 12px; color: #667781; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Link WhatsApp Bot</h2>
        <ol>
            <li>Open <strong>WhatsApp</strong> on your phone</li>
            <li>Tap <strong>Settings</strong> or <strong>Menu (⋮)</strong> &gt; <strong>Linked Devices</strong></li>
            <li>Tap <strong>Link a Device</strong> and point your camera here:</li>
        </ol>
        <div class="qr-wrap">
            <img src="${currentQrDataUrl}" alt="WhatsApp QR Code">
        </div>
        <div class="hint">⚡ This page automatically refreshes every 12 seconds with new QR codes.</div>
    </div>
</body>
</html>`);
    } else {
        res.end(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="5">
    <title>WhatsApp Bot Starting...</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b141a; color: #e9edef; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #111b21; border-radius: 16px; padding: 36px; text-align: center; max-width: 380px; }
        .spinner { width: 36px; height: 36px; border: 3px solid #202c33; border-top: 3px solid #00a884; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        p { color: #8696a0; font-size: 14px; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <h3>Starting WhatsApp Bot...</h3>
        <p>Connecting to servers. Page will reload in 5 seconds.</p>
    </div>
</body>
</html>`);
    }
});

server.listen(PORT, () => {
    console.log(`[Web Server] HTTP Dashboard ready on port ${PORT}`);
});

// Start bot
startBot().catch(err => {
    console.error('[Fatal Error] Failed to start bot:', err);
});
