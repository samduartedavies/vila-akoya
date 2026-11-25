/**
 * Money helpers for receipt extraction: cross-check the OCR total against the other
 * totals printed on the receipt, and parse amounts typed by people in chat.
 */

function toCents(n) {
  const v = Number(n);
  return Number.isFinite(v) ? Math.round(v * 100) : null;
}

function fromCents(c) {
  return c == null ? null : c / 100;
}

/**
 * A receipt usually prints the total several ways (TOTAL line, card/Multibanco charge,
 * IVA base + IVA, sum of items). Pick the value most of them agree on.
 *
 * Returns { amount, changed, uncertain, candidates }.
 */
function reconcileReceiptAmount({
  amount,
  paidAmount,
  vatBase,
  vat,
  lineItemAmounts,
  itemCount,
} = {}) {
  const candidates = [];
  const push = (source, value) => {
    const c = toCents(value);
    if (c != null && c > 0) candidates.push({ source, cents: c });
  };
  push("total", amount);
  push("paid", paidAmount);
  if (toCents(vatBase) != null && toCents(vat) != null) {
    push("vat_base_plus_vat", (toCents(vatBase) + toCents(vat)) / 100);
  }
  const items = (Array.isArray(lineItemAmounts) ? lineItemAmounts : [])
    .map(toCents)
    .filter((c) => c != null);
  const countOk = !Number(itemCount) || Number(itemCount) === items.length;
  if (items.length && countOk) {
    push("line_items", items.reduce((a, b) => a + b, 0) / 100);
  }

  const original = toCents(amount);
  if (!candidates.length) {
    return { amount: fromCents(original), changed: false, uncertain: original == null, candidates };
  }

  const support = new Map();
  for (const { cents } of candidates) support.set(cents, (support.get(cents) || 0) + 1);

  const priority = ["paid", "total", "vat_base_plus_vat", "line_items"];
  const rank = (cents) =>
    Math.min(
      ...candidates
        .filter((c) => c.cents === cents)
        .map((c) => priority.indexOf(c.source))
    );
  const best = [...support.keys()].sort(
    (a, b) => support.get(b) - support.get(a) || rank(a) - rank(b)
  )[0];

  const distinct = support.size;
  const uncertain = distinct > 1 && support.get(best) < 2;
  const chosen = uncertain && original != null ? original : best;
  return {
    amount: fromCents(chosen),
    changed: original != null && chosen !== original,
    uncertain,
    candidates: candidates.map((c) => ({ source: c.source, amount: fromCents(c.cents) })),
  };
}

/** "113,94" / "113.94" / "€1.234,56" / "1,234.56" / "113" → number, or null. */
function parseMoneyToken(raw) {
  let s = String(raw || "").replace(/[€\s]|eur(os?)?/gi, "");
  if (!/\d/.test(s)) return null;
  const lastComma = s.lastIndexOf(",");
  const lastDot = s.lastIndexOf(".");
  const decimalSep = lastComma > lastDot ? "," : lastDot > lastComma ? "." : null;
  if (decimalSep) {
    const decimals = s.length - s.lastIndexOf(decimalSep) - 1;
    if (decimals === 3 && s.split(decimalSep).length === 2 && !s.includes(decimalSep === "," ? "." : ",")) {
      // "1.234" or "1,234" with no other separator → thousands grouping.
      s = s.replace(decimalSep, "");
    } else {
      const thousands = decimalSep === "," ? /\./g : /,/g;
      s = s.replace(thousands, "").replace(decimalSep, ".");
    }
  }
  const n = Number(s);
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : null;
}

const MONEY_RE = /(?:€\s*)?\d{1,3}(?:[.,\s]\d{3})*(?:[.,]\d{1,2})?(?:\s*(?:€|eur(?:os?)?\b))?|(?:€\s*)?\d+(?:[.,]\d{1,2})?(?:\s*(?:€|eur(?:os?)?\b))?/gi;

/**
 * Find the corrected amount in a message like "It should be 113,94" / "total is €113.94".
 * Prefers the number after "should be / is / to / deve ser"; otherwise the last money-looking number.
 */
function parseCorrectedAmount(text) {
  const t = String(text || "");
  const withoutDates = t.replace(/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b/g, " ");
  const preferred = withoutDates.match(
    /\b(?:should be|should have been|is actually|actually|is|was|to|=|deve ser|devia ser|é|era|para)\s*:?\s*((?:€\s*)?\d[\d.,\s]*\d(?:\s*(?:€|eur(?:os?)?\b))?|(?:€\s*)?\d)/i
  );
  if (preferred) {
    const v = parseMoneyToken(preferred[1]);
    if (v != null) return v;
  }
  const matches = withoutDates.match(MONEY_RE) || [];
  for (let i = matches.length - 1; i >= 0; i--) {
    const v = parseMoneyToken(matches[i]);
    if (v != null) return v;
  }
  return null;
}

function looksLikeAmountCorrection(text) {
  const t = String(text || "").toLowerCase();
  if (!t) return false;
  const mentionsAmount =
    /\b(amount|total|price|valor|montante|quantia|preço|preco|cost|euros?|eur)\b|€/.test(t);
  const mentionsFix =
    /\b(wrong|incorrect|mistake|error|fix|correct|should be|should have been|actually|not right|errad[oa]|corrig|deve ser|devia ser|change|update)\b/.test(
      t
    );
  return mentionsAmount && mentionsFix && parseCorrectedAmount(t) != null;
}

module.exports = {
  reconcileReceiptAmount,
  parseMoneyToken,
  parseCorrectedAmount,
  looksLikeAmountCorrection,
};
