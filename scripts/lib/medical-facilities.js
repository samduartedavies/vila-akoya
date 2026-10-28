/**
 * Regional medical & emergency directory (Alentejo / Baixo Alentejo around Vila Akoya).
 * Stored in Notion; VAVA recommends facilities by need and accepts community ratings.
 */

"use strict";

const notion = require("./notion");
const { foldAccents } = require("./regional-markets");

const FACILITIES_DB_NAME =
  process.env.MEDICAL_FACILITIES_DB || notion.DB.MEDICAL_FACILITIES;
const RATINGS_DB_NAME =
  process.env.MEDICAL_FACILITY_RATINGS_DB || notion.DB.MEDICAL_FACILITY_RATINGS;

const HUB_PAGE_TITLE = "Medical & emergency";
const AVG_PROP = "Community avg (1-10)";
const COUNT_PROP = "Community ratings #";

const CATEGORIES = [
  "Emergency number",
  "Basic emergency (SUB)",
  "Hospital",
  "Health centre",
  "Health extension",
  "Private clinic",
];

const SERVICES = [
  "Emergency care",
  "Paediatrics",
  "GP / family doctor",
  "Vaccination",
  "Nursing",
  "Specialist",
  "Routine care",
  "Medical advice line",
  "24 hours",
];

function selectOpts(names) {
  const colors = [
    "gray",
    "brown",
    "orange",
    "yellow",
    "green",
    "blue",
    "purple",
    "pink",
    "red",
  ];
  return names.map((name, i) => ({
    name,
    color: colors[i % colors.length],
  }));
}

function selectProp(options) {
  return { select: { options: selectOpts(options) } };
}

function multiSelectProp(options) {
  return { multi_select: { options: selectOpts(options) } };
}

const FACILITIES_PROPERTIES = {
  Name: { title: {} },
  Location: { rich_text: {} },
  Category: selectProp(CATEGORIES),
  "Type detail": { rich_text: {} },
  Phone: { rich_text: {} },
  "When to use": { rich_text: {} },
  Town: { rich_text: {} },
  Services: multiSelectProp(SERVICES),
  Notes: { rich_text: {} },
  Status: selectProp(["Active", "Archived"]),
  [AVG_PROP]: { number: { format: "number" } },
  [COUNT_PROP]: { number: { format: "number" } },
};

const RATINGS_PROPERTIES = {
  Name: { title: {} },
  Facility: { rich_text: {} },
  "Facility key": { rich_text: {} },
  Score: { number: { format: "number" } },
  Reviewer: { rich_text: {} },
  Date: { date: {} },
  Source: selectProp(["Telegram", "Manual", "Import"]),
  Notes: { rich_text: {} },
};

