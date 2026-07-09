
/**
 * Vila Akoya monday.com setup script
 *
 * What it does:
 * - Creates/reuses Vila Akoya boards
 * - Creates missing groups
 * - Creates missing columns
 * - Creates sample items only when a board is newly created
 * - Handles monday.com complexity budget errors with retry/backoff
 * - Can resume from a specific board
 *
 * Requirements:
 * - Node.js 18+
 *
 * Run:
 * MONDAY_API_TOKEN="your_token_here" \
 * MONDAY_WORKSPACE_ID="123456" \
 * node setup-vila-akoya-monday.js
 *
 * Optional:
 * MONDAY_API_VERSION="2026-10"
 * START_AT_BOARD_NAME="30 — Campsite & Guest Issues"
 * INCLUDE_SAMPLE_ITEMS="false"
 * INCLUDE_DOC_FOLDERS="false"   (skip creating the 📚 Documentation folders)
 * PACING_MS="1000"
 */



const MONDAY_API_TOKEN = 'eyJhbGciOiJIUzI1NiJ9.eyJ0aWQiOjY3NzQ0MDk2NywiYWFpIjoxMSwidWlkIjoxMDcyNzM0NDIsImlhZCI6IjIwMjYtMDctMDFUMDk6NDU6NTYuMDAwWiIsInBlciI6Im1lOndyaXRlIiwiYWN0aWQiOjM1ODIxNzEyLCJyZ24iOiJldWMxIn0.fUMN4Zpvsm7hOx8xyo4Qb76Ovi_tDs-7nhhdu8ZIyEg';
const WORKSPACE_ID = 6923231;
const MONDAY_API_VERSION = process.env.MONDAY_API_VERSION || "2026-10";
const START_AT_BOARD_NAME = process.env.START_AT_BOARD_NAME || "";
const INCLUDE_SAMPLE_ITEMS = process.env.INCLUDE_SAMPLE_ITEMS !== "false";
const INCLUDE_DOC_FOLDERS = process.env.INCLUDE_DOC_FOLDERS !== "false";
const PACING_MS = Number(process.env.PACING_MS || 1000);

const API_URL = "https://api.monday.com/v2";

if (!MONDAY_API_TOKEN) {
  throw new Error("Missing MONDAY_API_TOKEN environment variable.");
}

