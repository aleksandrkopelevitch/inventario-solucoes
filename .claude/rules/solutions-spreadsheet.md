---
paths:
  - "app/Services/SolutionSpreadsheetService.php"
  - "app/Actions/ExportSolutionSpreadsheet.php"
  - "app/Http/Controllers/Inventory/SolutionSpreadsheetController.php"
  - "app/Http/Controllers/PublicSolutionSpreadsheetController.php"
  - "app/Http/Requests/ExportSolutionSpreadsheetRequest.php"
  - "app/Models/PublicLink.php"
  - "app/Enums/SpreadsheetAudience.php"
  - "app/View/Components/Solutions/SpreadsheetSharePanel.php"
  - "resources/views/components/solutions/spreadsheet*.blade.php"
  - "resources/views/solutions/spreadsheet.blade.php"
  - "resources/views/public/solutions-spreadsheet.blade.php"
  - "resources/views/components/layouts/public-sheet.blade.php"
  - "resources/js/modules/solutions-sheet.js"
---

### The solutions spreadsheet: one dataset, two audiences, a file of what is on screen

`/solutions/spreadsheet` (reached from the catalog's "Ver como planilha") is the
whole catalog as a read-only grid — attributes, owners by role, cadernos — with
hideable columns, Excel-style value filters and an `.xlsx`/`.csv` export. Its
magic link is `/public-solutions/{token}`.

- **`SolutionSpreadsheetService` is the only place a column exists.** The
  inventory screen, the magic link and the export all read it, so a column
  cannot be on screen and missing from the file. Add a column there and the
  grid, the column picker and the export all pick it up.
- **Filtering, sorting and hiding happen in the browser** (`solutions-sheet.js`)
  over the JSON the page embeds — the catalog is ~100 rows. The export does NOT
  reimplement the filters: the page sends the visible `columns[]` and the
  on-screen `ids[]` in display order, and the server builds exactly that. A
  second implementation of the filters in PHP is the thing to avoid.
- **The audience decides what a column may carry** (`SpreadsheetAudience`).
  `Shared` drops the `contacts` column (people's e-mails and phones) from the
  definitions themselves — not merely hidden, since a hidden column is still in
  the page source and one click from the export — and its names do not link
  into the inventory. `ExportSolutionSpreadsheet` re-narrows any requested
  column list to the audience, so asking the public export for `contacts` gets
  nothing.
- **The magic link is the same mechanism as a caderno's**: an opaque token in
  the URL is the whole authorization, generated and revoked by an admin
  (`SolutionPolicy::share`) from a share panel driven by `docs-share.js`. The
  token lives in `public_links` (one row per `subject`), because the
  spreadsheet has no record of its own to hang it off. Revoking deletes the row.
- **`spreadsheet` is a reserved solution slug** (`Solution::RESERVED_SLUGS`,
  applied when a slug is generated AND refused when one is posted), because
  `solutions/spreadsheet` sits where `solutions/{solution}` does.
- **What localStorage remembers** (per audience): the column layout and the
  sort. Not the filters or the search — a sheet that reopens already narrowed
  reads as missing rows.
