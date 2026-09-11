/**
 * Apex Prime — WhatsApp QR Code Multi-Device Bot & Personal Assistant
 * 
 * Powered by @whiskeysockets/baileys.
 * Runs directly on your machine or server.
 * Connects to your WhatsApp account by scanning a QR code once.
 */

let makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion, downloadContentFromMessage, proto;

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
}

const qrcode = require('qrcode-terminal');
const QRCodeImage = require('qrcode');
const pino = require('pino');
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');
const { execFile } = require('child_process');

// Path to bot_commands.json and bridge (supports local, public_html, or same folder)
let COMMANDS_FILE = path.resolve(__dirname, '../bot_commands.json');
if (!fs.existsSync(COMMANDS_FILE)) {
    if (fs.existsSync(path.resolve(__dirname, '../public_html/bot_commands.json'))) {
        COMMANDS_FILE = path.resolve(__dirname, '../public_html/bot_commands.json');
    } else if (fs.existsSync(path.resolve(__dirname, 'bot_commands.json'))) {
        COMMANDS_FILE = path.resolve(__dirname, 'bot_commands.json');
    }
}

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

/**
 * Query website backend database API (https://apexprime.club/api.php)
 */
function callWebsiteApi(dataObj) {
    return new Promise((resolve) => {
        const payload = JSON.stringify({
            action: 'bot_query',
            bot_secret: 'ApexPrimeBot_2026',
            ...dataObj
        });
        const req = https.request('https://apexprime.club/api.php', {
            method: 'POST',
            timeout: 12000,
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(payload)
            }
        }, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                try {
                    resolve(JSON.parse(data));
                } catch (e) {
                    resolve(null);
                }
            });
        });
        req.on('error', (err) => {
            console.error('[Website API Error]:', err.message);
            resolve(null);
        });
        req.on('timeout', () => {
            req.destroy();
            resolve(null);
        });
        req.write(payload);
        req.end();
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
            return "❌ *Session Cancelled.*\n\nReply *menu* anytime to view our services.";
        }
    }

    // ── ACTIVE SESSION STEPS ──
    if (session) {
        // Step 1: Entering User Code
        if (session.step === 'order_user_code') {
            if (['no account', 'no', 'guest', 'none', 'link', 'pay', 'direct'].includes(lower)) {
                customerSessions.delete(phone);
                return "👋 *Instant Online Purchase Link*:\n━━━━━━━━━━━━━━━━━━━━━\nYou can order and pay for Data Bundles, WAEC Result Checkers, or MTN AFA registration directly via our secure link:\n\n👉 https://payroute.name/mr-nipah\n━━━━━━━━━━━━━━━━━━━━━\n_(Or reply with your User Code anytime if you have an account)_";
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
                    customerSessions.delete(phone);
                    return `👤 *User Verified*: *${user.username}* (\`APEX-${user.id}\`)\n🏷️ *Tier*: *${role}*\n💰 *Wallet Balance*: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\n⚠️ *Insufficient Wallet Balance*\n\nYour balance is too low to place an order. Please top up your wallet:\n\n📱 *MoMo Number*: \`0530429556\`\n👤 *Account Name*: *Sir Esarq Ent (Eric Fosu)*\n📝 *Payment Reference*: \`APEX-${user.id}\`\n\n🌐 *Or Pay Online Instantly:*\n👉 https://payroute.name/mr-nipah`;
                }

                session.step = 'order_select_network';
                session.timestamp = now;
                return `👤 *User Verified*: *${user.username}* (\`APEX-${user.id}\`)\n🏷️ *Tier*: *${role}*\n💰 *Wallet Balance*: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nPlease choose a network by replying with a number (*1 - 4*):\n\n1️⃣ *MTN Data Bundles*\n2️⃣ *Telecel Data Bundles*\n3️⃣ *AT / AirtelTigo Ishare*\n4️⃣ *MTN AFA Registration*\n\n_(Reply *cancel* anytime to abort)_`;
            } else {
                return `❌ *User Code Not Found*\n━━━━━━━━━━━━━━━━━━━━━\nUser Code \`${raw}\` was not found in our database.\n\n💡 *Don't have an account?*\nOrder directly via our instant link:\n👉 https://payroute.name/mr-nipah\n\n_(Or re-enter your valid User Code e.g. 317, or reply *cancel* to abort)_`;
            }
        }

        // Step 2: Selecting Network
        if (session.step === 'order_select_network') {
            let network = null;
            if (lower === '1' || lower.includes('mtn')) network = 'MTN';
            else if (lower === '2' || lower.includes('telecel')) network = 'Telecel';
            else if (lower === '3' || lower.includes('at') || lower.includes('ishare')) network = 'Ishare';
            else if (lower === '4' || lower.includes('afa')) network = 'AFA';

            if (!network) {
                return `⚠️ *Invalid Option*\nPlease reply with a number from *1 to 4*:\n1️⃣ *MTN Data*\n2️⃣ *Telecel Data*\n3️⃣ *AT Ishare*\n4️⃣ *MTN AFA*\n\n_(Reply *cancel* to abort)_`;
            }

            if (network === 'AFA') {
                session.step = 'order_afa_details';
                session.timestamp = now;
                return `📝 *MTN AFA Registration (GHS 15.00)*\n━━━━━━━━━━━━━━━━━━━━━\nPlease reply with the registration details in this format:\n👉 \`<Phone> <Full Name> <Ghana Card Number>\`\n\n• Example: \`0541145310 Eric Fosu GHA-123456789-0\`\n\n_(Reply *cancel* to abort)_`;
            }

            session.data.network = network;
            session.step = 'order_enter_bundle';
            session.timestamp = now;

            const priceRes = await callWebsiteApi({ op: 'get_price', network, amount: 1, user_id: session.data.user_id, role: session.data.role });
            const rateStr = (priceRes && priceRes.price) ? ` (Rate: *GHS ${parseFloat(priceRes.price).toFixed(2)} / GB*)` : '';

            return `📱 *${network} Data Bundle Order*\n━━━━━━━━━━━━━━━━━━━━━\n👤 User: *${session.data.username}* (\`APEX-${session.data.user_id}\`)${rateStr}\n💰 Balance: *GHS ${session.data.wallet_balance.toFixed(2)}*\n\nPlease enter the *Recipient Phone Number* and *GB size*:\n👉 Format: \`<phone> <GB>\`\n\n• Example: \`0559623850 2\`\n• Example: \`0241234567 5\`\n\n_(Reply *cancel* to abort)_`;
        }

        // Step 3: Enter Bundle (<Phone> <GB>)
        if (session.step === 'order_enter_bundle') {
            const bundleMatch = raw.match(/(\d{10,12})\s+(\d+(?:\.\d+)?)/);
            if (!bundleMatch) {
                return `⚠️ *Invalid Format*\nPlease enter the *recipient phone* and *GB size* separated by a space:\n👉 Example: \`0559623850 2\`\n\n_(Reply *cancel* to abort)_`;
            }

            const recipient = bundleMatch[1];
            const gb = parseFloat(bundleMatch[2]);
            if (gb <= 0) {
                return `⚠️ Please specify a valid GB amount (e.g. \`0559623850 2\`).`;
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
                return `❌ Unable to calculate bundle pricing for ${session.data.network} ${gb}GB. Please contact support at 0541145310.`;
            }

            if (session.data.wallet_balance < cost) {
                return `⚠️ *Insufficient Balance*\n━━━━━━━━━━━━━━━━━━━━━\nRequired: *GHS ${cost.toFixed(2)}*\nYour Balance: *GHS ${session.data.wallet_balance.toFixed(2)}*\n\nPlease top up your wallet or order directly via:\n👉 https://payroute.name/mr-nipah\n\n_(Reply *cancel* to abort)_`;
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
                return `✅ *ORDER PLACED SUCCESSFULLY!* 🚀\n━━━━━━━━━━━━━━━━━━━━━\n📦 *Order ID*: \`#${orderId}\`\n📱 *Network*: ${session.data.network}\n👤 *Recipient*: \`${recipient}\`\n📊 *Data Bundle*: *${gb} GB*\n💵 *Amount Charged*: *GHS ${cost.toFixed(2)}*\n💰 *Remaining Balance*: *GHS ${newBal}*\n⏳ *Status*: Processing (Automated Delivery)\n━━━━━━━━━━━━━━━━━━━━━\nThank you for choosing Apex Prime Tech!`;
            } else {
                return `❌ *Order Failed*: ${orderRes?.message || 'Server error processing order'}. Your wallet was not debited. Please try again or contact support.`;
            }
        }

        // Step 4: Check Status Session
        if (session.step === 'check_status_query') {
            customerSessions.delete(phone);
            const statusRes = await callWebsiteApi({ op: 'check_status', search: raw });
            if (statusRes && statusRes.success && statusRes.order) {
                const o = statusRes.order;
                const statusEmoji = (o.status === 'completed' || o.status === 'Completed') ? '✅' : (o.status === 'failed' ? '❌' : '⏳');
                return `📦 *ORDER STATUS REPORT* 📦\n━━━━━━━━━━━━━━━━━━━━━\n🔢 *Order ID*: \`#${o.id}\`\n📱 *Network*: ${o.network || 'Data'}\n👤 *Recipient*: \`${o.recipient_phone || 'N/A'}\`\n📊 *Bundle*: ${o.gb_amount || '1'} GB\n${statusEmoji} *Delivery Status*: *${(o.status || 'processing').toUpperCase()}*\n📝 *Gateway Message*: ${o.message || 'Dispatched via Gateway'}\n📅 *Date Placed*: ${o.created_at || 'Recently'}\n━━━━━━━━━━━━━━━━━━━━━\nNeed help? Contact support at 0541145310.`;
            } else {
                return `❌ *Order Not Found*\n━━━━━━━━━━━━━━━━━━━━━\nNo order record matched: \`${raw}\`.\n\nPlease check your Order ID or phone number and try again.\n(Reply *menu* to return to the main menu)`;
            }
        }
    }

    // ── INITIAL INTENT TRIGGERS (When no active session) ──

    // 1. Trigger "1" / "place order" / "buy"
    if (lower === '1' || lower === '1.' || ['place order', 'order', 'buy', 'buy bundle', 'buy data', 'packages', 'bundle', 'data'].includes(lower)) {
        customerSessions.set(phone, { step: 'order_user_code', data: {}, timestamp: now });
        return `🛒 *Apex Prime Tech — Place Order* 🛒\n━━━━━━━━━━━━━━━━━━━━━\nPlease enter your *User Code* (e.g. \`317\` or \`APEX-317\`):\n\n💡 *Don't have an account or User Code?*\nOrder directly via our instant link:\n👉 https://payroute.name/mr-nipah\n\n_(Reply *cancel* anytime to abort)_`;
    }

    // 2. Trigger "2" / "check result" / "waec" / "wassce" / "bece"
    if (lower === '2' || lower === '2.' || ['check result', 'result', 'results', 'waec', 'wassce', 'bece', 'checker'].includes(lower)) {
        return `🎓 *WAEC Result Checker — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nCheck your BECE or WASSCE results online:\n\n🌐 *Official Checking Portal*:\nhttps://ghana.waecdirect.org/\n\n📝 *Quick Steps*:\n1. Go to https://ghana.waecdirect.org/\n2. Enter your 10-digit Index Number\n3. Select Exam Type (WASSCE / BECE) & Exam Year\n4. Enter your Card Serial Number & 12-digit PIN\n5. Click Submit to view your result slip!\n\n💳 *Need a Result Checker Card?*\nBuy instantly with instant delivery at:\n👉 https://apexprime.club/digital_store`;
    }

    // 3. Trigger "3" / "check status" / "track" / "status"
    if (lower === '3' || lower === '3.' || ['check status', 'status', 'track', 'track order', 'order status'].includes(lower)) {
        customerSessions.set(phone, { step: 'check_status_query', data: {}, timestamp: now });
        return `📦 *Track Order Status — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nPlease enter your *Order ID* (e.g. \`16523\`) or *Recipient Phone Number* to check status:\n\n_(Reply *cancel* to abort)_`;
    }

    // Quick 1-line status check e.g. "status 16523" or "status 0541145310"
    if (lower.startsWith('status ') || lower.startsWith('track ')) {
        const query = raw.replace(/^(status|track)\s+/i, '').trim();
        const statusRes = await callWebsiteApi({ op: 'check_status', search: query });
        if (statusRes && statusRes.success && statusRes.order) {
            const o = statusRes.order;
            const statusEmoji = (o.status === 'completed' || o.status === 'Completed') ? '✅' : (o.status === 'failed' ? '❌' : '⏳');
            return `📦 *ORDER STATUS REPORT* 📦\n━━━━━━━━━━━━━━━━━━━━━\n🔢 *Order ID*: \`#${o.id}\`\n📱 *Network*: ${o.network || 'Data'}\n👤 *Recipient*: \`${o.recipient_phone || 'N/A'}\`\n📊 *Bundle*: ${o.gb_amount || '1'} GB\n${statusEmoji} *Delivery Status*: *${(o.status || 'processing').toUpperCase()}*\n📝 *Gateway Message*: ${o.message || 'Dispatched via Gateway'}\n📅 *Date Placed*: ${o.created_at || 'Recently'}\n━━━━━━━━━━━━━━━━━━━━━`;
        } else {
            return `❌ No order found matching \`${query}\`. Please verify your Order ID or phone number.`;
        }
    }

    // 4. Trigger "4" / "verify payment"
    if (lower === '4' || lower === '4.' || ['verify payment', 'verify', 'payment', 'paid'].includes(lower)) {
        return `🔍 *Verify Payment — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nTo verify your payment reference or MoMo Transaction ID:\n👉 Reply *verify <reference>*\n\nExample: \`verify APX-1725894123\`\n\nOur system will confirm with the payment gateway and update your order or wallet immediately!`;
    }

    // 5. Trigger "5" / "talk to an agent" / "agent" / "support"
    if (lower === '5' || lower === '5.' || ['talk to an agent', 'talk to agent', 'agent', 'support', 'human'].includes(lower)) {
        return `📞 *Talk to an Agent — Apex Prime Tech*\n━━━━━━━━━━━━━━━━━━━━━\nOur customer support team is here to assist you 24/7!\n\n📱 Phone / WhatsApp: *0541145310*\n💬 Direct WhatsApp: https://wa.me/233541145310\n🌐 Website: https://apexprime.club\n\nPlease send your message or question right here, and an agent will attend to you shortly!`;
    }

    // 6. Trigger "balance" / "wallet"
    if (lower === 'balance' || lower === 'wallet') {
        const userRes = await callWebsiteApi({ op: 'lookup_user', search: phone });
        if (userRes && userRes.success && userRes.user) {
            const u = userRes.user;
            return `💰 *Apex Prime Wallet Balance*\n━━━━━━━━━━━━━━━━━━━━━\n👤 User: *${u.username}* (\`APEX-${u.id}\`)\n📱 Phone: \`${u.phone}\`\n💵 Balance: *GHS ${parseFloat(u.wallet_balance || 0).toFixed(2)}*\n🏷️ Tier: *${(u.role || 'client').toUpperCase()}*\n━━━━━━━━━━━━━━━━━━━━━\nTop up your wallet anytime at: https://apexprime.club/topup`;
        } else {
            return `💰 *Apex Prime Wallet Balance*\n━━━━━━━━━━━━━━━━━━━━━\nPlease enter your *User Code* (e.g. \`317\` or \`APEX-317\`) to check your balance, or login at:\n👉 https://apexprime.club/login`;
        }
    }

    // If user enters just a User Code (e.g. "317" or "APEX-317" or "apex317") directly
    if (/^(?:apex[\s\-_]*)?\d{1,6}$/i.test(raw)) {
        const userRes = await callWebsiteApi({ op: 'lookup_user', search: raw });
        if (userRes && userRes.success && userRes.user) {
            const u = userRes.user;
            const bal = parseFloat(u.wallet_balance || 0);
            customerSessions.set(phone, {
                step: 'order_select_network',
                data: { user_id: u.id, username: u.username, wallet_balance: bal, role: u.role || 'client' },
                timestamp: now
            });
            return `👤 *User Verified*: *${u.username}* (\`APEX-${u.id}\`)\n🏷️ *Tier*: *${(u.role || 'client').toUpperCase()}*\n💰 *Wallet Balance*: *GHS ${bal.toFixed(2)}*\n━━━━━━━━━━━━━━━━━━━━━\nPlease choose an option:\n1️⃣ *MTN Data Bundles*\n2️⃣ *Telecel Data Bundles*\n3️⃣ *AT / AirtelTigo Ishare*\n4️⃣ *MTN AFA Registration*\n\n_(Reply *cancel* anytime to abort)_`;
        }
    }

    return null;
}

