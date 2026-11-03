# Bio-Notifier design guide

Status: editable working draft · Updated: 3 November 2026

User direction: mobile first, amber primary, compact controls, progressive detail, clear actions and lightweight interactions. These rules guide existing and future employee, attendance, payroll and device screens. This document defines design guidance; it does not claim that every screen already implements it. Follow [project conventions](agent.md) when applying changes.

## Design foundation

Use Filament 4 components and CLI-generated resources/pages as the starting point. Preserve its spacing, focus states, typography, form behaviour and accessible dialogs. Use a small shared set of component defaults and theme tokens rather than per-page overrides or a second UI framework. Use Alpine.js for local browser state and Livewire for server work.

Set the panel's semantic primary color to Filament's `Color::Amber` through its `colors()` configuration. Do not scatter custom amber hex values through templates. Primary is the brand/action color; success, warning, danger and informational states keep their distinct semantics. Use neutral surfaces and restrained amber emphasis, not amber backgrounds everywhere. Validate contrast in light and dark themes; color alone never communicates state. The master and tenant panel providers already use Amber. Keep the PWA theme color aligned with the existing amber `#f59e0b`. See [Filament colors](https://filamentphp.com/docs/4.x/styling/colors).

## Mobile first, with content-sized layouts

Design the smallest usable layout first, then add useful detail as space grows. A mobile layout is not automatically one large card per row. Compact summaries, quick links and short fields may share a row when their content, labels and touch targets fit. Long records or forms may need one column. Do not force two columns just to meet a visual pattern.

Use wrapping flex/grid with a minimum usable width per component and a maximum width where useful. A provisional minimum of 9–11rem suits short summary tiles; content, translation and text zoom determine the real minimum. Fall back to one column before content becomes squeezed. For custom grids, a pattern such as `repeat(auto-fit, minmax(min(100%, 10rem), 1fr))` is a starting point for short tiles, not a global card rule. Cap the parent width or item growth when short content would otherwise stretch excessively.

Use `min-width: 0` on shrinkable content, wrapping for labels, and explicit overflow handling for long values. Keep critical amounts, statuses and action triggers visible. Do not cut a card in half, hide overflow to conceal a layout error, or depend on horizontal page scrolling. Use bounded inner scrolling only for content that truly needs it; mobile record lists should not require sideways scrolling.

Respect the viewport, browser bars, iOS safe areas, landscape and the on-screen keyboard. Sticky headers/footers must not cover the last field, error message or pagination. Preserve browser zoom and text resizing. Aim for usable reflow at 320 CSS px and 200% text zoom without clipped controls.

## Consistent components and readable content

Use shared Filament component defaults and theme tokens for spacing, typography, borders, surfaces, focus and disabled states. Apply a shared fix where the same pattern appears across employees, attendance, payroll and devices. Avoid page-specific CSS overrides and competing component styles. Support both light and dark themes.

Use short, complete labels and brief help text. Keep names, dates, currencies and durations readable and consistent with the organisation settings and existing business rules. Preserve entered names and notes in their original script. Allow long names and translated labels to wrap without clipping or shrinking text. Do not introduce a new localization policy through a visual change.

## PWA and installed mobile experience

Maintain one responsive application for browser and installed use. Build on the existing manifest, service worker and Filament panel integration; do not add a separate mobile interface or another PWA framework. Keep app name, icons, amber theme color and standalone presentation consistent. Launch and navigation must preserve the correct tenant or master panel and respect authentication and role permissions.

Keep Install in the existing user menu. Show it only when installation is available; avoid repeated prompts or an unusable button in an already installed app. Where the browser needs manual installation, provide short, platform-appropriate guidance only when relevant.

Keep update notices small and clear, with an explicit Update action. Do not reload an active form or discard unsaved work automatically. Preserve safe navigation and recovery after an update, session expiry or interrupted connection.

Cache public static assets selectively. Employee, bank, payroll, attendance and device data must not be available from shared offline caches or cross tenant/account boundaries. Offline screens should explain that a connection is needed and offer Retry; never show stale figures as current, pretend a save succeeded or silently queue sensitive changes. Installation does not make business operations available offline.

Apply the same safe-area, keyboard, scrolling, touch-target and responsive rules in standalone mode as in the browser. Keep navigation, dialogs, notifications and primary actions reachable without browser controls. Verify install, update and offline behaviour separately when live testing is explicitly requested.

## Forms and dialogs

Choose width by task and expected content. Short amounts, dates, counts, statuses and compact dropdowns should not stretch across a desktop page. Names and phone numbers need readable widths; addresses and notes may need a wider line. Fields may fill their own bounded container on a narrow screen. Compact means removing wasted space, not shrinking text or touch targets.

Group related fields in responsive columns; stack when labels or values stop fitting. Give each field a persistent label. Keep help text brief and errors close to the field; placeholders are not labels. Do not truncate a selected value with no way to read it. Long entity lists need server search and bounded results, not thousands of preloaded dropdown options.

Use the smallest modal that comfortably fits the task. Do not make every dialog full-screen or every input full-width on desktop. On narrow screens, let a dialog use the available width with safe margins. Ensure the title, close control, fields and footer are reachable with keyboard, scrolling and text zoom. Avoid nested scroll traps. A long form may need a dedicated page rather than an oversized modal. Use one consistent form for the same task, such as adding or correcting attendance, rather than separate competing dialogs.

## Lists, cards and progressive detail

| Surface | Show immediately | Open on demand |
| --- | --- | --- |
| Mobile records | A compact table with headers, essential columns and an overflow action. | Full record, history, long notes, attachments and related entries. |
| Desktop tables | Identity and the few operational columns needed for scanning, sorting and comparison. | Secondary columns through column controls; deeper data on the record page. |
| Dashboard | Small useful totals, clear period and a link to the underlying list. | Charts, breakdowns and full reports when requested. |

Use one consistent table presentation across mobile and desktop, with visible column headers and aligned rows. On mobile, select only the columns needed to identify and compare records; show additional useful columns on desktop. Do not turn each row into a long stack of labels and values or load the full desktop table onto a narrow screen. Keep the table within the viewport without clipping the final column or its action menu.

Size columns according to their contents rather than distributing empty space equally. Short IDs, dates and counts should stay compact; allow names enough room to wrap when needed. Apply spacing and responsive behaviour through shared table styles or components so fixes benefit all lists. Preserve readable text, usable action targets and headers that remain aligned with their values.

Choose essential mobile columns by task: employee identity and status, attendance date and worked time, or payslip period and net pay. Keep bank details, extended settings and history on authorized detail views. Branch, department and task-group IDs are read-only identifiers, not manual entry fields. Wide monthly attendance matrices may use clearly indicated horizontal scrolling inside the report; keep employee identity visible where practical and avoid horizontal page scrolling.

Do not fetch heavy relationships just because their columns are hidden with CSS. Use shared authorization/query boundaries with a small list projection, then load detail after navigation. Prefer Filament responsive layouts and reusable schemas; avoid duplicating business logic in separate mobile/desktop implementations. [Filament table layouts](https://filamentphp.com/docs/4.x/tables/layout) support responsive composition.

Make the record title a clear, keyboard-accessible link to its authorized read-only detail view; offer an Edit shortcut when permitted. Opening a record never saves it. Keep navigation clicks separate from the three-dot menu, selection boxes and other controls. Return to the previous filters, page and scroll position after editing where practical.

## Pagination and data loading

Every growing list uses server-side pagination: mobile summaries, desktop tables, related records, searches and dropdown results. Never fetch all rows and paginate in Alpine. Never use viewport hiding as a data-loading strategy. Paginate before hydration/rendering and eager-load only the relationships needed for the current page.

Working default: 10 records per page, selectable 10/25/50, maximum 50. These are editable performance defaults, not final product limits. No `All` option or disabled pagination for growing datasets. Enforce limits server-side, including modified Livewire requests. Use the installed Filament 4 pagination APIs; verify available methods before changing shared table configuration.

Keep Previous/Next and the current position visible on mobile; show totals only when accurate and affordable. Use stable sorting with an ID tie-breaker. Reset to a valid page after search/filter changes or deletion. Preserve list state per tenant/resource without leaking previous-tenant data. Revalidate restored state before rendering. If expensive count queries justify simple/cursor pagination, check the installed API and accept losing exact page totals; do not invent a total. Exports are separate bounded/chunked server jobs, not a request for an unlimited UI page. See [Filament pagination](https://filamentphp.com/docs/4.x/tables/overview#pagination).

## Actions, labels and icons

Place row/card secondary actions inside one three-dot overflow menu using Filament `ActionGroup`. Give its trigger an accessible name such as “Actions for Amina”; retain a clear menu label for each action. Keep destructive actions separated and confirmed. Do not crowd a row with several loose icon buttons. See [grouped actions](https://filamentphp.com/docs/4.x/actions/grouping-actions).

Keep the page's primary action visible, such as Add employee or Add attendance, and keep Save/Cancel/Confirm visible in a form. They are intentional exceptions to overflow grouping: the next step must be discoverable, especially on empty pages. Do not place all navigation or a dialog's submit action behind three dots.

Use short complete labels: Add, Save, Edit, Print, Share, Retry, Clear filters. Add the object when context is ambiguous, such as Add employee versus Add attendance. Shorten wording, not meaning; avoid unexplained abbreviations. Use one consistent Heroicon per meaning with visible space between icon and text. Do not reuse the same icon for unrelated actions or stack decorative icons. Icon-only controls require accessible names; tooltips alone do not serve touch users.

Use a project touch-target goal of about 44 × 44 CSS px, including invisible padding where useful. Keep the visible icon compact. Never compress targets just to fit another column. WCAG 2.2 AA sets a 24 × 24 CSS px minimum with exceptions; the larger project target is deliberate. Follow [W3C target-size guidance](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html), keyboard focus, meaningful contrast and focus return after dialogs.

## Complete page states

| State | Guidance and visible action |
| --- | --- |
| No records | Say what belongs here, for example “No employees yet. Add an employee to get started.” Show Add employee when allowed. |
| No search results | Explain that filters found no match; show Clear filters or change-search guidance. |
| Loading | Use bounded placeholders/progress without stale values masquerading as current data. Keep the page structure stable. |
| Request failed | Preserve recoverable input, explain the failure briefly, and offer Retry. Never show a successful save without confirmation. |
| Offline | Explain that current records need a connection; offer Retry. No fake empty list or cached balance. |
| Permission unavailable | Explain the limitation and an appropriate next step, without offering an unauthorized button or exposing restricted data. |

Every page needs a complete title, readable content boundary and useful next step. Avoid blank white workspaces, cropped headers, floating footers and unexplained disabled buttons. Distinguish no data from a fetch error.

## Review and design questions

Before calling a screen ready, check narrow and wide layouts, long names/translated labels, two tiles where they fit, one column where they do not, keyboard/focus, menu placement, empty/error states, pagination and authorized detail navigation. Inspect source by default; run live UI/device checks only when explicitly requested. Do not claim visual/device validation from source inspection alone.

If a material design choice needs user input, ask one focused question with the affected screen, decision, recommended option and tradeoff. Record unresolved choices below rather than guessing or building a large alternative. Use an external issue only when the user asks to create it; the local register is enough for now.

| ID | Decision | Status / next step |
| --- | --- | --- |
| D-01 | Amber primary, shared Filament 4 components, compact mobile-first UI and Alpine for local state | Design direction; apply consistently as screens change. |
| D-02 | Browser and installed PWA use the same layouts and permission boundaries | Preserve the existing PWA integration; live mobile validation remains separate. |
| D-03 | 10-row default, 10/25/50 options, content-dependent tile widths | Working defaults; verify installed APIs and representative data before applying. |
| D-04 | Essential mobile columns with details on demand | Select columns by employee, attendance, payroll or device task; preserve readable report matrices. |
| D-05 | Supported devices/browser versions and additional UI languages | Open; do not claim compatibility or language support without verification. |
