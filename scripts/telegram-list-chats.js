/**
 * List Telegram chats the bot can see (from recent updates + getChat probes).
 *
 *   TELEGRAM_BOT_TOKEN="..." npm run telegram:chats
 *
 * Tip: send a message in your target group first, then run this.
 */

"use strict";

const TELEGRAM_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || "";

if (!TELEGRAM_BOT_TOKEN) {
  console.error("Set TELEGRAM_BOT_TOKEN");
  process.exit(1);
}

const API = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}`;

async function api(method, body) {
  const res = await fetch(`${API}/${method}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body || {}),
  });
  return res.json();
}

async function main() {
  const me = await api("getMe");
  if (!me.ok) throw new Error(JSON.stringify(me));
  console.log(`Bot: @${me.result.username}\n`);

  const chats = new Map();

  const updates = await api("getUpdates", { limit: 100, allowed_updates: ["message", "my_chat_member"] });
  if (updates.ok) {
    for (const u of updates.result || []) {
      const chat = u.message?.chat || u.my_chat_member?.chat;
      if (chat) chats.set(chat.id, chat);
    }
  }

  // Also probe ids the user may have configured elsewhere.
  for (const raw of ["5144861600", "-5144861600", "5438367592", "-5438367592"]) {
    const probe = await api("getChat", { chat_id: raw });
    if (probe.ok) chats.set(probe.result.id, probe.result);
  }

  if (!chats.size) {
    console.log("No chats found yet.");
    console.log("Add the bot to your group, send a message there, then run this again.");
    return;
  }

  console.log("Chats the bot can post to:\n");
  console.log("(Use a separate id for WHATSAPP_IMPORT_CHAT_ID — not the Jobs bot group.)\n");
  for (const chat of chats.values()) {
    const name = chat.title || chat.first_name || chat.username || "?";
    console.log(`  id: ${chat.id}`);
    console.log(`  type: ${chat.type}`);
    console.log(`  name: ${name}`);
    console.log(`  → WHATSAPP_IMPORT_CHAT_ID="${chat.id}"\n`);
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