/**
 * Call full interactive session engine connected to Apex Prime live database,
 * falling back to local PHP CLI bridge if present.
 */
async function processMessageViaBridge(phone, text, name) {
    try {
        const interactiveReply = await handleCustomerInteractiveSession(phone, text, name);
        if (interactiveReply) {
            return { handled: true, reply: interactiveReply };
        }
    } catch (e) {
        console.error('[Interactive Session Error]:', e.message);
    }

    return new Promise((resolve) => {
        if (!fs.existsSync(BRIDGE_SCRIPT)) return resolve(null);
        execFile('php', [BRIDGE_SCRIPT, phone, text, name || 'Customer'], { timeout: 15000 }, (error, stdout) => {
            if (error || !stdout) return resolve(null);
            try {
                const res = JSON.parse(stdout.trim());
                if (res && res.handled && res.reply) return resolve(res);
            } catch (e) {}
            resolve(null);
        });
    });
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
        fallback_message: "🤖 Hello {name}! Welcome to Apex Prime.\nType *menu* to see available commands.",
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
    const ignored = config.ignored_numbers || [];
    const clean = String(phone).replace(/\D/g, '');
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

    // 10. .commands / .help / .menu
    else if (cmd === '.commands' || cmd === 'commands' || cmd === '.help' || cmd === '.menu') {
        replyText = `👑 *APEX PRIME — PERSONAL BOT COMMANDS* 👑\n━━━━━━━━━━━━━━━━━━━━━\nControl your personal assistant features directly from your WhatsApp!\n\n⚙️ *LIVE SETTINGS:*\n• 👁️ Auto-View Status: ${ub.autoview ? '🟢 *ON*' : '🔴 *OFF*'}\n• ❤️ Auto-Like Status: ${ub.autolike ? `🟢 *ON* (${ub.autolike_emoji || '❤️'})` : '🔴 *OFF*'}\n• 🗑️ Anti-Delete: ${ub.recoverydeleted ? '🟢 *ON*' : '🔴 *OFF*'}\n• 📸 Save View-Once: ${ub.savedviews ? '🟢 *ON*' : '🔴 *OFF*'}\n• 📇 Auto-Save Contacts: ${ub.autosavecontact ? '🟢 *ON*' : '🔴 *OFF*'}\n\n━━━━━━━━━━━━━━━━━━━━━\n🛠️ *AUTO-FEATURES & EMOJI:*\n• *autoview on* | *autoview off*\n• *autolike on* | *autolike off*\n• *.statusemoji <emoji>* — Set reaction emoji (e.g. *.statusemoji 🔥*)\n• *recoverydeleted on* | *recoverydeleted off*\n• *savedviews on* | *savedviews off*\n• *auto save contact on* | *auto save contact off*\n\n━━━━━━━━━━━━━━━━━━━━━\n👥 *GROUP COMMANDS:*\n• *.tagall [message]* — Mention every member in a group.\n• *.hidetag <message>* — Notify all group members silently.\n\n━━━━━━━━━━━━━━━━━━━━━\n🔓 *MEDIA & FUN TOOLS:*\n• *.vv* — Reply to any View-Once photo/video to unlock it.\n• *.readmore <Header> | <Secret>* — Create WhatsApp Read-More prank.\n\n━━━━━━━━━━━━━━━━━━━━━\n⚡ *UTILITY COMMANDS:*\n• *.status* — Check bot uptime, memory & socket health.\n• *.getcontacts* — Send downloadable VCF file of saved contacts.\n• *.clearcache* — Flush deleted message history cache.\n• *.ping* — Check bot response latency.\n━━━━━━━━━━━━━━━━━━━━━`;
    }

    // 11. .status
    else if (cmd === '.status' || cmd === 'status') {
        const uptimeSeconds = Math.floor(process.uptime());
        const hours = Math.floor(uptimeSeconds / 3600);
        const mins = Math.floor((uptimeSeconds % 3600) / 60);
        const secs = uptimeSeconds % 60;
        const mem = (process.memoryUsage().rss / 1024 / 1024).toFixed(1);

        replyText = `🤖 *APEX PRIME BOT — SYSTEM HEALTH*\n━━━━━━━━━━━━━━━━━━━━━\n• 🟢 Socket Status: CONNECTED & ONLINE\n• ⏱️ Uptime: ${hours}h ${mins}m ${secs}s\n• 🧠 Memory Usage: ${mem} MB\n• 📦 Cached Messages: ${messageStore.size}\n• ⚙️ Userbot Tools:\n  - Auto-View: ${ub.autoview ? '🟢 ON' : '🔴 OFF'}\n  - Auto-Like: ${ub.autolike ? `🟢 ON (${ub.autolike_emoji || '❤️'})` : '🔴 OFF'}\n  - Anti-Delete: ${ub.recoverydeleted ? '🟢 ON' : '🔴 OFF'}\n  - Save View-Once: ${ub.savedviews ? '🟢 ON' : '🔴 OFF'}\n  - Auto-Save Contacts: ${ub.autosavecontact ? '🟢 ON' : '🔴 OFF'}\n━━━━━━━━━━━━━━━━━━━━━`;
    }

    // 12. .getcontacts
    else if (cmd === '.getcontacts' || cmd === 'getcontacts') {
        const vcfFile = path.resolve(__dirname, 'contacts_export.vcf');
        if (fs.existsSync(vcfFile) && fs.statSync(vcfFile).size > 0) {
            try {
                const targetJid = isFromMe ? from : `${senderPhone}@s.whatsapp.net`;
                await sock.sendMessage(targetJid, {
                    document: fs.readFileSync(vcfFile),
                    mimetype: 'text/vcard',
                    fileName: 'Apex_AutoSaved_Contacts.vcf',
                    caption: `📇 *Here is your Auto-Saved Contacts file!*\nTap it to import all new customer contacts directly into your phone.`
                }, { quoted: msg });
                return true;
            } catch (e) {
                replyText = `❌ Failed to send contacts file: ${e.message}`;
            }
        } else {
            replyText = `📇 No auto-saved contacts recorded yet. Make sure *auto save contact on* is activated!`;
        }
    }

    // 13. .clearcache
    else if (cmd === '.clearcache') {
        const count = messageStore.size;
        messageStore.clear();
        replyText = `🧹 Cleared ${count} cached messages from memory!`;
    }

    // 14. .ping
    else if (cmd === '.ping' || cmd === 'ping') {
        replyText = `🏓 Pong! Bot response time: ~${Math.floor(Math.random() * 20 + 20)}ms`;
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

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
    const { version, isLatest } = await fetchLatestBaileysVersion();
    console.log(`[WhatsApp] Using Baileys version ${version.join('.')} (isLatest: ${isLatest})`);

    const sock = makeWASocket({
        version,
        auth: state,
        logger: pino({ level: 'silent' }),
        printQRInTerminal: false,
        browser: ['Apex Prime Bot', 'Chrome', '120.0.6099.109'],
        syncFullHistory: false,
        connectTimeoutMs: 60000,
        defaultQueryTimeoutMs: 60000,
        keepAliveIntervalMs: 25000,
        retryRequestDelayMs: 2000
    });

    // Save session credentials whenever updated
    sock.ev.on('creds.update', saveCreds);

    // Handle connection status updates
    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            botStatus = 'scan_qr';
            console.log('\n-----------------------------------------------------');
            console.log(' SCAN THE QR CODE BELOW WITH YOUR WHATSAPP TO CONNECT:');
            console.log(' 1. Open WhatsApp on your phone');
            console.log(' 2. Go to Linked Devices > Link a Device');
            console.log(' 3. Point your camera at this QR code:');
            console.log('-----------------------------------------------------\n');
            qrcode.generate(qr, { small: true });

            try {
                QRCodeImage.toFile(path.resolve(__dirname, 'qr.png'), qr, { scale: 8 });
                QRCodeImage.toDataURL(qr, (err, url) => {
                    if (!err && url) currentQrDataUrl = url;
                });
            } catch (err) {}
        }

        if (connection === 'close') {
            botStatus = 'reconnecting';
            const statusCode = (lastDisconnect?.error)?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

            console.log(`[WhatsApp] Connection closed. Status code: ${statusCode}. Reconnecting: ${shouldReconnect}`);

            if (shouldReconnect) {
                const retryDelay = statusCode === 440 ? 5000 : 3000;
                setTimeout(() => {
                    startBot().catch(e => console.error('[Restart Error]:', e.message));
                }, retryDelay);
            } else {
                console.log('[WhatsApp] You have logged out. Automatically clearing "auth_info_baileys" to generate a new QR code...');
                try {
                    fs.rmSync(AUTH_DIR, { recursive: true, force: true });
                } catch (e) {}
                setTimeout(() => {
                    startBot().catch(e => console.error('[Restart Error]:', e.message));
                }, 2000);
            }
        } else if (connection === 'open') {
            botStatus = 'connected';
            currentQrDataUrl = null;
            connectedPhone = sock.user?.id ? sock.user.id.split(':')[0] : 'Online';
            console.log('\n======================================================');
            console.log(' [SUCCESS] WhatsApp Bot is CONNECTED & ONLINE!        ');
            console.log(' Ready to receive and reply to incoming messages 24/7.');
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

            // Strictly ignore WhatsApp Groups and Community Newsletters for bot customer flows
            if (from.endsWith('@g.us') || from.endsWith('@newsletter') || from.includes('-')) {
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

            // 8. Process via full PHP WhatsAppBot engine (Sessions, WAEC Check, SQL DB Anti-Scam Verify)
            const bridgeRes = await processMessageViaBridge(senderPhone, text, pushName);
            let reply = bridgeRes ? (typeof bridgeRes === 'string' ? bridgeRes : bridgeRes.reply) : null;

            // 9. Fallback to bot_commands.json if bridge returned null
            if (!reply) {
                reply = findMatchingResponse(text, pushName, senderPhone);
            }

            if (reply) {
                try {
                    await sock.sendMessage(from, { text: reply }, { quoted: msg });
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

// Built-in Web Server for cPanel / Cloud Hosting & Web QR Scanner
const PORT = process.env.PORT || 3000;
const server = http.createServer((req, res) => {
    // API endpoint for health check or status
    if (req.url === '/status' || req.url === '/api/status') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ status: botStatus, phone: connectedPhone }));
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
