/**
 * Shared helpers so event pages feel like hubs (docs + assignable tasks),
 * not thin calendar rows.
 */

"use strict";

const notion = require("./notion");

function eventBodyBlocks({ title, isSeries, seriesUrl, recurrenceLabel }) {
  const blocks = [
    {
      object: "block",
      type: "callout",
      callout: {
        icon: { type: "emoji", emoji: isSeries ? "📌" : "📅" },
        rich_text: notion.richText(
          isSeries
            ? "Event hub — write docs below, add tasks via the Tasks property (or ask VAVA). Calendar dates are separate occurrence pages linked here."
            : "Occurrence page — guest list & notes for this date. Lasting docs and shared tasks live on the series hub."
        ),
      },
    },
  ];

  if (!isSeries && seriesUrl) {
    blocks.push({
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: [
          {
            type: "text",
            text: { content: "Open series hub → ", link: null },
          },
          {
            type: "text",
            text: { content: seriesUrl, link: { url: seriesUrl } },
          },
        ],
      },
    });
  }

  if (recurrenceLabel) {
    blocks.push({
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(`Recurrence: ${recurrenceLabel}`),
      },
    });
  }

  blocks.push(
    {
      object: "block",
      type: "heading_2",
      heading_2: { rich_text: notion.richText("Overview") },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          isSeries
            ? `What “${title}” is, who it’s for, and anything guests should know.`
            : "Notes for this date (who’s cooking, special setup, rain plan…)."
        ),
      },
    },
    {
      object: "block",
      type: "heading_2",
      heading_2: { rich_text: notion.richText("Documentation") },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          isSeries
            ? "Paste guides, menus, setup checklists, links to Documentation hub pages…"
            : "Date-specific docs only — prefer putting lasting guides on the series hub."
        ),
      },
    },
    {
      object: "block",
      type: "heading_2",
      heading_2: { rich_text: notion.richText("Tasks") },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          "Use the Tasks property on this page (relates to the Tasks database). Each task can have Assignee, Status, Priority, Type (villager/management). Or tell VAVA: “add a task to the potluck — buy bread”."
        ),
      },
    },
    {
      object: "block",
      type: "heading_2",
      heading_2: { rich_text: notion.richText("People in charge") },
    },
    {
      object: "block",
      type: "paragraph",
      paragraph: {
        rich_text: notion.richText(
          "Also set the “People in charge” property. Guest list lives in the Guest list property."
        ),
      },
    }
  );

  return blocks;
}

function pageHasMeaningfulBody(blocks) {
  // Require the Documentation section so short intros don't skip the hub template
  return (blocks || []).some((b) => {
    if (b.type !== "heading_2" && b.type !== "heading_1") return false;
    const data = b[b.type] || {};
    const text = (data.rich_text || []).map((t) => t.plain_text || "").join("");
    return /documentation|tasks/i.test(text);
  });
}

module.exports = {
  eventBodyBlocks,
  pageHasMeaningfulBody,
};