if (!WORKSPACE_ID) {
  throw new Error("Missing MONDAY_WORKSPACE_ID environment variable.");
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function monday(query, variables = {}, attempt = 1) {
  const res = await fetch(API_URL, {
    method: "POST",
    headers: {
      Authorization: MONDAY_API_TOKEN,
      "Content-Type": "application/json",
      "API-Version": MONDAY_API_VERSION,
    },
    body: JSON.stringify({ query, variables }),
  });

  const json = await res.json();

  const complexityError = json.errors?.find(
    (err) => err.extensions?.code === "COMPLEXITY_BUDGET_EXHAUSTED"
  );

  if (complexityError) {
    const retrySeconds =
      Number(complexityError.extensions?.retry_in_seconds || 0) ||
      Math.min(60, attempt * 5);

    console.warn(
      `Complexity budget exhausted. Waiting ${retrySeconds + 2}s before retrying...`
    );

    await sleep((retrySeconds + 2) * 1000);

    if (attempt >= 10) {
      console.error(JSON.stringify(json, null, 2));
      throw new Error("monday.com API complexity retry limit reached.");
    }

    return monday(query, variables, attempt + 1);
  }

  if (json.errors) {
    console.error(JSON.stringify(json, null, 2));
    throw new Error("monday.com API request failed.");
  }

  if (!res.ok) {
    console.error(JSON.stringify(json, null, 2));
    throw new Error(`HTTP error from monday.com: ${res.status}`);
  }

  await sleep(PACING_MS);
  return json.data;
}

async function listBoardsInWorkspace() {
  const query = `
    query ListBoards($workspaceIds: [ID!]) {
      boards(limit: 500, workspace_ids: $workspaceIds) {
        id
        name
        state
        workspace {
          id
          name
        }
      }
    }
  `;

  const data = await monday(query, {
    workspaceIds: [WORKSPACE_ID],
  });

  return data.boards || [];
}

async function getBoardByName(boardName) {
  const boards = await listBoardsInWorkspace();
  return boards.find((board) => board.name === boardName && board.state !== "archived");
}

async function getBoardDetails(boardId) {
  const query = `
    query GetBoardDetails($boardIds: [ID!]) {
      boards(ids: $boardIds) {
        id
        name
        groups {
          id
          title
          deleted
          archived
        }
        columns {
          id
          title
          type
          archived
        }
      }
    }
  `;

  const data = await monday(query, {
    boardIds: [String(boardId)],
  });

  const board = data.boards?.[0];

  if (!board) {
    throw new Error(`Could not read board details for board ID ${boardId}`);
  }

  return board;
}

async function createBoard(name) {
  const existingBoard = await getBoardByName(name);

  if (existingBoard) {
    console.log(`Reusing existing board: ${name} (${existingBoard.id})`);
    return {
      id: existingBoard.id,
      name: existingBoard.name,
      wasCreated: false,
    };
  }

  const query = `
    mutation CreateBoard($name: String!, $workspaceId: ID!) {
      create_board(
        board_name: $name,
        board_kind: public,
        workspace_id: $workspaceId
      ) {
        id
        name
      }
    }
  `;

  const data = await monday(query, {
    name,
    workspaceId: WORKSPACE_ID,
  });

  console.log(`Created board: ${data.create_board.name} (${data.create_board.id})`);

  return {
    id: data.create_board.id,
    name: data.create_board.name,
    wasCreated: true,
  };
}

async function createGroup(boardId, groupName, existingGroups) {
  const existing = existingGroups.find(
    (group) =>
      group.title === groupName &&
      !group.deleted &&
      !group.archived
  );

  if (existing) {
    console.log(`  Reusing group: ${groupName}`);
    return existing;
  }

  const query = `
    mutation CreateGroup($boardId: ID!, $groupName: String!) {
      create_group(board_id: $boardId, group_name: $groupName) {
        id
        title
      }
    }
  `;

  const data = await monday(query, {
    boardId: String(boardId),
    groupName,
  });

  console.log(`  Created group: ${data.create_group.title}`);
  return data.create_group;
}

async function createColumn(boardId, title, type, existingColumns) {
  const existing = existingColumns.find(
    (column) =>
      column.title === title &&
      column.type === type &&
      !column.archived
  );

  if (existing) {
    console.log(`  Reusing column: ${title} (${type})`);
    return existing;
  }

  const query = `
    mutation CreateColumn($boardId: ID!, $title: String!, $type: ColumnType!) {
      create_column(
        board_id: $boardId,
        title: $title,
        column_type: $type
      ) {
        id
        title
        type
      }
    }
  `;

  const data = await monday(query, {
    boardId: String(boardId),
    title,
    type,
  });

  console.log(`  Created column: ${data.create_column.title} (${data.create_column.type})`);
  return data.create_column;
}

async function createItem(boardId, groupId, itemName) {
  const query = `
    mutation CreateItem($boardId: ID!, $groupId: String!, $itemName: String!) {
      create_item(
        board_id: $boardId,
        group_id: $groupId,
        item_name: $itemName
      ) {
        id
        name
      }
    }
  `;

  const data = await monday(query, {
    boardId: String(boardId),
    groupId,
    itemName,
  });

  console.log(`  Created sample item: ${data.create_item.name}`);
  return data.create_item;
}

async function listFolders() {
  const query = `
    query ListFolders($workspaceIds: [ID!]) {
      folders(workspace_ids: $workspaceIds, limit: 100) {
        id
        name
        parent {
          id
        }
      }
    }
  `;

  const data = await monday(query, {
    workspaceIds: [String(WORKSPACE_ID)],
  });

  return data.folders || [];
}

async function createFolder(name, parentFolderId, existingFolders) {
  const parentId = parentFolderId ? String(parentFolderId) : null;

  const existing = existingFolders.find(
    (folder) =>
      folder.name === name &&
      (folder.parent?.id ? String(folder.parent.id) : null) === parentId
  );

  if (existing) {
    console.log(`  Reusing folder: ${name}`);
    return existing;
  }

  const query = `
    mutation CreateFolder($workspaceId: ID!, $name: String!, $parentId: ID) {
      create_folder(
        workspace_id: $workspaceId,
        name: $name,
        parent_folder_id: $parentId
      ) {
        id
        name
      }
    }
  `;

  const data = await monday(query, {
    workspaceId: String(WORKSPACE_ID),
    name,
    parentId,
  });

  console.log(`  Created folder: ${data.create_folder.name}`);
  return data.create_folder;
}

// Sidebar layout for the knowledge base. Subfolders mirror the classifier
// areas (see scripts/lib/openai.js) so docs and tasks share a vocabulary.
const documentationFolder = {
  name: "📚 Documentation",
  subfolders: [
    "Water",
    "Compost & toilets",
    "Solar / electric",
    "Buildings / structures",
    "Tools / workshop",
    "Land / roads / drainage",
    "Campsite / guest",
    "Garden",
    "Community / events",
    "Safety & emergency",
    "Admin / money",
  ],
};

async function setupDocumentationFolders() {
  console.log(`\n=== ${documentationFolder.name} (folders) ===`);

  let folders = await listFolders();
  const parent = await createFolder(documentationFolder.name, null, folders);

  folders = await listFolders();
  for (const subName of documentationFolder.subfolders) {
    await createFolder(subName, parent.id, folders);
    folders = await listFolders();
  }

  return parent;
}

const boardConfigs = [
  {
    name: "00 — Task Inbox",
    purpose:
      "All Telegram, WhatsApp, form, meeting and manual brain dumps land here before being reviewed.",
    groups: [
      "New brain dumps",
      "Needs review",
      "Accepted tasks",
      "Rejected / not a task",
      "Documentation gap",
      "Done",
    ],
    columns: [
      ["Status", "status"],
      ["Priority", "status"],
      ["Area", "status"],
      ["Steward", "people"],
      ["Doer name", "text"],
      ["Due date", "date"],
      ["Source", "status"],
      ["Telegram user", "text"],
      ["Telegram chat ID", "text"],
      ["Safety critical?", "checkbox"],
      ["Visitor visible?", "checkbox"],
      ["Needs money?", "checkbox"],
      ["Needs materials?", "checkbox"],
      ["Estimated effort", "text"],
      ["Photo / file", "file"],
      ["Linked documentation", "link"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Example: lower compost toilet smells and sawdust is empty",
      "Example: document what the red valve does on water system",
      "Example: visitor says shower block light is broken",
    ],
  },
  {
    name: "10 — Recurring Duties",
    purpose:
      "Repeated duties that keep the village and campsite running: checks, cleaning, inspections and seasonal tasks.",
    groups: ["Daily", "Weekly", "Monthly", "Seasonal", "Done / logged"],
    columns: [
      ["Status", "status"],
      ["Frequency", "status"],
      ["Area", "status"],
      ["Steward", "people"],
      ["Doer name", "text"],
      ["Next due date", "date"],
      ["Last completed", "date"],
      ["Safety critical?", "checkbox"],
      ["Linked documentation", "link"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Check compost sawdust levels",
      "Check water pressure and visible leaks",
      "Clean guest shower block",
      "Check campsite bins",
      "Inspect fire safety points",
    ],
  },
  {
    name: "20 — Maintenance & Infrastructure",
    purpose:
      "Physical systems and repairs: water, compost, solar, buildings, tools, land and access routes.",
    groups: [
      "Water",
      "Compost & toilets",
      "Solar / electric",
      "Buildings / structures",
      "Tools / workshop",
      "Land / roads / drainage",
      "Done",
    ],
    columns: [
      ["Status", "status"],
      ["Priority", "status"],
      ["Steward", "people"],
      ["Doer name", "text"],
      ["Due date", "date"],
      ["Needs materials?", "checkbox"],
      ["Estimated cost", "numbers"],
      ["Safety critical?", "checkbox"],
      ["Photo / file", "file"],
      ["Linked documentation", "link"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Inspect red valve and label it clearly",
      "Create simple map of water shutoff points",
      "Check compost chamber airflow",
    ],
  },
  {
    name: "30 — Campsite & Guest Issues",
    purpose:
      "Visitor-facing campsite issues, guest reports, cleaning, facilities, pitches, booking/check-in and feedback.",
    groups: [
      "New guest issues",
      "Cleaning",
      "Facilities",
      "Pitch / camping area",
      "Check-in / booking",
      "Guest feedback",
      "Done",
    ],
    columns: [
      ["Status", "status"],
      ["Guest impact", "status"],
      ["Priority", "status"],
      ["Steward", "people"],
      ["Doer name", "text"],
      ["Due date", "date"],
      ["Visitor visible?", "checkbox"],
      ["Source", "status"],
      ["Telegram user", "text"],
      ["Photo / file", "file"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Guest reports broken light near shower block",
      "Pitch 3 needs clearing before next arrival",
      "Add clearer sign for compost toilet sawdust",
    ],
  },
  {
    name: "40 — Village Projects",
    purpose:
      "Bigger build, creative, social, garden and community projects that need planning and shared visibility.",
    groups: [
      "Ideas",
      "Approved",
      "Planning",
      "In progress",
      "Waiting for money/materials",
      "Done",
    ],
    columns: [
      ["Status", "status"],
      ["Project type", "status"],
      ["Priority", "status"],
      ["Lead steward", "people"],
      ["Helpers / doers", "text"],
      ["Target date", "date"],
      ["Estimated cost", "numbers"],
      ["Needs community decision?", "checkbox"],
      ["Linked documentation", "link"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Build shaded seating area near campsite",
      "Design welcome board for visitors",
      "Plan weekly shared meal system",
    ],
  },
  {
    name: "50 — Documentation Register",
    purpose:
      "Index of official docs, manuals, community agreements, emergency procedures and documentation gaps.",
    groups: [
      "Infrastructure docs",
      "Guest / campsite docs",
      "Community agreements",
      "Safety / emergency docs",
      "Drafts",
      "Needs review",
      "Approved docs",
    ],
    columns: [
      ["Doc status", "status"],
      ["Area", "status"],
      ["Owner / steward", "people"],
      ["Last reviewed", "date"],
      ["Review frequency", "status"],
      ["Criticality", "status"],
      ["Linked workdoc", "link"],
      ["Related tasks", "long_text"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Water System — Valve Map",
      "Compost Toilet — Daily Use & Troubleshooting",
      "Guest Check-in Process",
      "Emergency Contacts & First Response",
      "Community Decision-Making Agreement",
    ],
  },
  {
    name: "60 — Steward Dashboard",
    purpose:
      "High-level accountability board showing who owns each area and what needs attention.",
    groups: [
      "Core stewards",
      "Backup stewards",
      "Needs steward",
      "Inactive / archived",
    ],
    columns: [
      ["Area", "status"],
      ["Primary steward", "people"],
      ["Backup steward", "people"],
      ["Non-Monday contact name", "text"],
      ["WhatsApp / Telegram", "text"],
      ["Current focus", "long_text"],
      ["Open issues", "numbers"],
      ["Review date", "date"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Water steward",
      "Compost steward",
      "Guest / campsite steward",
      "Garden steward",
      "Community / events steward",
    ],
  },
  {
    name: "70 — Community Members",
    purpose:
      "Directory of residents, volunteers and helpers, including Telegram handles used by the bot to route tasks and notifications.",
    groups: [
      "Residents",
      "Long-term volunteers",
      "Short-term helpers / guests",
      "Onboarding",
      "Left / inactive",
    ],
    columns: [
      ["Member status", "status"],
      ["Role / focus", "status"],
      ["Monday user", "people"],
      ["Display name", "text"],
      ["Telegram username", "text"],
      ["Telegram chat ID", "text"],
      ["Phone / contact", "text"],
      ["Areas of help", "status"],
      ["Active?", "checkbox"],
      ["Joined date", "date"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Example resident — @telegram_handle",
      "Example volunteer — @telegram_handle",
    ],
  },
  {
    name: "80 — Events & Gatherings",
    purpose:
      "Shared meals, work parties, workshops, celebrations and community gatherings that can be announced to Telegram.",
    groups: [
      "Ideas",
      "Planning",
      "Upcoming",
      "Recurring",
      "Done / past",
    ],
    columns: [
      ["Status", "status"],
      ["Event type", "status"],
      ["Date", "date"],
      ["Organiser", "people"],
      ["Helpers / doers", "text"],
      ["Location", "text"],
      ["Needs volunteers?", "checkbox"],
      ["Announce on Telegram?", "checkbox"],
      ["Linked documentation", "link"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Weekly shared meal",
      "Monthly work party",
      "Seasonal celebration",
    ],
  },
  {
    name: "90 — Decisions & Proposals",
    purpose:
      "Community proposals and governance: things that need discussion, consent or a vote before they happen.",
    groups: [
      "Proposed",
      "In discussion",
      "Needs vote",
      "Decided",
      "Rejected / parked",
    ],
    columns: [
      ["Status", "status"],
      ["Category", "status"],
      ["Proposed by", "text"],
      ["Facilitator", "people"],
      ["Needs vote?", "checkbox"],
      ["Decision date", "date"],
      ["Outcome", "status"],
      ["Linked documentation", "link"],
      ["Notes", "long_text"],
    ],
    samples: [
      "Proposal: agree quiet hours for the campsite",
      "Proposal: shared budget for garden tools",
      "Proposal: process for welcoming new volunteers",
    ],
  },
];

async function setupBoard(boardConfig) {
  console.log(`\n=== ${boardConfig.name} ===`);

  const board = await createBoard(boardConfig.name);

  let boardDetails = await getBoardDetails(board.id);

  const createdGroups = [];
  for (const groupName of boardConfig.groups) {
    const group = await createGroup(board.id, groupName, boardDetails.groups || []);
    createdGroups.push(group);

    boardDetails = await getBoardDetails(board.id);
  }

  boardDetails = await getBoardDetails(board.id);

  const createdColumns = [];
  for (const [title, type] of boardConfig.columns) {
    const column = await createColumn(
      board.id,
      title,
      type,
      boardDetails.columns || []
    );
    createdColumns.push(column);

    boardDetails = await getBoardDetails(board.id);
  }

  if (INCLUDE_SAMPLE_ITEMS && board.wasCreated && boardConfig.samples?.length) {
    const firstGroup = createdGroups[0];

    if (!firstGroup?.id) {
      console.warn(`  Could not create sample items for ${boardConfig.name}: no group found.`);
    } else {
      for (const sample of boardConfig.samples) {
        await createItem(board.id, firstGroup.id, sample);
      }
    }
  } else if (INCLUDE_SAMPLE_ITEMS && !board.wasCreated) {
    console.log("  Skipping sample items because board already existed.");
  }

  return {
    name: board.name,
    id: board.id,
    wasCreated: board.wasCreated,
    groups: createdGroups.map((group) => ({
      id: group.id,
      title: group.title,
    })),
    columns: createdColumns.map((column) => ({
      id: column.id,
      title: column.title,
      type: column.type,
    })),
  };
}

async function setup() {
  console.log("Starting Vila Akoya monday.com setup...");
  console.log(`Workspace ID: ${WORKSPACE_ID}`);
  console.log(`API version: ${MONDAY_API_VERSION}`);
  console.log(`Include sample items: ${INCLUDE_SAMPLE_ITEMS}`);
  console.log(`Pacing: ${PACING_MS}ms`);
  if (START_AT_BOARD_NAME) {
    console.log(`Starting at board: ${START_AT_BOARD_NAME}`);
  }

  if (INCLUDE_DOC_FOLDERS) {
    await setupDocumentationFolders();
  }

  const results = [];

  let hasReachedStartBoard = !START_AT_BOARD_NAME;

  for (const boardConfig of boardConfigs) {
    if (!hasReachedStartBoard && boardConfig.name === START_AT_BOARD_NAME) {
      hasReachedStartBoard = true;
    }

    if (!hasReachedStartBoard) {
      console.log(`Skipping board: ${boardConfig.name}`);
      continue;
    }

    const result = await setupBoard(boardConfig);
    results.push(result);
  }

  console.log("\nDONE. Vila Akoya monday.com setup result:");
  console.log(JSON.stringify(results, null, 2));

  console.log("\nSuggested next manual steps:");
  console.log("1. Customise status labels in each board.");
  console.log("2. Add real stewards as paid Monday users.");
  console.log("3. Use Doer name for non-seat villagers/helpers.");
  console.log("4. Add board views: By Area, By Steward, Urgent, Guest Issues.");
  console.log("5. Register members (with Telegram handles) on '70 — Community Members'.");
  console.log("6. Start the Telegram bridge: see scripts/vila-akoya-telegram-bot.js");
}

setup().catch((err) => {
  console.error("\nSetup failed:");
  console.error(err);
  process.exit(1);
});