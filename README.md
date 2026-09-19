# OA7 Chapter Meetings

A lightweight WordPress plugin that shows each lodge chapter's upcoming meeting(s)
on its page, pulled live from a shared **public Google Calendar**.

It does **not** replace any existing calendar iframe — it adds a small, separate
per-chapter "upcoming meeting(s)" widget you place with a shortcode. Everything
calendar-, lodge-, and chapter-specific is configured in settings; nothing is
hardcoded, so the plugin works against any public Google Calendar.

---

## Install

1. Zip the `oa7-chapter-meetings/` folder (so the zip contains
   `oa7-chapter-meetings/oa7-chapter-meetings.php` at its top level).
2. WordPress Admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install** → **Activate**.
3. Go to **Settings → OA7 Chapter Meetings** to configure.

### Updating an installed copy

Upload the new zip the same way. WordPress detects that the plugin already exists
and shows a "current vs. uploaded" comparison with a **Replace current with
uploaded** button — take that. There is no need to deactivate or delete first.

Settings, the cached calendar payload, and match history live in the WordPress
options table, not in the plugin folder, so they are untouched by a replace. The
plugin registers no uninstall hook, so even a full delete leaves them in place.

Bump `Version:` in the plugin header (and the matching `OA7CM_VERSION` constant)
for each update — WordPress uses it to recognize the upload as an update, and the
constant is the cache-buster on the admin CSS/JS.

Activation schedules an automatic refresh every 6 hours via WP-Cron. You can also
refresh on demand with the **Refresh Cache Now** button.

---

## Setup

### 1. Get a Google Calendar API key

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a project (or select an existing one).
3. **APIs & Services → Library** → search **"Google Calendar API"** → **Enable**.
4. **APIs & Services → Credentials → Create Credentials → API key**.
5. Copy the key. (Recommended: click the key, restrict it to the **Google Calendar API**,
   and add an HTTP-referrer or IP restriction.)
6. Paste it into **Google Calendar API key** on the settings page.

The calendar must be **public** ("anyone with the link can view") for an API key to read it.

### 2. Find the Calendar ID

1. In [Google Calendar](https://calendar.google.com/), hover the calendar in the left
   sidebar → **⋮ → Settings and sharing**.
2. Under **Access permissions for events**, enable **"Make available to public"**.
3. Scroll to **Integrate calendar** → copy the **Calendar ID**
   (often looks like `xxxxxxxx@group.calendar.google.com`, or your Gmail address for a
   personal calendar).
4. Paste it into **Calendar ID**.

> The Calendar ID is **not** the same as the embed iframe URL. Use the Calendar ID from
> the Integrate calendar section, not anything from an embed snippet.

### 3. Set the timezone

Enter an IANA timezone string in **Timezone**, e.g. `America/Chicago`. This is used for
all date/time display **and** for deciding which meetings are still upcoming.

### 4. Add your first chapter

In the **Chapters** table:

- **Chapter name** — the display label you'll reference in the shortcode, e.g. `Portage Creek`.
- **Title regex** — a pattern matched against the **event title only**. Examples:
  - `Portage Creek` — matches any title containing "Portage Creek".
  - `^Portage Creek` — matches titles that start with "Portage Creek".

Use **+ Add chapter** for more rows; **Remove** to delete one. **Save Changes**, then click
**Refresh Cache Now**. The chapter's **Last matched event** date should populate — that
confirms the regex actually matches real events on the calendar.

---

## Shortcode

```
[oa7_chapter_meetings chapter="Portage Creek" count="3"]
```

- `chapter` *(required)* — must exactly match a configured chapter name.
- `count` *(optional, default 3)* — number of upcoming meetings to show.
  - `count="1"` renders a single-meeting block.
  - When fewer upcoming meetings exist than `count`, an "All upcoming meetings shown" note is added.
- `color` *(optional)* — overrides the left-edge accent color for this widget only,
  e.g. `color="#b32d2e"`. Accepts hex, `rgb()/rgba()`, or a CSS color name. When omitted,
  the global **Accent color** from the settings page is used.

If a chapter has no upcoming matching events, it shows:

> No upcoming meetings found - please check calendar on oa7.org homepage

Each meeting row shows the **date, time, and address** (the event's native Location field)
together as a compact block.

### Styling

Default CSS ships with the plugin. Override via your theme or Elementor custom CSS using
these classes: `.oa7cm`, `.oa7cm--single`, `.oa7cm-heading`, `.oa7cm-list`, `.oa7cm-row`,
`.oa7cm-date`, `.oa7cm-time`, `.oa7cm-location`, `.oa7cm-empty`.

---

## How it stays current

- Events from **now forward 365 days** are fetched and cached for **6 hours** (WordPress
  transient), refreshed automatically by WP-Cron and on demand by the button. The window
  is forward-only — past events are never fetched, so nothing that has already happened
  can appear in the widget.
- Recurring monthly meetings are expanded to individual instances, so "next meeting"
  always rolls forward on its own — no manual date upkeep.
- If a fetch fails (bad key, quota, network), the last good data keeps serving and the
  error is recorded.

## Admin health checks

- **Last matched event** per chapter, with a per-chapter ⚠ warning if a regex has matched
  nothing for longer than the configurable **stale-match threshold** (default 45 days) —
  this usually means the regex no longer matches actual event titles.
- A separate **feed-wide warning** appears if the cache hasn't refreshed successfully in
  over 24 hours (the whole API connection should be checked).

Both are admin-only and never change the public widget output.
