/**
 * Ask Câmara Municipal de Ourique to empty the full recycling / rubbish bins.
 *
 * Email via SMTP (same setup as WP Mail SMTP on the site: Gmail / Google Workspace)
 * or Resend if RESEND_API_KEY is set and SMTP_PASS is not. CCs Sam and Reply-To
 * his Hotmail so council replies land in his inbox.
 */

"use strict";

const tls = require("tls");
const net = require("net");

const COUNCIL_EMAIL = process.env.COUNCIL_EMAIL || "geral@cm-ourique.pt";
const COUNCIL_EMAIL_FROM =
  process.env.COUNCIL_EMAIL_FROM || "Sam Duarte-Davies <info@vila-akoya.com>";
const COUNCIL_EMAIL_REPLY_TO = process.env.COUNCIL_EMAIL_REPLY_TO || "samduarte-davies@hotmail.com";
const COUNCIL_EMAIL_CC =
  (process.env.COUNCIL_EMAIL_CC && process.env.COUNCIL_EMAIL_CC.trim()) || COUNCIL_EMAIL_REPLY_TO;
const COUNCIL_EMAIL_SIGNATURE = process.env.COUNCIL_EMAIL_SIGNATURE || "Sam Duarte-Davies";
const BINS_LOCATION_URL = process.env.BINS_LOCATION_URL || "https://maps.app.goo.gl/djuVzuxitoSwkSw3A";
const COOLDOWN_HOURS = Number(process.env.COUNCIL_BINS_COOLDOWN_HOURS || 48);

const SMTP_HOST = process.env.SMTP_HOST || "smtp.hostinger.com";
const SMTP_PORT = Number(process.env.SMTP_PORT || 465);
const SMTP_USER = process.env.SMTP_USER || "info@vila-akoya.com";
/** Port 465 uses implicit TLS (Hostinger). Port 587 uses STARTTLS. */
const SMTP_IMPLICIT_TLS = SMTP_PORT === 465 || process.env.SMTP_SECURE === "ssl";

const BIN_TYPES = {
  paper: { en: "paper/cardboard", pt: "papel/cartão (azul)" },
  plastic: { en: "plastic & metal packaging", pt: "embalagens de plástico e metal (amarelo)" },
  glass: { en: "glass", pt: "vidro (verde)" },
  general: { en: "general waste", pt: "lixo indiferenciado" },
};

function isEnabled() {
  if (!COUNCIL_EMAIL) return false;
  if (process.env.SMTP_PASS) return true;
  return Boolean(process.env.RESEND_API_KEY);
}

function normalizeBins(bins) {
  const list = (Array.isArray(bins) ? bins : [bins]).map((b) => String(b || "").toLowerCase().trim());
  if (list.includes("all")) return Object.keys(BIN_TYPES);
  return [...new Set(list.filter((b) => BIN_TYPES[b]))];
}

const BIN_LABELS_EN = Object.fromEntries(Object.entries(BIN_TYPES).map(([k, v]) => [k, v.en]));

