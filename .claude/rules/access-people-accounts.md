---
paths:
  - "app/Models/User.php"
  - "app/Models/Person.php"
  - "app/Actions/GrantPersonAccess.php"
  - "app/Http/Controllers/UserController.php"
  - "app/Http/Controllers/Auth/AccessLinkController.php"
  - "app/Http/Requests/GrantPersonAccessRequest.php"
  - "app/Http/Requests/*PersonAccount*.php"
  - "app/Policies/UserPolicy.php"
  - "app/Policies/PersonPolicy.php"
  - "resources/views/people/**"
---

### Access is an attribute of a PERSON, and an account can still have none

`people.user_id` (nullable, unique) links a `Person` to the account they log in
with. Both directions stay optional, and that is the whole shape of this:

- **Most people never log in.** They are vendor contacts — 105 of the 108 rows
  in dev have no email at all — so a person without an account is the ordinary
  state, said plainly on the card rather than hidden.
- **An account without a Person is normal too**, which is why the roster at
  `/people/accounts` still exists after access management moved onto each
  person's page. `admin@leomadeiras.com.br` comes from `DatabaseSeeder` and never
  will have a catalog row; a screen listing only "people who have accounts"
  would leave the one account that cannot be locked out with no screen at all.
  It is also the only place an ORPHAN account's ROLE can be changed.
- **But an orphan is no longer MANUFACTURED.** `UserController::store()` — the
  invite — used to create a `User` and never a `Person`, which made it the app's
  orphan factory and left "vincular uma conta que já existe" a routine step
  rather than a repair. It goes through `GrantPersonAccess::invite()` now: the
  account AND the catalog row, linked, reusing the person already filed under
  that e-mail (`Person::withEmail()`, folded EQUALITY) instead of duplicating
  them. What is listed without a person is the seeder's admin and rows older than
  2026-09-01.

`UserController` kept the two endpoints that really are about the account
(`store` = invite somebody NOT in the catalog, `update` = the role, shared by
both screens); its own screen is gone.

**The two tables are NOT merged, and an account is not a small person.** Asked
directly (2026-09-01), the answer is no, for two reasons specific to this app
rather than to Laravel. First, **105 of 108 catalog rows have no e-mail at all**:
merging puts vendor contacts into the authentication table, makes
`email`/`password` nullable so 97% of rows can hold neither, and turns "delete a
contact" into "delete an identity that authored submissions" (nine FK columns
point at `users`). Second, **the authority split falls exactly on the table
boundary** — `people` is writable by an EDITOR, an account only by an admin — so
one merged row would be written by two authority levels, with `role`, `email`
and `password` guarded by field lists instead of by a policy.

What WAS wrong was the presentation, fixed at the source: an account is rendered
as a CREDENTIAL everywhere it is offered (the orphan picker's options are
`e-mail · perfil`, the roster's rows lead with the e-mail), because leading with
`users.name` made the gesture read as linking a person to another person.
`users.name` survives for the one thing it is: how the app greets whoever is
signed in.

**`UserPolicy::manage`, never `PersonPolicy::update`.** This is the trap the
whole feature turns on: an EDITOR may rewrite a person's job title, company and
system links, and must not be able to hand out an account — least of all an admin
one. The two live on the same page and answer to different rules, which is why
`GrantPersonAccessRequest`/`LinkPersonAccountRequest` exist instead of reusing the
person's own authorization. `user_id` is deliberately absent from `Person`'s
`$fillable` for the same reason: granting access must not be reachable by posting
a field to the edit panel an editor CAN reach.

**The access link leads to the password screen and never to a session.** The
obvious implementation authenticates the holder and drops them inside the app,
and that is exactly what `AccessLinkController` must not do — a URL forwarded in
a Teams thread would then BE the account. Its whole privilege is "you may set
this account's password"; the person then logs in like anybody else, one screen
further, and ends up with a credential of their own instead of a link they have
to keep.

Four things that make the link's generosity safe:

- **It is spent the moment the password is set.** `ClearAccessTokenAfterPasswordReset`
  listens to `PasswordReset` (auto-discovered, and already fired by
  `ResetPasswordController`), so the real lifetime is "until it works, and at
  most `User::ACCESS_TOKEN_DAYS`". Left alive it would be a seven-day
  password-reset link for a live account. The ordinary "esqueci minha senha"
  flow fires the same event, so a person who resets by email also invalidates a
  link they were sent — neither path has to remember to.
- **Each open mints a FRESH Laravel reset token.** That is what lets the access
  link be reusable for days while the thing it hands over stays short-lived
  (`config('auth.passwords.users.expire')`, 60 minutes).
- **Generating a new link replaces the old one** — `unique` on the column would
  refuse a duplicate anyway, and it is the only way to kill a link that went to
  the wrong person.
- **A dead link answers with ONE message** for "never existed", "already used"
  and "expired". Telling them apart tells a stranger holding a dead URL whether
  the account behind it is real.

**Revoking answers from BOTH screens, and the roster is the one that matters.**
`GrantPersonAccess::revoke(Person)` delegates to `revokeAccount(User)` so the two
behave alike — and the second exists because an account does not need a Person.
Revoking lived only on a person's Acesso card at first, which left an orphan with
its ROLE changeable on the roster and no way to be switched off anywhere at all.
The refusal is the same one the role carries — nobody revokes their own account
— which is also what keeps at least one panel-holder able to log in (see
`.claude/rules/roles-and-policies.md`).

**Revoking soft-deletes the account and unlinks it.** That stops the person
logging in (Laravel's user provider applies the default scope, so an existing
session stops resolving) while their submissions and chats keep pointing at a row
that exists. Granting again RESTORES the same row rather than creating a second
account beside it — `GrantPersonAccess::grant()` looks with `withTrashed()`,
which is also what keeps the unique index on `email` from refusing the insert.
Erasing an account for real is still database-only.

**UNLINKING is not revoking, and the card offers both.** `link()` is a statement
about identity — "the account that logs in as this e-mail is this catalog row" —
so its inverse has to be a statement too: `unlink(Person)` disassociates and
stops, leaving the account's role, password and access link exactly as they were,
back on the roster as an orphan. While "Remover acesso" was the only apparent
opposite of "Vincular uma conta que já existe", undoing a mistaken link
soft-deleted the account it named — which is how the seeded admin got locked out
of the app (reported 2026-09-01).

Two things that go with it:

- **Both confirms name the ACCOUNT, not just the person.** Which e-mail is about
  to stop working was exactly what the screen did not say, and it is the one fact
  that separates the two gestures at the moment of pressing either.
- **`people.access.unlink` is `/access/{user}/unlink`, not a DELETE on
  `/access/{user}/link`.** That pair is already the magic LINK's
  (`refreshLink`/`destroyLink`) — the two senses of "link" collide in this
  feature, and a route name is the wrong place to be clever about it. It answers
  to `UserPolicy::manage` like every other verb here, and goes through
  `accountOf()`, so a person/account pair that is not actually linked 404s.