/** Canonical seed rows — safe to re-run via upsert by name. */
const SEED_FACILITIES = [
  {
    name: "Emergency / Ambulance (INEM)",
    location: "Portugal (nationwide)",
    category: "Emergency number",
    typeDetail: "Emergency services",
    phone: "112",
    whenToUse:
      "Life-threatening emergencies, serious injuries, breathing difficulties.",
    town: "Portugal",
    services: ["Emergency care", "24 hours"],
    notes: "Call 112 for immediate emergency response.",
  },
  {
    name: "SNS 24",
    location: "Portugal (telephone line)",
    category: "Emergency number",
    typeDetail: "Health line",
    phone: "808 24 24 24",
    whenToUse: "Medical advice, triage, referrals for adults and children.",
    town: "Portugal",
    services: ["Medical advice line"],
    notes: "Not a substitute for 112 when someone is in immediate danger.",
  },
  {
    name: "Centro de Saúde de Ourique",
    location: "Rua dos Bombeiros Voluntários, Ourique",
    category: "Health centre",
    typeDetail: "Local GP, nursing, vaccinations and routine care",
    phone: "286 510 300",
    whenToUse: "Routine care, nursing, vaccinations — not for life-threatening emergencies.",
    town: "Ourique",
    services: ["GP / family doctor", "Vaccination", "Nursing", "Routine care"],
    notes:
      "Moved to Rua dos Bombeiros Voluntários in May 2026 (not the old Rua da Misericórdia address).",
  },
  {
    name: "Serviço de Urgência Básica de Castro Verde",
    location: "Av. General Humberto Delgado, Castro Verde",
    category: "Basic emergency (SUB)",
    typeDetail: "Basic emergency, adults and children",
    town: "Castro Verde",
    services: ["Emergency care", "Paediatrics"],
    notes:
      "Serviço de Urgência Básica — initial emergency treatment; may transfer to a larger hospital. Not a full hospital.",
  },
  {
    name: "Serviço de Urgência Básica de Odemira",
    location: "Vale Pêgas de Baixo, Odemira",
    category: "Basic emergency (SUB)",
    typeDetail: "Basic emergency",
    town: "Odemira",
    services: ["Emergency care"],
    notes:
      "Serviço de Urgência Básica — initial emergency treatment; may transfer to a larger hospital. Not a full hospital.",
  },
  {
    name: "Hospital José Joaquim Fernandes",
    location: "Rua Dr. António Fernando Covas Lima, Beja",
    category: "Hospital",
    typeDetail: "Hospital, adult and paediatric emergency",
    town: "Beja",
    services: ["Emergency care", "Paediatrics"],
    notes: "Regional hospital with emergency department.",
  },
  {
    name: "Hospital do Litoral Alentejano",
    location: "EN261, Monte do Gilbardinho, Santiago do Cacém",
    category: "Hospital",
    typeDetail: "Hospital emergency",
    town: "Santiago do Cacém",
    services: ["Emergency care"],
    notes: "Coastal Alentejo hospital emergency department.",
  },
  {
    name: "Extensão de Saúde de Garvão",
    location: "Largo da Feira, Garvão",
    category: "Health extension",
    typeDetail: "Local health extension",
    town: "Garvão",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Extensão de Saúde de Panóias",
    location: "Largo do Terreiro 27, Panóias",
    category: "Health extension",
    typeDetail: "Local health extension",
    town: "Panóias",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Extensão de Saúde de Santa Luzia",
    location: "Rua do Poço, Santa Luzia",
    category: "Health extension",
    typeDetail: "Local health extension",
    town: "Santa Luzia",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Extensão de Saúde de Santana da Serra",
    location: "Rua Dr. José Silva Cristina, Santana da Serra",
    category: "Health extension",
    typeDetail: "Local health extension",
    town: "Santana da Serra",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Centro de Saúde de Castro Verde",
    location: "Av. General Humberto Delgado, Castro Verde",
    category: "Health centre",
    typeDetail: "Public health centre",
    town: "Castro Verde",
    services: ["GP / family doctor", "Nursing", "Routine care"],
  },
  {
    name: "Centro de Saúde de Almodôvar",
    location: "Rua Prof. Dr. Fernando Pádua, Almodôvar",
    category: "Health centre",
    typeDetail: "Public health centre",
    town: "Almodôvar",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Centro de Saúde de Aljustrel",
    location: "Rua de Beja, Aljustrel",
    category: "Health centre",
    typeDetail: "Public health centre",
    town: "Aljustrel",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Centro de Saúde de Odemira",
    location: "Vale Pêgas de Baixo, Odemira",
    category: "Health centre",
    typeDetail: "Public health centre",
    town: "Odemira",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Centro de Saúde de Beja",
    location: "Rua Dom José do Patrocínio Dias, Beja",
    category: "Health centre",
    typeDetail: "Public health centre",
    town: "Beja",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "USF Alfa Beja",
    location: "Rua Rainha D. Amélia 4, Beja",
    category: "Health centre",
    typeDetail: "Family health unit",
    town: "Beja",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Extensão de Saúde de São Martinho das Amoreiras",
    location: "Estrada Nacional 123, São Martinho das Amoreiras",
    category: "Health extension",
    typeDetail: "Local health extension",
    town: "São Martinho das Amoreiras",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "CCA – Centro Clínico do Alentejo",
    location: "Rua da Seara Nova 3A, 1.º, Castro Verde",
    category: "Private clinic",
    typeDetail: "General and specialist care",
    town: "Castro Verde",
    services: ["Specialist", "Routine care"],
  },
  {
    name: "Natália Monteiro – Medicina Geral e Familiar",
    location: "Rua de Mértola 3, Castro Verde",
    category: "Private clinic",
    typeDetail: "Family doctor",
    town: "Castro Verde",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Clinicararte – Clínica Médica",
    location: "Rua 6 de Novembro 8C, Aljustrel",
    category: "Private clinic",
    typeDetail: "General medical clinic",
    town: "Aljustrel",
    services: ["Routine care"],
  },
  {
    name: "Clínica CUF Odemira",
    location: "Avenida Gago Coutinho, R/C, Odemira",
    category: "Private clinic",
    typeDetail: "General and specialist care",
    town: "Odemira",
    services: ["Specialist", "Routine care"],
  },
  {
    name: "Consultório Linha da Vida",
    location: "Estrada da Circunvalação, Odemira",
    category: "Private clinic",
    typeDetail: "Private family doctor",
    town: "Odemira",
    services: ["GP / family doctor", "Routine care"],
  },
  {
    name: "Centro Médico de Beja",
    location: "Largo D. Nuno Álvares Pereira 13, Beja",
    category: "Private clinic",
    typeDetail: "General medicine and paediatrics",
    town: "Beja",
    services: ["GP / family doctor", "Paediatrics", "Routine care"],
  },
  {
    name: "Policlínica de São Paulo",
    location: "Rua Cidade de São Paulo 29, 1.º, Beja",
    category: "Private clinic",
    typeDetail: "General medicine and paediatrics",
    town: "Beja",
    services: ["GP / family doctor", "Paediatrics", "Routine care"],
  },
  {
    name: "Clínica Criança Beja",
    location: "Rua de Olivença 19, Beja",
    category: "Private clinic",
    typeDetail: "Paediatrician",
    town: "Beja",
    services: ["Paediatrics"],
  },
  {
    name: "Clínica Rainha D. Leonor",
    location: "Rua Manuel António de Brito 4, 1.º, Beja",
    category: "Private clinic",
    typeDetail: "Multispecialty, including paediatrics",
    town: "Beja",
    services: ["Specialist", "Paediatrics"],
  },
  {
    name: "Clínica Particular de Beja",
    location: "Rua Dr. Brito Camacho 24, Beja",
    category: "Private clinic",
    typeDetail: "Private specialist clinic",
    town: "Beja",
    services: ["Specialist"],
  },
];

