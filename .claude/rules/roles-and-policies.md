---
paths:
  - "app/Policies/**"
  - "app/Enums/UserRole.php"
  - "app/Enums/AccessModule.php"
  - "app/Enums/AccessLevel.php"
  - "app/Models/User.php"
  - "app/Http/Requests/InviteUserRequest.php"
  - "app/Http/Requests/UpdateUserRoleRequest.php"
  - "app/Http/Requests/UpdateUserAccessRequest.php"
  - "resources/views/components/people/access-levels.blade.php"
---

### A role (member/admin) plus a level per module

`App\Enums\UserRole` is only `Member` (Usuário) or `Admin` (Administrador).
What a member may do is a LEVEL PER MODULE — `App\Enums\AccessModule` ×
`App\Enums\AccessLevel`, stored as JSON on `users.access`
(`{"catalog": "editor"}`):

| module | covers | policies |
|---|---|---|
| `Catalog` | solutions, people, companies | `SolutionPolicy`, `PersonPolicy`, `CompanyPolicy` |
| `Documentation` | cadernos, pages, diagrams | `NotebookPolicy`, `DocumentationPagePolicy`, `DiagramPolicy` |
| `Integrations` | the Especialista em Integrações and its corpus | `FlowspecChatPolicy`, `FlowspecExamplePolicy`, `FlowspecGuidelinePolicy` |
| `Committee` | the Comitê de Arquitetura | `SubmissionPolicy` (+ `SubmissionDiagramPolicy`, which delegates) |

- **Three levels per module: `Nenhum` (None), `Leitor` (Reader), `Editor`.**
  None means the module does not exist for the account: no screen, no rail
  entry, no card on another module's screen, no endpoint, no MCP tool — a
  person who reads the documentation need not see the catalog at all. Reader
  reads, filters and exports; Editor creates and changes.
- **The default is per module — `AccessModule::defaultLevel()`**: Reader of
  the catalog and the documentation, None in the Especialista and the Comitê
  (the user's call, 2026-10-06). Every new account (invited, granted, or
  provisioned by Entra SSO) starts there; an admin opens or closes modules from
  that point. The default is stored as an absent key, so `access` only records
  what differs from it. Changing a default changes every account never set in
  that module — which is why the migration wrote all four modules down
  explicitly for every pre-existing account instead of leaving them to it.
- **Policies ask `canView()` / `canEdit()`** — every `viewAny`/`view` is
  `$user->canView(AccessModule::X)`. There is NO route-group gate any more
  (`EnsureInventoryAccess` was removed), so the policies are the whole wall.
  `tests/Feature/ModuleAccessCrawlTest.php` is what keeps it whole: it walks
  every authenticated GET route, demands each be mapped to a module or to the
  short open list, and asserts every module route refuses an account that is
  None there. A new route fails that test until somebody decides its module.
- **Open to every account, whatever its levels**: the home (its cards filter by
  module), the ecosystem map (by decision — it belongs to no module), `/docs`
  (published cadernos), `/mcp/connect`, the profile.
- **Where one module shows another's records, it asks**: the solution page's
  diagrams/cadernos card and the committee warning, the spreadsheet's
  documentation columns (`SolutionSpreadsheetService::DOCUMENTATION_COLUMNS`,
  dropped — not hidden), and the Especialista's documentation picker/search/
  attachments all check the other module's `viewAny`. Names of related
  records inside a screen (a caderno's "contempla os sistemas") still show;
  their links answer 403.
- **`Editor` adds creating and changing inside that module only.** Policies ask
  `$user->canEdit(AccessModule::X)`; never compare `access` by hand.
  `User::accessLevel()` answers Editor for an admin, so no policy ORs the two.
- **Two modules stay AUTHORED rather than curated**, on top of the level: a
  submission is changed by its creator (while a Committee Editor) or an admin,
  and a flowSpec conversation is continued (and its pipeline run) by its owner
  (while an Integrations Editor) or an admin. Everybody else reads them — a
  Reader of the Especialista reads ALL conversations, which is why the rail
  lists every chat with its author. Anything that takes a chat from untrusted
  input to act on it (the attachment picker's `?chat=`) asks `update`, not
  `view`, now that `view` is everybody.
- **DELETING any record is the admin's** — solutions, cadernos, PAGES (and
  their subtree; this used to ride on the caderno's `update`), diagrams,
  submissions, the flowSpec corpus. Changing a record's contents (a diagram's
  blocks, a person's contacts, links between records, a submission's sources)
  is editing and belongs to the module's Editor.
- **What is not content stays with the admin**: accounts, the role and the
  module levels, linking a person to an account (`UserPolicy::manage`); the
  attribute vocabulary (`AttributeOptionPolicy`); publishing a caderno and its
  secret code (`NotebookPolicy::administer`/`administerAny`); the spreadsheet's
  magic link (`SolutionPolicy::share`); the MCP token screen (`McpTokenPolicy`).
  `/mcp/connect` stays open to every account by decision — a person connects
  their own chat client.

**Levels are set on two screens**, both `x-people.access-levels` (four
inline-edit selects, one per module) posting to `users.access.update` with
the module as a TOP-LEVEL key (`{"catalog": "none"}` — the inline editor posts
JSON keyed by field name, where `access[catalog]` would be a literal key): the
accounts roster (`/people/accounts`) and a person's Acesso card. The response
carries both slots. An admin's row shows one line instead of four selects —
`UpdateUserAccessRequest` refuses levels on an admin, since they would be a
lie the screen then has to explain.

**Nobody changes their own role or levels**, and the roster withholds the
controls on your own row (the request stays the authority for the role). There
is deliberately no "last admin" guard: demoting an admin requires an admin
asking about somebody ELSE, so two exist and one always survives.

The migration that introduced this (`add_module_access_to_users_table`) turned
every `writer` into an Editor of all four modules, every `viewer` into a plain
member (default everywhere) and every `reader` into None in all four, so
nobody gained or lost anything (`ModuleAccessMigrationTest`).
