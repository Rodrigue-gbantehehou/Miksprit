<!-- BEGIN:nextjs-agent-rules -->

## This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->

## Règle UI Miksprit

Respecte `../docs/architecture/design-system.md` pour chaque interface. Réutilise les composants shadcn/ui versionnés dans `components/ui/`; ne crée pas de boutons, badges ou cartes spécifiques à une seule page sans étendre d’abord les variantes communes. Garde la palette, la typographie et les états API cohérents. N’ajoute jamais de données de démonstration, de faux graphiques, de dégradés décoratifs ou d’icônes emoji.
