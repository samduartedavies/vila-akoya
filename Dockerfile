FROM node:20-alpine

WORKDIR /app

# Bot has zero runtime npm deps; package.json kept for repo consistency.
COPY package.json ./
COPY scripts/ ./scripts/

# Persistent volume mount point (state, docs index, Notion catalog).
ENV STATE_FILE=/data/.vila-akoya-telegram-state.json
ENV LOCK_FILE=/data/.vila-akoya-telegram-state.json.lock
ENV DOCS_INDEX_FILE=/data/.vila-akoya-docs-index.json
ENV NOTION_CATALOG_FILE=/data/.vila-akoya-notion.json

CMD ["node", "scripts/vila-akoya-telegram-bot.js"]