/** Free-text replies: "general waste", "paper and glass", "all", PT labels, etc. */
function parseBinsFromUserText(text) {
  const raw = String(text || "").trim();
  if (!raw) return [];
  const direct = normalizeBins(
    raw
      .split(/[,;&+]|\band\b|\be\b|\u2014|\//gi)
      .map((s) => s.trim())
      .filter(Boolean)
  );
  if (direct.length) return direct;

  const t = raw.toLowerCase();
  if (/\ball\b|todos|todas/.test(t)) return Object.keys(BIN_TYPES);
  const bins = [];
  if (/paper|cardboard|papel|cart[aã]o/.test(t)) bins.push("paper");
  if (/plastic|metal|packaging|embalagem|amarelo|yellow/.test(t)) bins.push("plastic");
  if (/glass|vidro|verde/.test(t)) bins.push("glass");
  if (/general|indiferenciado|^(lixo|rubbish|trash|waste)\b|general waste|black bin/.test(t)) bins.push("general");
  return [...new Set(bins)];
}

/**
 * Shared logic for the agent tool and Telegram pending follow-ups.
 * @param {{ bins?: string[], confirmed?: boolean, lastSent?: Record<string,string> }} opts
 */
async function processRequest({ bins = [], confirmed = false, lastSent = {} }) {
  if (!isEnabled()) {
    return { error: "Council email isn't set up (set SMTP_PASS or RESEND_API_KEY on the bot)." };
  }
  const wanted = normalizeBins(bins);
  if (!wanted.length) {
    return { needs_bin_types: true, options: BIN_LABELS_EN, hint: "Ask which are full; they can pick several or all." };
  }

  const { due, recent } = splitByCooldown(wanted, lastSent);
  const alreadyRequested = recent.map((r) => ({ bin: BIN_LABELS_EN[r.bin], emailed_at: r.at }));
  if (!due.length) {
    return {
      already_requested: alreadyRequested,
      hint: `Already emailed the council within the last ${COOLDOWN_HOURS}h — no new email sent.`,
      bins: wanted.map((b) => BIN_LABELS_EN[b]),
    };
  }
  const email = buildEmail(due);
  if (!confirmed) {
    return {
      needs_confirmation: true,
      would_email: email.to,
      due_bins: due,
      bins: due.map((b) => BIN_LABELS_EN[b]),
      already_requested: alreadyRequested,
    };
  }

  await sendEmail(email);
  const sentAt = new Date().toISOString();
  const sentKeys = {};
  for (const bin of due) sentKeys[bin] = sentAt;
  const names = due.map((b) => BIN_LABELS_EN[b]);
  return {
    ok: true,
    emailed: email.to,
    bins: names,
    due_bins: due,
    already_requested: alreadyRequested,
    sentKeys,
    done: `Emailed the council to empty the ${names.join(", ")} bins`,
  };
}

/** @param {Record<string,string>} lastSent bin type → ISO timestamp of the last email */
function splitByCooldown(bins, lastSent = {}, now = Date.now()) {
  const due = [];
  const recent = [];
  for (const bin of bins) {
    const at = lastSent[bin] ? Date.parse(lastSent[bin]) : NaN;
    if (Number.isFinite(at) && now - at < COOLDOWN_HOURS * 3600 * 1000) recent.push({ bin, at: lastSent[bin] });
    else due.push(bin);
  }
  return { due, recent };
}

function parseFromHeader(from) {
  const m = String(from).match(/^(.+?)\s*<([^>]+)>$/);
  if (m) return { name: m[1].trim(), email: m[2].trim() };
  return { name: "", email: String(from).trim() };
}

function encodeSubject(subject) {
  if (/^[\x20-\x7E]*$/.test(subject)) return subject;
  return `=?UTF-8?B?${Buffer.from(subject, "utf8").toString("base64")}?=`;
}

function buildEmail(bins) {
  const names = bins.map((b) => BIN_TYPES[b].pt);
  const plural = bins.length > 1 ? "es" : "";
  const subject = `Pedido de recolha – contentor${plural} cheio${plural ? "s" : ""} (${bins.map((b) => BIN_TYPES[b].pt.replace(/ \(.*\)$/, "")).join(", ")})`;
  const intro =
    names.length === 1
      ? `Venho informar que o contentor de ${names[0]} está cheio e precisa de recolha.`
      : "Venho informar que os seguintes contentores estão cheios e precisam de recolha:";
  const lines = [
    "Exmos. Senhores,",
    "",
    intro,
    ...(names.length > 1 ? ["", ...names.map((n) => `- ${n}`)] : []),
    "",
    `A localização é: ${BINS_LOCATION_URL}`,
    "",
    "Se possível, agradecia que pudessem enviar a equipa responsável para fazer a recolha, pois já não há espaço para colocar mais resíduos nos contentores.",
    "",
    "Obrigado, como sempre, pela vossa ajuda. Posso enviar fotografia ou localização por WhatsApp, se necessário.",
    "",
    "Com os melhores cumprimentos,",
    "",
    COUNCIL_EMAIL_SIGNATURE,
  ];
  return { to: COUNCIL_EMAIL, subject, text: lines.join("\n") };
}

function buildMimeMessage({ to, subject, text }) {
  const from = parseFromHeader(COUNCIL_EMAIL_FROM);
  const fromLine = from.name ? `${from.name} <${from.email}>` : from.email;
  const headers = [
    `From: ${fromLine}`,
    `To: ${to}`,
    ...(COUNCIL_EMAIL_CC ? [`Cc: ${COUNCIL_EMAIL_CC}`] : []),
    `Reply-To: ${COUNCIL_EMAIL_REPLY_TO}`,
    `Subject: ${encodeSubject(subject)}`,
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=UTF-8",
    "Content-Transfer-Encoding: 8bit",
    "",
    text.replace(/\r?\n/g, "\r\n"),
  ];
  return headers.join("\r\n");
}

class SmtpSession {
  constructor(socket) {
    this.socket = socket;
    this.buffer = "";
    this.socket.setEncoding("utf8");
    this.socket.on("data", (chunk) => {
      this.buffer += chunk;
    });
  }

  readResponse() {
    return new Promise((resolve, reject) => {
      const tryParse = () => {
        const lines = this.buffer.split(/\r?\n/);
        let consumed = 0;
        let lastCode = null;
        for (let i = 0; i < lines.length - 1; i++) {
          const line = lines[i];
          if (!line) continue;
          const m = line.match(/^(\d{3})([ -])(.*)$/);
          if (!m) continue;
          lastCode = Number(m[1]);
          consumed = i + 1;
          if (m[2] === " ") break;
        }
        if (lastCode !== null && lines[consumed - 1]?.match(/^(\d{3}) /)) {
          this.buffer = lines.slice(consumed).join("\n");
          if (lastCode >= 400) reject(new Error(`SMTP ${lastCode}: ${lines[consumed - 1]}`));
          else resolve(lastCode);
          return true;
        }
        return false;
      };
      if (tryParse()) return;
      const onData = () => {
        if (tryParse()) this.socket.off("data", onData);
      };
      this.socket.on("data", onData);
      this.socket.once("error", reject);
      this.socket.once("close", () => reject(new Error("SMTP connection closed")));
    });
  }

  async send(line) {
    this.socket.write(`${line}\r\n`);
    return this.readResponse();
  }

  close() {
    try {
      this.socket.end();
    } catch {
      /* ignore */
    }
  }
}

async function openSmtpSocket() {
  if (SMTP_IMPLICIT_TLS) {
    return new Promise((resolve, reject) => {
      const socket = tls.connect({ host: SMTP_HOST, port: SMTP_PORT, servername: SMTP_HOST }, () => resolve(socket));
      socket.once("error", reject);
    });
  }
  const socket = await new Promise((resolve, reject) => {
    const s = net.connect(SMTP_PORT, SMTP_HOST, () => resolve(s));
    s.once("error", reject);
  });
  const session = new SmtpSession(socket);
  await session.readResponse();
  await session.send("EHLO vila-akoya-bot");
  await session.send("STARTTLS");
  const secure = await new Promise((resolve, reject) => {
    const s = tls.connect({ socket, servername: SMTP_HOST }, () => resolve(s));
    s.once("error", reject);
  });
  return secure;
}

async function smtpAuthenticate(session) {
  const pass = process.env.SMTP_PASS;
  if (!pass) throw new Error("SMTP_PASS not set");
  await session.readResponse();
  await session.send("EHLO vila-akoya-bot");
  await session.send("AUTH LOGIN");
  await session.send(Buffer.from(SMTP_USER, "utf8").toString("base64"));
  await session.send(Buffer.from(pass, "utf8").toString("base64"));
}

async function sendEmailSmtp(mime, deliverTo) {
  const socket = await openSmtpSocket();
  const session = new SmtpSession(socket);
  await smtpAuthenticate(session);

  const fromAddr = parseFromHeader(COUNCIL_EMAIL_FROM).email || SMTP_USER;
  await session.send(`MAIL FROM:<${fromAddr}>`);
  const recipients = [...new Set([deliverTo, COUNCIL_EMAIL_CC].filter(Boolean))];
  for (const rcpt of recipients) {
    await session.send(`RCPT TO:<${rcpt}>`);
  }
  await session.send("DATA");
  socket.write(`${mime.replace(/\r?\n/g, "\r\n")}\r\n.\r\n`);
  await session.readResponse();
  await session.send("QUIT");
  session.close();
  return { transport: "smtp" };
}

/** Connect and log in only — for smoke tests without sending mail. */
async function verifySmtpLogin() {
  const socket = await openSmtpSocket();
  const session = new SmtpSession(socket);
  await smtpAuthenticate(session);
  await session.send("QUIT");
  session.close();
  return { ok: true, host: SMTP_HOST, port: SMTP_PORT, user: SMTP_USER };
}

async function sendEmailResend({ to, subject, text }) {
  const res = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      from: COUNCIL_EMAIL_FROM,
      to: [to],
      ...(COUNCIL_EMAIL_CC ? { cc: [COUNCIL_EMAIL_CC] } : {}),
      reply_to: COUNCIL_EMAIL_REPLY_TO,
      subject,
      text,
    }),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`Resend ${res.status}: ${body.message || JSON.stringify(body).slice(0, 200)}`);
  return { id: body.id, transport: "resend" };
}

async function sendEmail({ to, subject, text }) {
  if (process.env.SMTP_PASS) {
    return sendEmailSmtp(buildMimeMessage({ to, subject, text }), to);
  }
  return sendEmailResend({ to, subject, text });
}

module.exports = {
  BIN_TYPES,
  BIN_LABELS_EN,
  COOLDOWN_HOURS,
  COUNCIL_EMAIL,
  COUNCIL_EMAIL_CC,
  isEnabled,
  normalizeBins,
  parseBinsFromUserText,
  processRequest,
  splitByCooldown,
  buildEmail,
  sendEmail,
  verifySmtpLogin,
};