const NEAR_OURIQUE_TOWNS = new Set([
  "ourique",
  "garvao",
  "panoias",
  "santa luzia",
  "santana da serra",
  "castro verde",
  "almodovar",
  "aljustrel",
  "odemira",
  "sao martinho das amoreiras",
]);

const CACHE_MS = 2 * 60 * 1000;
let aggregateCache = { at: 0, byKey: new Map() };

function todayISO(tz = "Europe/Lisbon") {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}

function normalizeKey(name) {
  return foldAccents(name)
    .replace(/[^a-z0-9]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function pageToFacility(page) {
  const name = notion.pageTitle(page);
  return {
    id: page.id,
    url: page.url,
    name,
    key: normalizeKey(name),
    location: notion.pagePropText(page, "Location"),
    category: notion.pagePropText(page, "Category"),
    typeDetail: notion.pagePropText(page, "Type detail"),
    phone: notion.pagePropText(page, "Phone"),
    whenToUse: notion.pagePropText(page, "When to use"),
    town: notion.pagePropText(page, "Town"),
    services: notion.pagePropText(page, "Services"),
    notes: notion.pagePropText(page, "Notes"),
    ratingAvg: Number(notion.pagePropText(page, AVG_PROP)) || null,
    ratingCount: Number(notion.pagePropText(page, COUNT_PROP)) || 0,
  };
}

async function loadFacilityPages() {
  const { pages } = await notion.queryDatabase(FACILITIES_DB_NAME);
  return pages.filter((p) => {
    if (p.archived) return false;
    return notion.pagePropText(p, "Status") !== "Archived";
  });
}

async function loadFacilityCatalog() {
  try {
    const pages = await loadFacilityPages();
    if (!pages.length) return SEED_FACILITIES.map((row) => ({ ...row, key: normalizeKey(row.name) }));
    return pages.map(pageToFacility);
  } catch {
    return SEED_FACILITIES.map((row) => ({ ...row, key: normalizeKey(row.name) }));
  }
}

function isChildcareMedicalContext(text) {
  const t = foldAccents(text);
  return (
    /\b(childcare|child minder|kids programme|kids program|roster|allerg)\b/.test(t) &&
    /\b(today|kid|child|children|sam|parent)\b/.test(t)
  );
}

/** Cuts, wounds, “where do I take my child”, etc. — not childcare roster questions. */
function looksLikeInjuryOrUrgentCare(text) {
  const t = foldAccents(text);
  const injury =
    /\b(cut|cuts|wound|wounds|bleed|bleeding|blood|stitch|stitches|gash|laceration|hurt|injured|injury|accident|burn|burns|broken|fracture|bump|knock|fell|fall|unwell|sick)\b/.test(
      t
    );
  const headOrFace = /\b(head|face|skull|scalp|eye|teeth|tooth)\b/.test(t);
  const takeSomewhere =
    /\b(closest|nearest|where)\b.*\b(take|bring|go)\b/.test(t) ||
    /\b(take|bring)\b.*\b(hospital|doctor|clinic|urgent|emergency|place|her|him|them|child|kid|baby|daughter|son)\b/.test(
      t
    ) ||
    /\b(do you know|where should|what's the closest|whats the closest)\b/.test(t);
  const youngChild =
    /\b(daughter|son|toddler|baby|year old|yr old|child|kid|infant)\b/.test(t);
  if (injury && (headOrFace || youngChild || takeSomewhere)) return true;
  if (takeSomewhere && youngChild) return true;
  if (injury && takeSomewhere) return true;
  return false;
}

function looksLikeMedicalFacilityQuestion(text) {
  if (!text || isChildcareMedicalContext(text)) return false;
  const t = foldAccents(text);
  if (looksLikeMedicalFacilityRatingMessage(text)) return false;
  if (looksLikeInjuryOrUrgentCare(text)) return true;
  return (
    /\b(112|inem|sns\s*24|808\s*24)\b/.test(t) ||
    /\b(hospital|urgencia|urgência|emergency|ambulance|ambulancia)\b/.test(t) ||
    /\b(centro de saude|centro de saúde|health centre|health center|extensao de saude|extensão de saúde)\b/.test(
      t
    ) ||
    /\b(clinica|clínica|private doctor|family doctor|gp|medico de familia|médico de família|pediatr|paediatr)\b/.test(
      t
    ) ||
    /\b(where.*(hospital|doctor|clinic|health centre|urgencia))\b/.test(t) ||
    /\b(nearest|closest|best).*(hospital|doctor|clinic|health|urgencia|medical)\b/.test(t) ||
    /\b(medical facilit|facilities|where (can|should) i (go|get)|need a doctor)\b/.test(t)
  );
}

function parseMedicalRatingsFromText(text) {
  const cleaned = String(text || "")
    .replace(/@\w+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  if (!cleaned) return [];

  const ratings = [];
  const re = /([a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ0-9\s.'ºª-]{2,56}?)\s*:\s*(\d{1,2})(?:\s*\/\s*10)?(?=\s|$|[,.;!?])/gi;
  let match;
  while ((match = re.exec(cleaned)) !== null) {
    const hint = match[1].trim();
    const score = parseInt(match[2], 10);
    if (score < 1 || score > 10) continue;
    if (hint.length < 3) continue;
    ratings.push({ hint, score });
  }
  return ratings;
}

function scoreFacilityNameMatch(hint, facility) {
  const h = foldAccents(hint).replace(/\s+/g, " ").trim();
  const name = foldAccents(facility.name || facility.Name || "");
  const town = foldAccents(facility.town || facility.Town || "");
  if (!h) return 0;
  if (h === name) return 120;
  if (name.includes(h) || h.includes(name)) return 80 + Math.min(name.length, h.length) / 2;
  const hw = h.split(/\s+/).filter((w) => w.length > 2);
  const nw = name.split(/\s+/);
  let score = 0;
  for (const w of hw) {
    if (nw.some((n) => n === w || n.includes(w) || w.includes(n))) score += 14;
    if (town && town.includes(w)) score += 10;
  }
  if (/\bbeja\b/.test(h) && /\bbeja\b/.test(name)) score += 20;
  if (/\bodemira\b/.test(h) && /\bodemira\b/.test(name)) score += 20;
  if (/\bourique\b/.test(h) && /\bourique\b/.test(name)) score += 20;
  return score;
}

function resolveRatingTarget(hint, catalog) {
  const ranked = [...catalog]
    .map((f) => ({ f, score: scoreFacilityNameMatch(hint, f) }))
    .filter((row) => row.score >= 40)
    .sort((a, b) => b.score - a.score);
  if (ranked.length) {
    const f = ranked[0].f;
    const display = f.name || f.Name;
    return { name: display, key: f.key || normalizeKey(display), unknown: false };
  }
  const trimmed = hint.trim();
  return { name: trimmed, key: normalizeKey(trimmed), unknown: true };
}

function ratingHintLooksMedical(hint, catalog) {
  const t = foldAccents(hint);
  if (
    /\b(hospital|clinic|clinica|centro|urgencia|saude|health|beja|odemira|ourique|cuf|inem|doctor|medico)\b/.test(
      t
    )
  ) {
    return true;
  }
  return catalog.some((f) => scoreFacilityNameMatch(hint, f) >= 40);
}

function looksLikeMedicalFacilityRatingMessage(text) {
  const parsed = parseMedicalRatingsFromText(text);
  if (!parsed.length) {
    const t = foldAccents(text);
    return (
      /\b(rate|rating|review|scored)\b/.test(t) &&
      /\b(hospital|clinic|clinica|centro de saude|health centre|urgencia|doctor)\b/.test(t) &&
      /\d/.test(text)
    );
  }
  return parsed.some((row) => {
    const t = foldAccents(row.hint);
    return (
      /\b(hospital|clinic|clinica|centro|urgencia|saude|health|doctor|medical)\b/.test(t) ||
      row.hint.length >= 8
    );
  });
}

function looksLikeMedicalFacilityRatingQuery(text) {
  const t = foldAccents(text);
  return (
    /\b(rating|score|how good|reviews?|worth it|recommend)\b/.test(t) &&
    /\b(hospital|clinic|clinica|centro de saude|health centre|urgencia|doctor|medical)\b/.test(
      t
    )
  );
}

function detectNeed(text) {
  const t = foldAccents(text);
  const acuteInjury =
    /\b(cut|cuts|wound|bleed|bleeding|gash|laceration|stitch|head|scalp|hurt|injured|injury|accident|burn|broken)\b/.test(
      t
    );
  const lifeThreatening =
    /\b(life.?threat|cant breathe|can't breathe|unconscious|heart attack|severe bleed|won't stop bleeding|wont stop bleeding|112)\b/.test(
      t
    ) ||
    /\b(serious injury|not breathing|cardiac|heavy bleeding|deep cut)\b/.test(t);
  return {
    lifeThreatening,
    acuteInjury,
    adviceLine:
      /\b(sns\s*24|808|medical advice|triage|should i go|speak to a nurse|telephone advice)\b/.test(
        t
      ) || (acuteInjury && /\b(should i|not sure|worried|what do i)\b/.test(t)),
    emergency:
      acuteInjury ||
      (/\b(emergency|urgencia|urgência|accident|injury|ambulance|broken bone|stitch)\b/.test(t) &&
        !/\b(routine|vaccin|gp appointment)\b/.test(t)),
    paediatric:
      /\b(child|kid|baby|paediatr|pediatr|infant|toddler|daughter|son|year old|yr old)\b/.test(
        t
      ),
    routine:
      /\b(vaccin|gp|routine|family doctor|check.?up|nursing|centro de saude|health centre)\b/.test(
        t
      ),
    private: /\b(private|particular|cuf|pay)\b/.test(t),
    hospital: /\b(hospital)\b/.test(t),
  };
}

function townsMentioned(text) {
  const t = foldAccents(text);
  const towns = [
    "ourique",
    "garvao",
    "panoias",
    "santa luzia",
    "santana da serra",
    "castro verde",
    "almodovar",
    "aljustrel",
    "odemira",
    "beja",
    "santiago do cacem",
    "sao martinho das amoreiras",
  ];
  return towns.filter((town) => t.includes(town));
}

function facilityServicesList(f) {
  const raw = f.services || "";
  if (Array.isArray(raw)) return raw;
  return String(raw)
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
}

function scoreFacilityForQuery(f, { need, towns }) {
  let score = 10;
  const cat = foldAccents(f.category || "");
  const services = facilityServicesList(f).map(foldAccents);
  const town = foldAccents(f.town || "");

  if (need.lifeThreatening || need.emergency) {
    if (cat.includes("emergency number") && f.phone === "112") score += 200;
    if (cat.includes("hospital")) score += 90;
    if (cat.includes("basic emergency")) score += 70;
    if (cat.includes("private clinic")) score -= 55;
    if (cat.includes("health extension")) score -= 30;
    if (need.paediatric && cat.includes("hospital") && town === "beja") score += 35;
    if (need.paediatric && cat.includes("basic emergency")) score += 25;
  }

  if (need.adviceLine && !need.lifeThreatening) {
    if (f.phone && f.phone.includes("808")) score += 150;
    if (cat.includes("emergency number")) score += 40;
  }

  if (need.routine && !need.emergency) {
    if (cat.includes("health centre") || cat.includes("health extension")) score += 80;
    if (cat.includes("emergency number")) score -= 20;
    if (cat.includes("hospital")) score -= 10;
    if (need.private && cat.includes("private")) score += 60;
    if (need.private && !cat.includes("private")) score -= 20;
  }

  if (need.paediatric) {
    if (services.some((s) => s.includes("paediatr"))) score += 55;
    if (/\bpaediatr|\bpediatr|\bcrianca\b/.test(foldAccents(f.name))) score += 40;
  }

  if (need.hospital && cat.includes("hospital")) score += 70;

  if (need.private && cat.includes("private")) score += 50;

  const nearOurique = towns.some((t) => NEAR_OURIQUE_TOWNS.has(t));
  if (need.emergency && nearOurique) {
    if (cat.includes("basic emergency")) score += 35;
    if (cat.includes("hospital") && town === "beja") score += 40;
    if (cat.includes("hospital") && town === "santiago do cacem") score -= 30;
  }

  for (const mentioned of towns) {
    const m = foldAccents(mentioned);
    if (town === m || town.includes(m) || m.includes(town)) score += 65;
    else if (NEAR_OURIQUE_TOWNS.has(m) && NEAR_OURIQUE_TOWNS.has(town)) score += 25;
  }

  if (!towns.length && (need.routine || need.emergency)) {
    if (town === "ourique" || town === "odemira" || town === "castro verde") score += 15;
  }

  if (f.ratingAvg != null && f.ratingCount > 0) score += Math.min(15, f.ratingAvg);

  return score;
}

function formatRatingSummary(f) {
  if (!f.ratingCount) return "";
  return `★ ${f.ratingAvg}/10 (${f.ratingCount} review${f.ratingCount === 1 ? "" : "s"})`;
}

function formatFacilityLine(f, { includePhone = true } = {}) {
  const bits = [`<b>${f.name}</b>`];
  if (f.category) bits.push(`(${f.category})`);
  if (f.location) bits.push(`\n📍 ${f.location}`);
  if (includePhone && f.phone) bits.push(`\n☎ ${f.phone}`);
  if (f.typeDetail) bits.push(`\n${f.typeDetail}`);
  const rating = formatRatingSummary(f);
  if (rating) bits.push(`\n${rating}`);
  return bits.join(" ");
}

async function answerMedicalQuestion(text) {
  const catalog = await loadFacilityCatalog();
  const need = detectNeed(text);
  const towns = townsMentioned(text);

  if (looksLikeMedicalFacilityRatingQuery(text)) {
    const ranked = [...catalog]
      .map((f) => ({ f, score: scoreFacilityNameMatch(text, f) + scoreFacilityForQuery(f, { need, towns }) }))
      .filter((row) => row.score >= 50)
      .sort((a, b) => b.score - a.score);
    const target = ranked[0]?.f;
    if (target) {
      const rating = formatRatingSummary(target);
      return {
        answered: true,
        answer: rating
          ? `${target.name}: ${rating}`
          : `${target.name}: no community reviews yet — rate it in chat, e.g. <code>${target.name.split(" ")[0]}: 8</code> (1–10).`,
      };
    }
  }

  const ranked = [...catalog]
    .map((f) => ({ f, score: scoreFacilityForQuery(f, { need, towns }) }))
    .sort((a, b) => b.score - a.score);

  let picks = ranked.filter((row) => row.score >= 30).slice(0, 5);
  if (!picks.length) picks = ranked.slice(0, 4);

  const lines = [];

  if (need.lifeThreatening) {
    lines.push("<b>Life-threatening emergency → call 112</b> (INEM).");
  } else if (need.paediatric && need.acuteInjury) {
    lines.push(
      "<b>Head wound in a young child:</b> if bleeding won't stop, they're very drowsy, or you're unsure how serious it is → <b>call 112</b>. " +
        "Otherwise go to the nearest <b>hospital emergency</b> (paediatric) or a <b>Serviço de Urgência Básica</b> — SUB can treat first but may send you to a larger hospital. " +
        "Unsure? <b>SNS 24: 808 24 24 24</b>."
    );
  } else if (need.emergency) {
    lines.push(
      "<b>Serious emergency → 112</b>. For urgent but not life-threatening cases, a Serviço de Urgência Básica or hospital emergency department may apply — SUB units are not full hospitals and may transfer you."
    );
  } else if (need.adviceLine) {
    lines.push("<b>Medical advice / triage → SNS 24: 808 24 24 24</b> (adults and children).");
  }

  lines.push("", "<b>Suggestions for your area</b>");
  for (const { f } of picks) {
    if (f.category === "Emergency number" && !need.lifeThreatening && !need.adviceLine && f.phone === "112") {
      continue;
    }
    lines.push("", formatFacilityLine(f));
  }

  const hasSub = picks.some((row) => foldAccents(row.f.category || "").includes("basic emergency"));
  if (hasSub && (need.emergency || need.lifeThreatening)) {
    lines.push(
      "",
      "<i>Reminder: Serviço de Urgência Básica gives initial emergency care but is not the same as a full hospital.</i>"
    );
  }

  lines.push(
    "",
    "Rate a place (1–10): <code>Hospital José Joaquim Fernandes: 8</code> or <code>Centro de Saúde de Ourique: 9</code>."
  );

  return { answered: true, answer: lines.join("\n") };
}

function aggregatesFromRatingPages(pages) {
  const byKey = new Map();
  for (const page of pages) {
    const key =
      notion.pagePropText(page, "Facility key").trim() ||
      normalizeKey(notion.pagePropText(page, "Facility"));
    const score = Number(notion.pagePropText(page, "Score"));
    if (!key || !Number.isFinite(score) || score < 1 || score > 10) continue;
    const display = notion.pagePropText(page, "Facility").trim() || key;
    if (!byKey.has(key)) byKey.set(key, { name: display, scores: [] });
    byKey.get(key).scores.push(score);
  }
  const out = new Map();
  for (const [key, row] of byKey) {
    const sum = row.scores.reduce((a, b) => a + b, 0);
    out.set(key, {
      name: row.name,
      avg: Math.round((sum / row.scores.length) * 10) / 10,
      count: row.scores.length,
    });
  }
  return out;
}

async function loadRatingAggregates({ force = false } = {}) {
  if (!force && Date.now() - aggregateCache.at < CACHE_MS) {
    return aggregateCache.byKey;
  }
  try {
    const { pages } = await notion.queryDatabase(RATINGS_DB_NAME);
    aggregateCache = { at: Date.now(), byKey: aggregatesFromRatingPages(pages) };
  } catch {
    aggregateCache = { at: Date.now(), byKey: new Map() };
  }
  return aggregateCache.byKey;
}

async function syncAggregateToFacility(name, key, agg) {
  if (!agg?.count) return;
  try {
    const pages = await loadFacilityPages();
    for (const page of pages) {
      const pageKey = normalizeKey(notion.pageTitle(page));
      if (pageKey !== key) continue;
      const props = {};
      if (agg.avg != null) props[AVG_PROP] = agg.avg;
      props[COUNT_PROP] = agg.count;
      await notion.updatePageProps(FACILITIES_DB_NAME, page.id, props);
    }
  } catch {
    // columns may be missing until setup runs
  }
}

async function recordMedicalFacilityRating({ name, key, score, reviewer, comment = "" }) {
  const title = `${name} — ${reviewer}`.slice(0, 180);
  await notion.createPage({
    databaseName: RATINGS_DB_NAME,
    title,
    properties: {
      Facility: name,
      "Facility key": key,
      Score: score,
      Reviewer: reviewer,
      Date: todayISO(),
      Source: "Telegram",
      ...(comment ? { Notes: comment } : {}),
    },
  });
  aggregateCache.at = 0;
  const aggregates = await loadRatingAggregates({ force: true });
  const agg = aggregates.get(key) || { avg: score, count: 1 };
  await syncAggregateToFacility(name, key, agg);
  return agg;
}

async function recordMedicalFacilityRatingsFromMessage({ text, reviewer }) {
  const catalog = await loadFacilityCatalog();
  const parsed = parseMedicalRatingsFromText(text);
  const results = [];
  for (const { hint, score } of parsed) {
    if (!ratingHintLooksMedical(hint, catalog)) continue;
    const target = resolveRatingTarget(hint, catalog);
    const agg = await recordMedicalFacilityRating({
      name: target.name,
      key: target.key,
      score,
      reviewer,
    });
    results.push({
      hint,
      name: target.name,
      score,
      unknown: target.unknown,
      avg: agg.avg,
      count: agg.count,
    });
  }
  return results;
}

function hubBlocks() {
  return [
    {
      object: "block",
      type: "heading_2",
      heading_2: { rich_text: notion.richText("Emergency & healthcare nearby") },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          "Directory for Baixo Alentejo / around Vila Akoya. VAVA recommends facilities by need (emergency, GP, paediatrics, private). Life-threatening → 112. Medical advice line → SNS 24 (808 24 24 24)."
        ),
      },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          "Serviço de Urgência Básica (SUB) is not a full hospital — it may stabilise and transfer. Community ratings in Telegram sync here (1–10)."
        ),
      },
    },
  ];
}

async function ensureSection(parentId, catalog) {
  if (catalog.pages?.[HUB_PAGE_TITLE]) {
    return { id: catalog.pages[HUB_PAGE_TITLE], title: HUB_PAGE_TITLE };
  }
  const found = await notion.searchByTitle(HUB_PAGE_TITLE, "page");
  if (found) {
    catalog.pages[HUB_PAGE_TITLE] = found.id;
    return { id: found.id, title: HUB_PAGE_TITLE };
  }
  const page = await notion.createChildPage({
    parentPageId: parentId,
    title: HUB_PAGE_TITLE,
    children: hubBlocks(),
  });
  catalog.pages[HUB_PAGE_TITLE] = page.id;
  return page;
}

function seedRowProperties(row) {
  return {
    Location: row.location || "",
    Category: row.category,
    "Type detail": row.typeDetail || "",
    Phone: row.phone || "",
    "When to use": row.whenToUse || "",
    Town: row.town || "",
    Services: row.services || [],
    Notes: row.notes || "",
    Status: "Active",
  };
}

async function upsertSeedFacilities({ syncAll = false } = {}) {
  const { pages } = await notion.queryDatabase(FACILITIES_DB_NAME);
  const byName = new Map(
    pages.map((p) => [normalizeKey(notion.pageTitle(p)), p])
  );
  let created = 0;
  let updated = 0;
  for (const row of SEED_FACILITIES) {
    const key = normalizeKey(row.name);
    const existing = byName.get(key);
    const props = seedRowProperties(row);
    if (existing) {
      if (syncAll) {
        await notion.updatePageProps(FACILITIES_DB_NAME, existing.id, props);
        updated += 1;
      }
    } else {
      await notion.createPage({
        databaseName: FACILITIES_DB_NAME,
        title: row.name,
        properties: props,
      });
      created += 1;
    }
  }
  return { created, updated };
}

async function ensureRatingsDatabase(parentPageId, catalog) {
  catalog.databases = catalog.databases || {};
  const db = await notion.createDatabase({
    parentPageId,
    title: RATINGS_DB_NAME,
    properties: RATINGS_PROPERTIES,
  });
  catalog.databases[RATINGS_DB_NAME] = db.id;
  await notion.primeDatabase(RATINGS_DB_NAME, db.id);
  return db;
}

async function ensureMedicalFacilitiesDatabase({ syncAllSeed = false } = {}) {
  const catalog = notion.loadCatalog();
  catalog.databases = catalog.databases || {};
  catalog.pages = catalog.pages || {};

  const parentId =
    catalog.pages?.["Documentation hub"] || catalog.parentPageId;
  if (!parentId) {
    throw new Error(
      "No Documentation hub / parent page in the Notion catalog. Run setup-vila-akoya-notion.js first."
    );
  }

  const section = await ensureSection(parentId, catalog);
  notion.saveCatalog(catalog);

  const db = await notion.createDatabase({
    parentPageId: section.id,
    title: FACILITIES_DB_NAME,
    properties: FACILITIES_PROPERTIES,
  });
  catalog.databases[FACILITIES_DB_NAME] = db.id;
  await notion.primeDatabase(FACILITIES_DB_NAME, db.id);

  try {
    await notion.notion("PATCH", `/databases/${db.id}`, {
      properties: FACILITIES_PROPERTIES,
    });
    notion.clearDatabaseCache(FACILITIES_DB_NAME);
    await notion.primeDatabase(FACILITIES_DB_NAME, db.id);
  } catch (err) {
    if (!/already exists/i.test(err.message)) {
      console.warn(`Medical facilities property patch: ${err.message}`);
    }
  }

  await ensureRatingsDatabase(section.id, catalog);
  notion.saveCatalog(catalog);

  const seed = await upsertSeedFacilities({ syncAll: syncAllSeed });

  try {
    await notion.ensureTableView(db.id, { name: "All facilities" });
  } catch (err) {
    console.warn(`Medical facilities view skipped: ${err.message}`);
  }

  notion.saveCatalog(catalog);

  return {
    db,
    pageId: section.id,
    url: db.url,
    seeded: seed.created,
    updated: seed.updated,
    wasCreated: db.wasCreated,
  };
}

module.exports = {
  FACILITIES_DB_NAME,
  RATINGS_DB_NAME,
  HUB_PAGE_TITLE,
  AVG_PROP,
  COUNT_PROP,
  SEED_FACILITIES,
  looksLikeMedicalFacilityQuestion,
  looksLikeMedicalFacilityRatingMessage,
  looksLikeMedicalFacilityRatingQuery,
  parseMedicalRatingsFromText,
  answerMedicalQuestion,
  recordMedicalFacilityRatingsFromMessage,
  loadFacilityCatalog,
  formatFacilityLine,
  ensureMedicalFacilitiesDatabase,
  ratingHintLooksMedical,
};
