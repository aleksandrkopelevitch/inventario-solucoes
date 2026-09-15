---
paths:
  - "app/Policies/**"
  - "app/Enums/UserRole.php"
  - "app/Http/Middleware/EnsureInventoryAccess.php"
  - "app/Http/Requests/InviteUserRequest.php"
---

### Four roles, and four predicates instead of thirteen comparisons

`App\Enums\UserRole`: `Reader` (Leitor) reads the knowledge base and nothing
else, `Viewer` (Visualizador) reads the inventory, `Writer` (Editor) writes
CONTENT, `Admin` (Administrador) does everything. Every policy decides through
one of four predicates — `canReadInventory()` (everyone but a reader),
`canWrite()` (admin or editor), `canDelete()` (admin) and `isAdmin()` (admin) —
and never by comparing the case. That is the whole point of them: the same rule
used to be written `$user->role === UserRole::Admin` in thirteen files, so adding
a tier meant editing all thirteen and hoping.

**`Reader` is the tier that changed what "logged in" means here.** Until it
existed, holding an account and being able to read the whole inventory were the
same thing: every `viewAny` answered `true`, so the `auth` middleware WAS the
authorization for `/solutions`, `/people`, `/diagrams` and `/flowspec`. Entra
SSO breaks that equivalence — an account stops being something an admin created
one at a time and becomes something anybody with a Leo mailbox gets by visiting
once. `canReadInventory()` is that seam, and it is drawn TWICE on purpose:

- in every `viewAny`/`view`, which is what makes it true — an `authorize()` call
  is supposed to answer correctly on its own;
- once more at the route group (`App\Http\Middleware\EnsureInventoryAccess`,
  aliased `inventory`), which is what makes it complete — a policy per
  controller is a list somebody has to keep adding to, and the entry that gets
  forgotten is a leak nobody sees.

It answers a browser with a REDIRECT to `/docs` and a JSON caller with 403: for
this audience it is not a refusal at all, it is somebody landing one screen away
from the only thing they have. `/` and `LoginController::store()` route a reader
to `/docs` directly for the same reason — landing them on `profile.show` works
and is a redirect they can watch happen, on the app's first screen.

Where the line falls, and why:

- **A reader reads `/docs` and nothing else.** Not the catalog, not a person's
  record, not a caderno that was never published.
- **An editor creates and edits**: solutions, people, companies, cadernos and
  their pages, diagrams, the flowSpec corpus.
- **DELETING is the admin's**, everywhere except a page (a page delete is part
  of writing a tree, and goes through `update` on its caderno). A caderno delete
  takes its whole page tree; a diagram delete leaves prose citing it. Move
  `canDelete()` if that call should change — it is one seam.
- **What is not content stays with the admin**: inviting accounts
  (`UserPolicy::manage`), the attribute vocabulary (`AttributeOptionPolicy`,
  deliberately NARROWER than `SolutionPolicy` — a category invented while
  filling one form is a category every other form then offers), publishing a
  caderno — to the magic link OR to `/docs` — and its secret code
  (`NotebookPolicy::administer`, plus `administerAny` for the settings screen,
  which asks the same question about the COLLECTION).
- **`SubmissionPolicy` is untouched** and stays owner-based (admin OR the person
  who created it): a CATI submission is authored, not curated.

**A role is changed on the Usuários panel** (`PATCH users/{user}`, the badge is
an `x-ui.inline-edit` select). Before that it could only be chosen at INVITE
time, which left promoting a viewer to editor — and taking the admin off
somebody who left — as an `UPDATE` against the production database.

**Nobody changes their own role**, and `UserList` withholds the select on your
own row so the refusal is a missing affordance rather than an error you discover
by pressing it (the request stays the authority). **There is deliberately no
"last admin" guard**: demoting an admin requires an admin asking about somebody
ELSE, so two exist and one always survives. Nothing else in the app writes
`role`, and an invite only ever ADDS an admin. DELETING an account is still
database-only — that means sessions, plus the submissions and chats it owns.

Two things that broke when the role landed and would break again:

- **`InviteUserRequest` listed its roles by hand** (`Rule::in([Viewer, Admin])`,
  a leftover from the removed `agent` case), so a new case is refused by
  validation while every policy already honours it. It is `Rule::enum` now.
- **Anything gated on `update` that is really about ADMINISTERING** silently
  widened the day `update` stopped meaning "admin" — the share dropdown was
  exactly that, and it now carries the secret code as well.
