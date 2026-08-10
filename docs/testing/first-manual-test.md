# First Manual Test — Library & EPUB Ingestion

Audience: product owner. Scope: the first milestone only (upload →
approval → processing → structured book). Search, Ask Books, chat and
Deep Analysis are **future milestones**: their pages say so and are not
part of this test.

Base URL: **https://mnemosyne.shellrent.com**

Use 2–5 EPUBs you own. Nothing you upload enters Git; originals are
stored privately on the server.

## Login

1. One-time setup (server terminal, if not done yet):
   `docker compose exec app php artisan mnemosyne:user:create-admin`
   — it asks name, email and password interactively.
2. Go to `/login` and sign in. You land on the Dashboard.
3. Expected: no self-registration page exists (`/register` is a 404);
   the sidebar shows Library / Search / Analyses plus, for admins, an
   Administration section (Processing, Submissions, Library admin,
   Users, System).

## Test 1 — A normal EPUB, end to end

1. Go to **Library** (`/library`) → button **Submit EPUB**
   (`/library/submissions/create`).
2. Choose an `.epub`, optionally add a note, submit.
3. You are redirected to the submission page. Expected status:
   **Pending approval** (auto-approval is OFF by default).
4. As admin, open **Admin → Submissions** (`/admin/submissions`):
   the file is listed as pending. Press **Approve**.
5. Back on the submission page (or Admin → Processing → the run), watch
   the real stage progress: `hash → validate → parse → normalize →
   structure`. The page polls by itself.
6. Expected final status: **Ready for enrichment** (or **Ready (with
   warnings)** for imperfect but recoverable files). "Ready for
   enrichment" means *structurally understood*, not yet searchable —
   that is the next milestone.
7. Inspect the result:
   * submission page: title, authors and their roles, language, EPUB
     version, structure numbers (spine items, sections, nodes);
   * Admin → Processing → run detail: stage attempts with durations,
     event timeline, extracted metadata;
   * Admin → Library admin (`/admin/library`): navigate Work → Edition
     → Asset created for your book.
8. Press **Download original** on the book: the exact file you uploaded
   comes back (same bytes).

## Test 2 — Exact duplicate

1. Upload the *same* file again and approve it.
2. Expected: it completes almost immediately with a "duplicate" notice;
   no second copy is stored and no re-processing happens (run detail
   shows only the `hash` stage). The library still shows one book.

## Test 3 — A different/related EPUB

1. Upload a second, different book (another edition or another work).
2. Expected: a new book with its own metadata. In Admin → Library admin
   check how it was filed: same Work only when the evidence is strong,
   otherwise a separate provisional Work. If two files contain the same
   text (e.g. same book, different cover), run detail shows a
   **Possible duplicates** signal for you to judge — never an automatic
   merge.

## Test 4 — Pause / resume

1. Upload a book, approve it, and quickly open Admin → Processing →
   the running run.
2. Press **Pause**: the current stage finishes, nothing further starts,
   status becomes **Paused**. Press **Resume**: it continues from where
   it stopped (no repeated stages in the attempts list).
3. On the Processing dashboard try **Pause ingestion** (global): a
   banner appears and new work stays queued; **Resume ingestion**
   releases it.

## Test 5 — A problematic EPUB (optional)

If you have a broken/DRM/odd file:

* invalid or corrupted file → run ends **Failed** with an error code;
* DRM-protected content → **Needs review**, and the issue explicitly
  says it cannot be overridden (there is deliberately no Force button
  for security blocks);
* fixable oddities (broken navigation, missing pieces) → **Needs
  review** with an **Override** button, or completes as **Ready (with
  warnings)**;
* for a hopeless file, **Mark unsupported** archives it; uploading a
  corrected file later is a brand-new submission.

## What to record for every problem

* page URL and what you clicked;
* what you expected vs what happened;
* the id shown in the page/URL (submission/run/book code);
* the exact error text;
* a screenshot if useful.

## Not part of this test

Search, Analyses, Ask Books/chat, Deep Analysis, reader view,
summaries: their pages exist only as labeled placeholders. Bulk import
of the full library is intentionally not enabled yet.
